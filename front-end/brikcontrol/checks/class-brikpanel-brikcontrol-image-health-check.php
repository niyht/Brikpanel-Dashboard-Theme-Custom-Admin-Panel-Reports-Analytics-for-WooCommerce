<?php
/**
 * BrikPanel — BrikControl Image Health Check
 *
 * Walks every published / private product (including variations) and looks
 * at the store's product imagery on three axes:
 *  - oversize  : how many product images are larger than 1 MB.
 *  - missing   : how many point to a file that is no longer there.
 *  - modernity : what share of product images are served as WebP / AVIF.
 *
 * Only the first two grade the card (status and score): a large image slows
 * the product page down and a missing one breaks it. The WebP / AVIF share is
 * shown as a grey tip and never changes the status or the score (field test
 * F2: a store of small JPEGs was rated Critical and told to install a plugin
 * whose free version cannot convert). The install suggestion appears only
 * when the share of large images reaches the Warning line by itself (missing
 * files can colour the card too, but an optimizer cannot bring them back),
 * and it talks about compressing them. BrikPanel intentionally does NOT do
 * the work itself.
 *
 * Missing images come in two kinds (missing_reason()): deleted from the Media
 * Library while a product still lists them, or still in the Media Library
 * with the file gone from the server. The card lists the products that use
 * them (3.3.37: the merchant only had a count, and could not find them among
 * a thousand products), and the first kind can be removed from every product
 * with one click and put back with another (run_fix(), run_undo()).
 *
 * Filters on the percentages:
 *  - brikpanel_brikcontrol_image_warning_oversized_pct / _critical_oversized_pct
 *    set the Warning and Critical lines for the oversized share.
 *  - brikpanel_brikcontrol_image_warning_modern_pct is informational: below
 *    this WebP / AVIF share the card shows the tip. It grades nothing.
 *  - brikpanel_brikcontrol_image_critical_modern_pct is informational too: it
 *    is still read and stored in `thresholds` for code that uses it, and
 *    grades nothing.
 * Any missing file makes the card at least Warning, and Critical from 5% up.
 *
 * Batching: products → 200 per batch. The cursor counts product offsets, not
 * attachment offsets, because the unique attachment set is computed per
 * product (avoiding cross-batch deduplication state).
 *
 * Storage shape inside `facts` (schema 4; schema 3 had no `missing` block,
 * no `missing_deleted` and counted a file gone from the server only when
 * WordPress had no size on record for it; schema 2 also graded the WebP / AVIF
 * share; results written before 3.3.25 kept the same keys under `metadata`,
 * next to sentences in the scan's language):
 *   {
 *     no_products?: true,
 *     totals: { products, attachments, oversized, webp_avif, legacy, missing_files, missing_deleted },
 *     percentages: { oversized_pct, modern_pct, missing_pct },
 *     thresholds: { oversize_bytes, warn_oversized, crit_oversized, warn_modern, crit_modern },
 *     largest: [ { id, post_id, bytes, size_mb, mime } ... ],
 *     missing: { products, refs, deleted_products, deleted_refs,
 *                rows: [ { p: product, v: variation or 0, w: where, r: reason, n: images, a: [ first image ids ] } ... ] },
 *     plugins: { active: { slug => label }, recommendations: [ { slug, label, search } ... ] }
 *   }
 *
 * Hem basit hem variable ürünler taranır:
 *   - Featured image
 *   - _product_image_gallery (CSV)
 *   - Variation thumbnails (every product that has variations)
 *   - BrikPanel variation galleries, while the shop shows them
 *
 * @package BrikPanel
 * @since   3.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_BrikControl_Image_Health_Check extends Brikpanel_BrikControl_Check {

    const PARTIAL_OPTION = 'brikpanel_brikcontrol_image_partial';
    const DEFAULT_THRESHOLD_BYTES   = 1048576;   // 1 MB
    const DEFAULT_MODERN_THRESHOLD  = 60;        // Not read here; kept for code that uses it. Grades nothing.
    const DEFAULT_WARN_OVERSIZED_PCT = 10;
    const DEFAULT_CRIT_OVERSIZED_PCT = 25;
    const DEFAULT_WARN_MODERN_PCT    = 60;       // Informational: the tip's line.
    const DEFAULT_CRIT_MODERN_PCT    = 20;       // Informational: stored, grades nothing.
    const CRIT_MISSING_PCT           = 5;        // Missing files: Warning from one file, Critical from this share up.
    const LARGEST_KEEP               = 10;

    /**
     * Rows of the card's list of products with missing images. The counts
     * above the list cover every one of them.
     */
    const MISSING_KEEP = 100;

    /**
     * Seconds a cleanup, an undo, or the rescan after them works in one
     * request. A cleanup that runs out continues on the next click; a rescan
     * hands the rest to the scan runner.
     */
    const REQUEST_BUDGET = 8;

    /**
     * Where a cleanup that ran out of time resumes on the next click.
     */
    const FIX_CURSOR_TRANSIENT = 'brikpanel_image_cleanup_cursor';

    /**
     * Restore point of the cleanup: a small index, the changed values in
     * numbered chunks that are never autoloaded.
     */
    const BACKUP_OPTION       = 'brikpanel_image_cleanup_backup';
    const BACKUP_CHUNK_PREFIX = 'brikpanel_image_cleanup_backup_';
    const BACKUP_CHUNK_ROWS   = 1000;

    /**
     * Ceiling so a runaway can never fill wp_options. Reaching it stops the
     * cleanup instead of changing products it could not put back.
     */
    const MAX_BACKUP_ROWS = 50000;

    /**
     * A restore point older than this is dropped at the next cleanup and no
     * longer offered, so "undo" never reaches back months.
     */
    const BACKUP_TTL = 30 * DAY_IN_SECONDS;

    /**
     * The product fields a cleanup may change, by where the image is shown.
     */
    const REF_KEYS = [
        'main'      => '_thumbnail_id',
        'gallery'   => '_product_image_gallery',
        'variation' => '_thumbnail_id',
        'vgallery'  => '_brikpanel_variation_gallery',
    ];

    public function get_id() {
        return 'image_health';
    }

    public function get_label() {
        return __( 'Product image health', 'brikpanel' );
    }

    public function get_category() {
        return 'media';
    }

    public function supports_batching() {
        return true;
    }

    public function get_batch_size() {
        return 200;
    }

    public function get_priority() {
        return 10;
    }

    /**
     * Images deleted from the Media Library can be taken off the products.
     *
     * @return bool
     */
    public function supports_fix() {
        return true;
    }

    /**
     * @return string
     */
    public function get_fix_label() {
        return __( 'Remove deleted images', 'brikpanel' );
    }

    /**
     * Figures only; the card writes the sentences (see bc_present()).
     *
     * 3: status and score come from oversized and missing images only; a
     *    stored schema 2 result still graded the WebP / AVIF share, so it is
     *    rescanned once (Storage::maybe_heal()).
     * 4: the products with missing images are kept for the card's list, and a
     *    file gone from the server counts as missing even when WordPress has
     *    its size on record.
     *
     * @return int
     */
    public function bc_schema() {
        return 4;
    }

    /**
     * Run a single batch slice. The runner passes the current cursor and
     * we own the partial-state option so we can resume where we left off.
     *
     * @param array $state { cursor?: int, total?: int }
     * @return array CheckResult with batch_state populated.
     */
    public function run( array $state = [] ) {
        $started    = microtime( true );
        $cursor     = isset( $state['cursor'] ) ? max( 0, (int) $state['cursor'] ) : 0;
        $batch_size = $this->batch_size();

        // Resume partial state when continuing a multi-batch scan.
        if ( 0 === $cursor ) {
            $partial = $this->start_partial();
            $this->save_partial( $partial );
        } else {
            $partial = $this->load_partial();
        }

        $count = $this->scan_batch( $partial, $cursor, $batch_size );

        if ( 0 === $count && $cursor > 0 ) {
            // Cursor overshoot — finalise.
            return $this->finalise( $partial, $started );
        }

        if ( 0 === $count ) {
            // Empty store — produce a friendly "ok" result and clear partial.
            $this->clear_partial();
            return $this->build_empty_result( $started );
        }

        // Decide if more batches needed.
        if ( $count < $batch_size ) {
            return $this->finalise( $partial, $started );
        }

        $this->save_partial( $partial );

        // Free per-batch memory (keeps long scans flat).
        wp_cache_flush_runtime();

        // bc_present() turns batch_state into "Scanning… 200 / 1,000 products".
        $result = $this->make_result_skeleton();
        $result['status']      = 'unknown';
        $result['scanned_at']  = time();
        $result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
        $result['batch_state'] = [
            'cursor' => $partial['products_scanned'],
            'total'  => $partial['total_products'],
            'done'   => false,
        ];
        return $result;
    }

    /**
     * Products per slice, for the scan and the cleanup alike.
     *
     * @return int
     */
    private function batch_size() {
        return max( 1, (int) apply_filters( 'brikpanel_brikcontrol_image_batch_size', $this->get_batch_size() ) );
    }

    /**
     * Scan one slice of products into $partial. Shared by the batched scan
     * (run()) and the rescan after a cleanup or an undo, which keeps its state
     * in memory (refresh_after_change()).
     *
     * @param array $partial Scan state, changed in place.
     * @param int   $cursor  Products already scanned.
     * @param int   $size    Products per slice.
     * @return int Products read; fewer than $size means this was the last slice.
     */
    private function scan_batch( array &$partial, $cursor, $size ) {
        $product_ids = $this->fetch_product_ids( $cursor, $size );
        if ( empty( $product_ids ) ) {
            return 0;
        }

        $threshold = (int) apply_filters( 'brikpanel_brikcontrol_image_threshold_bytes', self::DEFAULT_THRESHOLD_BYTES );

        // The same gallery image shared between products is weighed once,
        // under the first product that uses it; every product that uses a
        // missing image gets its own row in the card's list.
        $seen =& $partial['seen_attachments'];
        foreach ( $this->collect_refs( $product_ids ) as $pid => $refs ) {
            $missing = [];
            foreach ( $refs as $ref ) {
                $att_id = $ref[0];
                if ( ! isset( $seen[ $att_id ] ) ) {
                    $seen[ $att_id ] = $pid;
                    $this->score_attachment( $att_id, $pid, $threshold, $partial );
                }
                if ( isset( $partial['missing_atts'][ $att_id ] ) ) {
                    $missing[] = [ $ref, $partial['missing_atts'][ $att_id ] ];
                }
            }
            if ( ! empty( $missing ) ) {
                $this->record_missing( $pid, $missing, $partial );
            }
        }

        $partial['products_scanned'] = $cursor + count( $product_ids );

        return count( $product_ids );
    }

    // =========================================================================
    // ATTACHMENT COLLECTION — covers simple + variable products
    // =========================================================================

    /**
     * Every image a slice of products shows, with where it shows it: the
     * featured image, the gallery, each variation's image and, while the shop
     * shows them, BrikPanel's variation galleries.
     *
     * Caches are filled for the whole slice first (product and variation
     * fields, the variation lists, the images), so no product costs a query
     * of its own. Variations are read for every product, not only the
     * "variable" type: a simple product has none, and types such as variable
     * subscriptions keep their images there too.
     *
     * @param int[] $product_ids
     * @return array<int, array<int, array{0:int,1:string,2:int}>> Product id => [ image id, where, variation id or 0 ].
     */
    private function collect_refs( array $product_ids ) {
        update_meta_cache( 'post', $product_ids );
        if ( function_exists( 'brikpanel_prime_variation_ids' ) ) {
            brikpanel_prime_variation_ids( $product_ids );
        }

        $variations = [];
        $all        = [];
        foreach ( $product_ids as $pid ) {
            $variations[ $pid ] = function_exists( 'brikpanel_variation_ids' ) ? array_map( 'intval', (array) brikpanel_variation_ids( $pid ) ) : [];
            foreach ( $variations[ $pid ] as $vid ) {
                $all[] = $vid;
            }
        }
        if ( ! empty( $all ) ) {
            update_meta_cache( 'post', $all );
        }

        $galleries = $this->variation_galleries_shown();
        $out       = [];
        $images    = [];

        foreach ( $product_ids as $pid ) {
            $refs = [];

            $featured = (int) get_post_thumbnail_id( $pid );
            if ( $featured > 0 ) {
                $refs[] = [ $featured, 'main', 0 ];
            }

            foreach ( $this->gallery_ids( get_post_meta( $pid, '_product_image_gallery', true ) ) as $g ) {
                $refs[] = [ $g, 'gallery', 0 ];
            }

            foreach ( $variations[ $pid ] as $vid ) {
                $thumb = (int) get_post_thumbnail_id( $vid );
                if ( $thumb > 0 ) {
                    $refs[] = [ $thumb, 'variation', $vid ];
                }
                if ( $galleries ) {
                    foreach ( (array) get_post_meta( $vid, '_brikpanel_variation_gallery', true ) as $g ) {
                        $g = (int) $g;
                        if ( $g > 0 ) {
                            $refs[] = [ $g, 'vgallery', $vid ];
                        }
                    }
                }
            }

            foreach ( $refs as $ref ) {
                $images[ $ref[0] ] = true;
            }
            $out[ $pid ] = $refs;
        }

        if ( ! empty( $images ) ) {
            _prime_post_caches( array_keys( $images ), false, true );
        }

        return $out;
    }

    /**
     * Image ids in a gallery field ("12,34"), in their order.
     *
     * @param mixed $gallery Stored value.
     * @return int[]
     */
    private function gallery_ids( $gallery ) {
        $ids = [];
        if ( ! is_scalar( $gallery ) || '' === (string) $gallery ) {
            return $ids;
        }
        foreach ( explode( ',', (string) $gallery ) as $g ) {
            $g = (int) trim( $g );
            if ( $g > 0 ) {
                $ids[] = $g;
            }
        }
        return $ids;
    }

    /**
     * Whether the shop shows BrikPanel's variation galleries (the same two
     * settings front-end/products/brikpanel-variation-gallery.php reads).
     *
     * @return bool
     */
    private function variation_galleries_shown() {
        return 'yes' === get_option( 'brikpanel_simple_product_editor', 'yes' )
            && 'yes' === get_option( 'brikpanel_variation_gallery_enabled', 'yes' );
    }

    /**
     * @param int $att_id
     * @param int $product_id Product the attachment first appeared under.
     * @param int $threshold  Oversize threshold in bytes.
     * @param array $partial  Mutated by reference via the caller.
     */
    private function score_attachment( $att_id, $product_id, $threshold, array &$partial ) {
        $partial['totals']['attachments']++;

        $path    = get_attached_file( $att_id, true );
        $on_disk = $path && @file_exists( $path );
        $mime    = (string) get_post_mime_type( $att_id );

        // Modernity bucket. We treat an attachment as "modern" when EITHER:
        //   1. its own MIME is image/webp or image/avif, or
        //   2. an optimizer plugin is active AND has written a sibling .webp
        //      file (Converter for Media, ShortPixel, EWWW, Smush, Imagify all
        //      keep the JPG/PNG in place and serve .webp via .htaccess /
        //      <picture> rewrites — those rewrites disappear the moment the
        //      plugin is deactivated, so the sidecar files stop reaching the
        //      browser even though they're still on disk).
        // The optimizer_active gate is the key correctness rule: without it
        // we'd keep reporting "ok" forever after the user disables the only
        // plugin actually serving the modern format.
        $is_native_modern  = ( $mime === 'image/webp' || $mime === 'image/avif' );
        $has_modern_sidecar = ! $is_native_modern
            && ! empty( $partial['optimizer_active'] )
            && $on_disk
            && $this->has_webp_sidecar( $path );

        if ( $is_native_modern || $has_modern_sidecar ) {
            $partial['totals']['webp_avif']++;
            if ( $has_modern_sidecar ) {
                $partial['totals']['webp_via_sidecar']++;
            }
        } elseif ( $mime !== '' && strpos( $mime, 'image/' ) === 0 ) {
            $partial['totals']['legacy']++;
        }

        if ( ! $on_disk ) {
            $reason = $this->missing_reason( $att_id );
            if ( '' !== $reason ) {
                $partial['totals']['missing_files']++;
                if ( 'deleted' === $reason ) {
                    $partial['totals']['missing_deleted']++;
                }
                $partial['missing_atts'][ $att_id ] = $reason;
                return;
            }
        }

        $size = $this->resolve_filesize( $att_id, $path, $on_disk );
        if ( false === $size ) {
            return; // Served from elsewhere with no size on record: nothing to weigh.
        }

        if ( $size >= $threshold ) {
            $partial['totals']['oversized']++;
            $this->maybe_record_largest( $att_id, $product_id, $size, $mime, $partial );
        }
    }

    /**
     * Why an image whose file is not on this server is missing, or '' when it
     * is not missing at all.
     *
     *  - 'deleted': the Media Library no longer has it, and its id stayed
     *    behind on a product (WordPress clears featured images when an image
     *    is deleted, galleries it leaves alone).
     *  - 'file': the Media Library has it, but the file is gone while its
     *    address still points at this site's uploads: the shop shows a broken
     *    image. Before 3.3.37 these were found only when WordPress had no size
     *    on record for the image; it records one for every upload since 6.0,
     *    so a file deleted from the server went unnoticed.
     *  - '': the address points elsewhere (an offload or CDN plugin, an
     *    external image), so the file is not expected here.
     *
     * @param int $att_id
     * @return string
     */
    private function missing_reason( $att_id ) {
        if ( $this->is_deleted_image( $att_id ) ) {
            return 'deleted';
        }

        $post = get_post( $att_id );
        if ( ! $post || 'attachment' !== $post->post_type ) {
            return ''; // A plugin serves it (see is_deleted_image()).
        }

        $url = (string) wp_get_attachment_url( $att_id );
        if ( '' !== $url && ! $this->is_local_upload_url( $url ) ) {
            return '';
        }

        return 'file';
    }

    /**
     * Whether a product lists an image the Media Library no longer has.
     *
     * The same test WooCommerce's product images box uses before it quietly
     * drops such an id from a gallery, so an image a media-sharing plugin
     * still serves under that id is never taken for a deleted one.
     *
     * @param int $att_id
     * @return bool
     */
    private function is_deleted_image( $att_id ) {
        $post = get_post( $att_id );
        if ( $post && 'attachment' === $post->post_type ) {
            return false;
        }
        return ! wp_get_attachment_image_src( $att_id, 'thumbnail' );
    }

    /**
     * Whether an image address points at this site's uploads folder, where
     * its file should be. The scheme does not matter (http and https), and an
     * address without a host is this site's.
     *
     * @param string $url
     * @return bool
     */
    private function is_local_upload_url( $url ) {
        static $bases = [];

        $blog = get_current_blog_id();
        if ( ! isset( $bases[ $blog ] ) ) {
            $dir            = wp_get_upload_dir();
            $bases[ $blog ] = self::url_key( isset( $dir['baseurl'] ) ? (string) $dir['baseurl'] : '' );
        }
        $base = $bases[ $blog ];

        if ( '' === $base ) {
            return true; // Cannot tell: the file belongs here.
        }

        $key = self::url_key( $url, $base );
        return $key === $base || 0 === strpos( $key, $base . '/' );
    }

    /**
     * An address as "//host/path", lower case, without its scheme or a
     * trailing slash. An address without a host takes the host of $base.
     *
     * @param string $url
     * @param string $base A key made by this method, for addresses without a host.
     * @return string
     */
    private static function url_key( $url, $base = '' ) {
        $url = trim( (string) $url );
        if ( '' === $url ) {
            return '';
        }

        if ( '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) && '' !== $base ) {
            $slash = strpos( $base, '/', 2 );
            $url   = ( false === $slash ? $base : substr( $base, 0, $slash ) ) . $url;
        }

        $url = (string) preg_replace( '#^[a-z][a-z0-9+.\-]*:#i', '', $url );

        return strtolower( untrailingslashit( $url ) );
    }

    /**
     * Whether the optimizer has produced a `.webp` (or `.avif`) variant for
     * this attachment. Optimizers don't agree on a single layout, so we check
     * the four real-world conventions:
     *
     *   1. Same dir, extension replaced  : foo.jpg → foo.webp
     *   2. Same dir, extension appended  : foo.jpg → foo.jpg.webp
     *   3. Mirror dir, ext appended      : wp-content/uploads-webpc/uploads/.../foo.jpg.webp
     *      (Converter for Media default — it mirrors the entire uploads tree
     *      under `uploads-webpc/` and tags every variant with `.webp` on top
     *      of the original extension.)
     *   4. Mirror dir, ext replaced      : same as (3) but with extension
     *      replaced — covers a few less common configs.
     *
     * Returns false when the source file isn't local (CDN offload) — those
     * attachments fall back to MIME scoring only.
     *
     * @param string|false $path
     * @return bool
     */
    private function has_webp_sidecar( $path ) {
        if ( empty( $path ) || ! @file_exists( $path ) ) {
            return false;
        }

        // 1 + 2: same-directory variants.
        $replaced_same = preg_replace( '/\.(jpe?g|png|gif|bmp|tiff?)$/i', '.webp', $path );
        if ( is_string( $replaced_same ) && $replaced_same !== $path && @file_exists( $replaced_same ) ) {
            return true;
        }
        if ( @file_exists( $path . '.webp' ) ) {
            return true;
        }
        if ( @file_exists( $path . '.avif' ) ) {
            return true;
        }

        // 3 + 4: mirror-directory variants. We translate the absolute upload
        // path into a path under each known mirror root and probe both the
        // appended and replaced extensions.
        $mirrors = self::get_webp_mirror_roots();
        if ( empty( $mirrors ) ) {
            return false;
        }

        $upload_dir  = wp_get_upload_dir();
        $upload_base = isset( $upload_dir['basedir'] ) ? wp_normalize_path( (string) $upload_dir['basedir'] ) : '';
        $norm_path   = wp_normalize_path( (string) $path );

        // Only attempt the mirror probe when the file actually lives under
        // the upload basedir — otherwise the path arithmetic is meaningless.
        if ( $upload_base === '' || strpos( $norm_path, $upload_base ) !== 0 ) {
            return false;
        }
        $rel = ltrim( substr( $norm_path, strlen( $upload_base ) ), '/' );

        foreach ( $mirrors as $mirror_root ) {
            $candidates = [
                $mirror_root . '/' . $rel . '.webp',
                $mirror_root . '/' . $rel . '.avif',
                $mirror_root . '/' . preg_replace( '/\.(jpe?g|png|gif|bmp|tiff?)$/i', '.webp', $rel ),
            ];
            foreach ( $candidates as $candidate ) {
                if ( $candidate && @file_exists( $candidate ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Build the list of mirror roots that hold optimizer-generated webp/avif
     * siblings of files under wp-content/uploads/. Cached per-request because
     * we hit it once per attachment.
     *
     * Filterable via `brikpanel_brikcontrol_webp_mirror_roots` so other
     * optimizers (or non-default Converter for Media setups) can opt in.
     *
     * @return string[] Absolute filesystem paths, no trailing slash.
     */
    private static function get_webp_mirror_roots() {
        static $cached = null;
        if ( $cached !== null ) {
            return $cached;
        }

        $upload_dir  = wp_get_upload_dir();
        $upload_base = isset( $upload_dir['basedir'] ) ? wp_normalize_path( (string) $upload_dir['basedir'] ) : '';

        $roots = [];

        // Converter for Media default: <wp-content>/uploads-webpc/uploads/<...>
        // We point the mirror root at the parent of "/uploads/" so the
        // relative-path probe in has_webp_sidecar() sees the same "uploads/.."
        // prefix it strips off the source path.
        if ( defined( 'WP_CONTENT_DIR' ) ) {
            $cfm = wp_normalize_path( WP_CONTENT_DIR . '/uploads-webpc/uploads' );
            if ( $cfm !== $upload_base ) {
                $roots[] = $cfm;
            }
        }

        // EWWW Image Optimizer's WebP cache layout (when "WebP Conversion" is
        // on with the same-directory option off) lives under
        // <uploads>/ewww/ — same basedir, different prefix.
        $roots[] = $upload_base; // No-op fallback — same-directory probe
                                 // already covers it, kept here so filters can
                                 // append to a non-empty list.

        $roots = array_values( array_unique( array_filter( apply_filters(
            'brikpanel_brikcontrol_webp_mirror_roots',
            $roots
        ) ) ) );

        $cached = $roots;
        return $cached;
    }

    /**
     * Lookup order:
     *   1. The file on this server.
     *   2. WP-stored attachment metadata (`filesize`) — covers offload plugins
     *      that strip the file off the local FS but still record metadata.
     *      Only reached for an image served from elsewhere: one whose file
     *      belongs here and is gone counts as missing (missing_reason()).
     *   3. False — nothing to weigh.
     *
     * @param int          $att_id
     * @param string|false $path    Attached path, already resolved.
     * @param bool         $on_disk Whether that path exists.
     * @return int|false
     */
    private function resolve_filesize( $att_id, $path, $on_disk ) {
        if ( $on_disk ) {
            $bytes = @filesize( $path );
            if ( $bytes !== false ) {
                return (int) $bytes;
            }
        }

        $meta = wp_get_attachment_metadata( $att_id );
        if ( is_array( $meta ) && ! empty( $meta['filesize'] ) ) {
            return (int) $meta['filesize'];
        }

        return false;
    }

    /**
     * Maintains a top-N (largest first) running list inside the partial state.
     * Cheap insertion sort because N=10.
     */
    private function maybe_record_largest( $att_id, $product_id, $size, $mime, array &$partial ) {
        $largest = $partial['largest'];
        $count   = count( $largest );

        if ( $count >= self::LARGEST_KEEP && $size <= $largest[ $count - 1 ]['bytes'] ) {
            return;
        }

        // Ids only: the links are built for the viewer when the card is drawn.
        // get_edit_post_link() checks the current user, and a scan worker has
        // none, so a link stored here was always empty.
        $entry = [
            'id'         => $att_id,
            'post_id'    => $product_id,
            'bytes'      => (int) $size,
            'size_mb'    => round( $size / 1048576, 2 ),
            'mime'       => $mime,
        ];

        $largest[] = $entry;
        usort( $largest, static function ( $a, $b ) {
            return $b['bytes'] <=> $a['bytes'];
        } );
        if ( count( $largest ) > self::LARGEST_KEEP ) {
            $largest = array_slice( $largest, 0, self::LARGEST_KEEP );
        }
        $partial['largest'] = $largest;
    }

    /**
     * Count a product's missing images and keep its rows for the card's list.
     *
     * One row per place and reason, with how many images it holds: a gallery
     * with two deleted images is one row that says so, not two rows that read
     * the same. Rows of files gone from the server and of images deleted from
     * the Media Library are kept apart, for up to MISSING_KEEP products each,
     * so the list can put the broken ones first however many deleted ones the
     * scan met before them.
     *
     * @param int   $product_id
     * @param array $missing    [ [ ref, reason ], ... ] of this product.
     * @param array $partial    Scan state, changed in place.
     * @return void
     */
    private function record_missing( $product_id, array $missing, array &$partial ) {
        if ( ! isset( $partial['missing'] ) || ! is_array( $partial['missing'] ) ) {
            $partial['missing'] = $this->fresh_missing();
        }
        $m =& $partial['missing'];

        $rows = [];
        foreach ( $missing as $item ) {
            $ref    = $item[0];
            $reason = (string) $item[1];
            $key    = $ref[2] . '|' . $ref[1] . '|' . $reason;
            if ( ! isset( $rows[ $key ] ) ) {
                $rows[ $key ] = [
                    'p' => (int) $product_id,
                    'v' => (int) $ref[2],
                    'w' => (string) $ref[1],
                    'r' => $reason,
                    'n' => 0,
                    'a' => [], // The first few images, for their file names.
                ];
            }
            $rows[ $key ]['n']++;
            if ( count( $rows[ $key ]['a'] ) < 3 ) {
                $rows[ $key ]['a'][] = (int) $ref[0];
            }

            $m['refs']++;
            if ( 'deleted' === $reason ) {
                $m['deleted_refs']++;
            }
        }

        $file    = [];
        $deleted = [];
        foreach ( $rows as $row ) {
            if ( 'deleted' === $row['r'] ) {
                $deleted[] = $row;
            } else {
                $file[] = $row;
            }
        }

        $m['products']++;
        if ( ! empty( $file ) ) {
            $m['file_products']++;
            if ( $m['file_products'] <= self::MISSING_KEEP ) {
                foreach ( $file as $row ) {
                    $m['file_rows'][] = $row;
                }
            }
        }
        if ( ! empty( $deleted ) ) {
            $m['deleted_products']++;
            if ( $m['deleted_products'] <= self::MISSING_KEEP ) {
                foreach ( $deleted as $row ) {
                    $m['deleted_rows'][] = $row;
                }
            }
        }
    }

    // =========================================================================
    // PRODUCT QUERY
    // =========================================================================

    private function count_products() {
        $q = new WP_Query( [
            'post_type'      => 'product',
            'post_status'    => [ 'publish', 'private' ],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
            'cache_results'  => false,
        ] );
        return (int) $q->found_posts;
    }

    /**
     * @param int $offset
     * @param int $limit
     * @return int[]
     */
    private function fetch_product_ids( $offset, $limit ) {
        $q = new WP_Query( [
            'post_type'      => 'product',
            'post_status'    => [ 'publish', 'private' ],
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'cache_results'  => false,
        ] );
        return array_map( 'intval', (array) $q->posts );
    }

    // =========================================================================
    // FINALISATION
    // =========================================================================

    private function fresh_partial() {
        return [
            'products_scanned' => 0,
            'total_products'   => 0,
            'optimizer_active' => false, // Snapshotted on first batch.
            'totals'           => [
                'products'         => 0,
                'attachments'      => 0,
                'oversized'        => 0,
                'webp_avif'        => 0,    // counts both native + sidecar
                'webp_via_sidecar' => 0,    // subset of webp_avif served via sidecar
                'legacy'           => 0,
                'missing_files'    => 0,
                'missing_deleted'  => 0,    // subset of missing_files gone from the Media Library
            ],
            'largest'          => [],
            'seen_attachments' => [], // att_id => product_id
            'missing_atts'     => [], // att_id => 'deleted' | 'file', for every missing image seen so far
            'missing'          => $this->fresh_missing(),
            'started_at'       => time(),
        ];
    }

    /**
     * @return array
     */
    private function fresh_missing() {
        return [
            'products'         => 0,
            'refs'             => 0,
            'file_products'    => 0,
            'deleted_products' => 0,
            'deleted_refs'     => 0,
            'file_rows'        => [],
            'deleted_rows'     => [],
        ];
    }

    /**
     * A new scan's state, before its first slice.
     *
     * @return array
     */
    private function start_partial() {
        $partial                   = $this->fresh_partial();
        $partial['total_products'] = $this->count_products();
        // Snapshot at scan start so every batch in this run uses the same
        // optimizer-active flag — a plugin deactivation mid-scan won't
        // make some attachments count as "modern via sidecar" and others
        // not. The flag is what gates sidecar credit (see
        // score_attachment): without an active optimizer, sidecar files
        // sitting on disk no longer translate into served WebP because
        // the plugin's .htaccess / <picture> rewrite is gone.
        $partial['optimizer_active'] = Brikpanel_BrikControl_Image_Plugins::any_active();
        return $partial;
    }

    private function save_partial( array $partial ) {
        update_option( self::PARTIAL_OPTION, $partial, false );
    }

    private function load_partial() {
        $stored = get_option( self::PARTIAL_OPTION, null );
        $fresh  = $this->fresh_partial();
        if ( ! is_array( $stored ) ) {
            return $fresh;
        }
        // Nested defaults too: a scan in flight while the plugin updated holds
        // the older shape, without the counters added since.
        $partial            = wp_parse_args( $stored, $fresh );
        $partial['totals']  = wp_parse_args( (array) $partial['totals'], $fresh['totals'] );
        $partial['missing'] = wp_parse_args( (array) $partial['missing'], $fresh['missing'] );
        return $partial;
    }

    private function clear_partial() {
        delete_option( self::PARTIAL_OPTION );
    }


    /**
     * @param array $partial    Scan state.
     * @param float $started_at microtime() of the slice that finishes the scan.
     * @param bool  $persist    False for a scan that kept its state in memory
     *                          (refresh_after_change()): the partial-state
     *                          option is left alone.
     * @return array
     */
    private function finalise( array $partial, $started_at, $persist = true ) {
        $totals       = $partial['totals'];
        $attachments  = (int) $totals['attachments'];
        $oversized    = (int) $totals['oversized'];
        $modern       = (int) $totals['webp_avif'];
        $missing      = (int) $totals['missing_files'];

        $oversized_pct = $attachments > 0 ? round( ( $oversized / $attachments ) * 100, 1 ) : 0;
        $modern_pct    = $attachments > 0 ? round( ( $modern / $attachments ) * 100, 1 ) : 0;
        $missing_pct   = $attachments > 0 ? round( ( $missing / $attachments ) * 100, 1 ) : 0;

        $crit_over = (float) apply_filters( 'brikpanel_brikcontrol_image_critical_oversized_pct', self::DEFAULT_CRIT_OVERSIZED_PCT );
        $warn_over = (float) apply_filters( 'brikpanel_brikcontrol_image_warning_oversized_pct', self::DEFAULT_WARN_OVERSIZED_PCT );
        // Informational since schema 3: stored with the result, read by the
        // tip in bc_present(), never part of the status or the score.
        $crit_mod  = (float) apply_filters( 'brikpanel_brikcontrol_image_critical_modern_pct', self::DEFAULT_CRIT_MODERN_PCT );
        $warn_mod  = (float) apply_filters( 'brikpanel_brikcontrol_image_warning_modern_pct', self::DEFAULT_WARN_MODERN_PCT );

        if ( $attachments === 0 ) {
            // Products without a single image: nothing is slow and there is
            // nothing to grade. "0% modern format" out of zero images used to
            // rate this store Critical, with a score of 68 and three install
            // suggestions beside "No product images found yet" (field test E6).
            $status = 'ok';
            $score  = null;
        } else {
            // Graded by what slows a page down or breaks it: images over the
            // size limit and files missing from disk. The WebP / AVIF share
            // used to count too, so a store of small JPEGs could never score
            // above 68 and was rated Critical (field test F2).
            // Any missing file is at least a Warning: it is a broken image on
            // a product page, and a green OK card used to sit beside the red
            // "missing files" row and tile. The count decides, not the rounded
            // share, so 1 missing image out of 5,000 still counts.
            $status = 'ok';
            if ( $oversized_pct >= $crit_over || $missing_pct >= self::CRIT_MISSING_PCT ) {
                $status = 'critical';
            } elseif ( $oversized_pct >= $warn_over || $missing > 0 ) {
                $status = 'warning';
            }

            // Score (0-100): the worse of the two problems decides. 4 points
            // per oversized percent (10% gives 60, 25% gives 0), 10 per missing
            // percent (5% gives 50). bc_band_score() keeps it inside the status
            // band, so a few missing files score at most 79, never an OK 80+.
            $oversize_score = max( 0, 100 - ( $oversized_pct * 4 ) );
            $missing_score  = max( 0, 100 - ( $missing_pct * 10 ) );
            $score          = self::bc_band_score(
                $status,
                max( 0, min( 100, (int) round( min( $oversize_score, $missing_score ) ) ) )
            );
        }

        // Products broken in the shop first: those are what the merchant fixes
        // by hand (the cleanup button takes care of deleted images). A
        // product's rows stay together, and the list holds MISSING_KEEP
        // products with every row of each.
        $m          = ( isset( $partial['missing'] ) && is_array( $partial['missing'] ) ) ? $partial['missing'] : [];
        $by_product = [];
        foreach ( array_merge( (array) ( $m['file_rows'] ?? [] ), (array) ( $m['deleted_rows'] ?? [] ) ) as $row ) {
            if ( is_array( $row ) && ! empty( $row['p'] ) ) {
                $by_product[ (int) $row['p'] ][] = $row;
            }
        }
        $rows = [];
        foreach ( array_slice( $by_product, 0, self::MISSING_KEEP, true ) as $product_rows ) {
            foreach ( $product_rows as $row ) {
                $rows[] = $row;
            }
        }

        $active_plugins = Brikpanel_BrikControl_Image_Plugins::get_active();

        $result           = $this->make_result_skeleton();
        $result['status'] = $status;
        $result['score']  = $score;
        $result['facts']  = [
            'totals'      => [
                'attachments'      => $attachments,
                'oversized'        => $oversized,
                'webp_avif'        => $modern,
                'webp_via_sidecar' => (int) ( $totals['webp_via_sidecar'] ?? 0 ),
                'legacy'           => (int) $totals['legacy'],
                'missing_files'    => $missing,
                'missing_deleted'  => (int) ( $totals['missing_deleted'] ?? 0 ),
                'products'         => (int) $partial['products_scanned'],
            ],
            'percentages' => [
                'oversized_pct' => $oversized_pct,
                'modern_pct'    => $modern_pct,
                'missing_pct'   => $missing_pct,
            ],
            'thresholds'  => [
                'oversize_bytes'    => (int) apply_filters( 'brikpanel_brikcontrol_image_threshold_bytes', self::DEFAULT_THRESHOLD_BYTES ),
                'warn_oversized'    => $warn_over,
                'crit_oversized'    => $crit_over,
                'warn_modern'       => $warn_mod,
                'crit_modern'       => $crit_mod,
            ],
            'largest'     => $partial['largest'],
            'missing'     => [
                'products'         => (int) ( $m['products'] ?? 0 ),
                'refs'             => (int) ( $m['refs'] ?? 0 ),
                'deleted_products' => (int) ( $m['deleted_products'] ?? 0 ),
                'deleted_refs'     => (int) ( $m['deleted_refs'] ?? 0 ),
                'rows'             => $rows,
            ],
            'plugins'     => [
                'active'          => $active_plugins,
                'recommendations' => empty( $active_plugins ) ? Brikpanel_BrikControl_Image_Plugins::get_recommendations() : [],
            ],
        ];
        $result['scanned_at']    = time();
        $result['duration_ms']   = (int) round( ( microtime( true ) - $started_at ) * 1000 );
        $result['batch_state']   = [
            'cursor' => $partial['products_scanned'],
            'total'  => $partial['total_products'],
            'done'   => true,
        ];

        if ( $persist ) {
            $this->clear_partial();
        }
        return $result;
    }

    private function build_empty_result( $started_at ) {
        $result                = $this->make_result_skeleton();
        $result['status']      = 'ok';
        $result['facts']       = [
            'no_products' => true,
            'totals'      => [
                'attachments'     => 0,
                'oversized'       => 0,
                'webp_avif'       => 0,
                'legacy'          => 0,
                'missing_files'   => 0,
                'missing_deleted' => 0,
                'products'        => 0,
            ],
            'percentages' => [ 'oversized_pct' => 0, 'modern_pct' => 0, 'missing_pct' => 0 ],
            'largest'     => [],
            'missing'     => [ 'products' => 0, 'refs' => 0, 'deleted_products' => 0, 'deleted_refs' => 0, 'rows' => [] ],
            'plugins'     => [
                'active'          => Brikpanel_BrikControl_Image_Plugins::get_active(),
                'recommendations' => [],
            ],
        ];
        $result['duration_ms'] = (int) round( ( microtime( true ) - $started_at ) * 1000 );
        $result['batch_state'] = [ 'cursor' => 0, 'total' => 0, 'done' => true ];
        return $result;
    }

    /**
     * Sentences for the stored figures, in the viewer's language.
     *
     * Results written before schema 2 carry the same figures under
     * `metadata`, so they are shown in the viewer's language as well.
     *
     * @param array  $r       Stored result.
     * @param string $context 'page' or 'summary'.
     * @return array
     */
    public function bc_present( array $r, $context = 'page' ) {
        $r = parent::bc_present( $r, $context );

        $batch = ( isset( $r['batch_state'] ) && is_array( $r['batch_state'] ) ) ? $r['batch_state'] : null;
        if ( $batch && empty( $batch['done'] ) ) {
            $total                = (int) ( $batch['total'] ?? 0 );
            $r['summary']         = brikpanel_safe_sprintf(
                /* translators: 1: products scanned so far, 2: products to scan in total. */
                _n( 'Scanning… %1$s / %2$s product', 'Scanning… %1$s / %2$s products', $total, 'brikpanel' ),
                brikpanel_number( (int) ( $batch['cursor'] ?? 0 ) ),
                brikpanel_number( $total )
            );
            $r['message']         = '';
            $r['recommendations'] = [];
            $r['metadata']        = [];
            return $r;
        }

        $f = ! empty( $r['facts'] ) ? (array) $r['facts'] : (array) ( $r['metadata'] ?? [] );
        if ( empty( $f['totals'] ) || ! is_array( $f['totals'] ) ) {
            return $r; // Nothing this check can read: show what was stored.
        }

        $totals      = $f['totals'];
        $pct         = isset( $f['percentages'] ) ? (array) $f['percentages'] : [];
        $attachments = (int) ( $totals['attachments'] ?? 0 );
        $oversized   = (int) ( $totals['oversized'] ?? 0 );
        $missing     = (int) ( $totals['missing_files'] ?? 0 );
        // Missing files make the card Critical from 5% up and Warning below
        // that (finalise()); the row and the tile follow the same level.
        $missing_bad = (float) ( $pct['missing_pct'] ?? 0 ) >= self::CRIT_MISSING_PCT;
        $over_pct    = (float) ( $pct['oversized_pct'] ?? 0 );
        $modern_pct  = (float) ( $pct['modern_pct'] ?? 0 );
        // Schema 4: which products use the missing images. Older results
        // have no list and no cleanup until the rescan maybe_heal() queues.
        $mfacts      = ( isset( $f['missing'] ) && is_array( $f['missing'] ) ) ? $f['missing'] : [];
        $has_list    = ! empty( $mfacts['rows'] ) && is_array( $mfacts['rows'] );

        $r['message']         = '';
        $r['recommendations'] = [];
        $r['metadata']        = [];

        if ( 0 === $attachments ) {
            $r['score']   = null;
            $r['summary'] = ( ! empty( $f['no_products'] ) || 0 === (int) ( $totals['products'] ?? 0 ) )
                ? __( 'No products to check yet.', 'brikpanel' )
                : __( 'No product images to check yet.', 'brikpanel' );
            return $r;
        }

        $r['summary'] = brikpanel_safe_sprintf(
            /* translators: 1: share of images over 1 MB, e.g. "12%", 2: number of those images, 3: all product images, 4: share in WebP or AVIF, e.g. "40%". */
            __( '%1$s oversized (%2$s of %3$s) · %4$s modern format', 'brikpanel' ),
            brikpanel_percent( $over_pct ),
            brikpanel_number( $oversized ),
            brikpanel_number( $attachments ),
            brikpanel_percent( $modern_pct )
        );

        if ( 'page' !== $context ) {
            return $r;
        }

        $t         = isset( $f['thresholds'] ) ? (array) $f['thresholds'] : [];
        $crit_over = (float) ( $t['crit_oversized'] ?? self::DEFAULT_CRIT_OVERSIZED_PCT );
        $warn_over = (float) ( $t['warn_oversized'] ?? self::DEFAULT_WARN_OVERSIZED_PCT );
        $warn_mod  = (float) ( $t['warn_modern'] ?? self::DEFAULT_WARN_MODERN_PCT );
        $legacy    = (int) ( $totals['legacy'] ?? 0 );
        $plugins   = isset( $f['plugins'] ) ? (array) $f['plugins'] : [];
        $active    = isset( $plugins['active'] ) ? (array) $plugins['active'] : [];

        $recs = [];

        if ( $oversized > 0 ) {
            $recs[] = [
                'text'     => brikpanel_safe_sprintf(
                    /* translators: 1: number of product images, 2: their share of all product images, e.g. "12%". */
                    _n(
                        '%1$s product image (%2$s) is larger than 1 MB. It slows down your product pages and hurts SEO. Compress it to under 200 KB where possible.',
                        '%1$s product images (%2$s) are larger than 1 MB. They slow down your product pages and hurt SEO. Compress them to under 200 KB where possible.',
                        $oversized,
                        'brikpanel'
                    ),
                    brikpanel_number( $oversized ),
                    brikpanel_percent( $over_pct )
                ),
                'priority' => $over_pct >= $crit_over ? 'high' : 'medium',
            ];
        }

        // One row with a button per plugin (it used to be one row per plugin,
        // three sentences that differed by a single word, field test E6).
        // Only when the share of large images reaches the Warning line by
        // itself, never because of the card status: missing files also make
        // the card Warning or Critical, but an optimizer does not bring back a
        // missing file, and the free Smush compresses but cannot convert to
        // WebP (field test F2). Its colour follows the large-images row, so
        // the suggestion is never louder than the problem it fixes.
        if ( empty( $active ) && $oversized > 0 && $over_pct >= $warn_over ) {
            $links = [];
            foreach ( (array) ( $plugins['recommendations'] ?? [] ) as $plugin ) {
                if ( empty( $plugin['slug'] ) || empty( $plugin['label'] ) ) {
                    continue;
                }
                $links[] = [
                    'plugin_slug'   => (string) $plugin['slug'],
                    'plugin_search' => (string) ( $plugin['search'] ?? '' ),
                    'label'         => (string) $plugin['label'],
                    'aria_label'    => brikpanel_safe_sprintf(
                        /* translators: %s: plugin name, e.g. "Smush". */
                        __( 'Install %s', 'brikpanel' ),
                        (string) $plugin['label']
                    ),
                ];
            }
            if ( ! empty( $links ) ) {
                $recs[] = [
                    'text'     => __( 'Install an image optimizer. It compresses large product images automatically, including the ones you upload later.', 'brikpanel' ),
                    'priority' => $over_pct >= $crit_over ? 'high' : 'medium',
                    'links'    => $links,
                ];
            }
        }

        if ( $missing > 0 ) {
            $text = brikpanel_safe_sprintf(
                /* translators: %s: number of product images. */
                _n(
                    '%s product image points to a file that no longer exists on disk. Upload it again or remove it from the product.',
                    '%s product images point to files that no longer exist on disk. Upload them again or remove them from their products.',
                    $missing,
                    'brikpanel'
                ),
                brikpanel_number( $missing )
            );
            // Field report 3.3.37: with a thousand products the count alone
            // left the merchant searching for them.
            if ( $has_list ) {
                $text .= ' ' . _n(
                    'Open the list below to see which product it is on.',
                    'Open the list below to see which products they are on.',
                    $missing,
                    'brikpanel'
                );
            }
            $recs[] = [
                'text'     => $text,
                'priority' => $missing_bad ? 'high' : 'medium',
            ];
        }

        // WebP / AVIF is a tip, never a problem: a grey row that changes
        // neither the status nor the score. Not shown once an optimizer is
        // active (converting is its job) or when there is nothing to convert.
        $tip = null;
        if ( empty( $active ) && $legacy > 0 && $modern_pct < $warn_mod ) {
            $tip = [
                'text'     => ( $legacy >= $attachments )
                    ? brikpanel_safe_sprintf(
                        /* translators: %s: number of product images, all of them JPEG or PNG. */
                        _n(
                            'Tip: your %s product image is a JPEG or PNG. Saving it as WebP makes it smaller. This does not affect the score.',
                            'Tip: all %s of your product images are JPEG or PNG. Saving them as WebP makes them smaller. This does not affect the score.',
                            $attachments,
                            'brikpanel'
                        ),
                        brikpanel_number( $attachments )
                    )
                    : brikpanel_safe_sprintf(
                        /* translators: 1: number of product images in JPEG or PNG, 2: number of all product images. */
                        _n(
                            'Tip: %1$s of your %2$s product images is a JPEG or PNG. Saving it as WebP makes it smaller. This does not affect the score.',
                            'Tip: %1$s of your %2$s product images are JPEG or PNG. Saving them as WebP makes them smaller. This does not affect the score.',
                            $legacy,
                            'brikpanel'
                        ),
                        brikpanel_number( $legacy ),
                        brikpanel_number( $attachments )
                    ),
                'priority' => 'info',
            ];
        }

        if ( empty( $recs ) ) {
            $recs[] = [
                'text'     => ( null !== $tip )
                    ? __( 'No product image is over 1 MB and none is missing.', 'brikpanel' )
                    : __( 'Your product images are well optimised. Keep an eye on this metric as you upload new products.', 'brikpanel' ),
                'priority' => 'low',
            ];
        }
        if ( null !== $tip ) {
            $recs[] = $tip;
        }

        $stats = [
            [
                'label' => __( 'Total images', 'brikpanel' ),
                'value' => $attachments,
                'tone'  => '',
            ],
            [
                'label' => __( 'Over 1 MB', 'brikpanel' ),
                'value' => $oversized,
                'tone'  => $oversized > 0 ? 'warn' : '',
            ],
            [
                'label' => __( 'WebP / AVIF', 'brikpanel' ),
                'value' => (int) ( $totals['webp_avif'] ?? 0 ),
                'tone'  => (int) ( $totals['webp_avif'] ?? 0 ) > 0 ? 'good' : '',
            ],
            [
                'label' => __( 'JPEG / PNG', 'brikpanel' ),
                'value' => (int) ( $totals['legacy'] ?? 0 ),
                'tone'  => '',
            ],
        ];
        if ( $missing > 0 ) {
            $stats[] = [
                'label' => __( 'Missing files', 'brikpanel' ),
                'value' => $missing,
                'tone'  => $missing_bad ? 'error' : 'warn',
            ];
        }

        // The cleanup's count comes from the scan; the restore point is read
        // live, so the undo button is right even before the next scan.
        $fixable = (int) ( $mfacts['deleted_products'] ?? 0 );
        $backup  = $this->read_backup_index();
        $undo    = ( $backup['count'] > 0 && ! $this->backup_expired( $backup ) ) ? max( 1, $backup['products'] ) : 0;

        $r['recommendations'] = $recs;
        $r['metadata']        = [
            'stats'        => $stats,
            'missing'      => $has_list ? $this->present_missing( $mfacts ) : [],
            'largest'      => isset( $f['largest'] ) && is_array( $f['largest'] ) ? $f['largest'] : [],
            'plugins'      => [ 'active' => $active ],
            'fixable'      => $fixable,
            'fix_confirm'  => brikpanel_safe_sprintf(
                /* translators: %s: number of products. */
                _n(
                    'Remove the images that were deleted from the Media Library from %s product? You can undo this.',
                    'Remove the images that were deleted from the Media Library from %s products? You can undo this.',
                    $fixable,
                    'brikpanel'
                ),
                brikpanel_number( $fixable )
            ),
            'undoable'     => $undo,
            'undo_at'      => $undo > 0 ? $backup['time'] : 0,
            'undo_confirm' => brikpanel_safe_sprintf(
                /* translators: %s: number of products. */
                _n(
                    'Put the removed images back on %s product?',
                    'Put the removed images back on %s products?',
                    $undo,
                    'brikpanel'
                ),
                brikpanel_number( $undo )
            ),
        ];

        return $r;
    }

    /**
     * The card's list of products with missing images, written for this
     * viewer: names, edit links (get_edit_post_link() checks the viewer), and
     * the labels in the viewer's language.
     *
     * @param array $m Stored `missing` facts.
     * @return array { title, rows: [ { product, url, image, detail, problem } ], note }
     */
    private function present_missing( array $m ) {
        $rows = array_values( array_filter( (array) ( $m['rows'] ?? [] ), static function ( $row ) {
            return is_array( $row ) && ! empty( $row['p'] );
        } ) );
        if ( empty( $rows ) ) {
            return [];
        }

        $ids = [];
        foreach ( $rows as $row ) {
            $ids[] = (int) $row['p'];
            if ( ! empty( $row['v'] ) ) {
                $ids[] = (int) $row['v'];
            }
            if ( 'file' === ( $row['r'] ?? '' ) ) {
                foreach ( (array) ( $row['a'] ?? [] ) as $att_id ) {
                    $ids[] = (int) $att_id;
                }
            }
        }
        _prime_post_caches( array_values( array_unique( array_filter( $ids ) ) ), false, true );

        $out    = [];
        $listed = [];
        foreach ( $rows as $row ) {
            $pid   = (int) $row['p'];
            $count = max( 1, (int) ( $row['n'] ?? 1 ) );
            $title = brikpanel_plain_label( get_the_title( $pid ) );

            // Under the place: which files to upload again, or how many
            // deleted images a gallery still lists.
            $detail = '';
            if ( 'file' === ( $row['r'] ?? '' ) ) {
                $names = [];
                foreach ( (array) ( $row['a'] ?? [] ) as $att_id ) {
                    $stored = (string) get_post_meta( (int) $att_id, '_wp_attached_file', true );
                    if ( '' !== $stored ) {
                        $names[] = wp_basename( $stored );
                    }
                }
                if ( ! empty( $names ) && $count > count( $names ) ) {
                    $names[] = '…';
                }
                $detail = implode( ', ', $names );
            } elseif ( $count > 1 ) {
                $detail = brikpanel_safe_sprintf(
                    /* translators: %s: number of images. */
                    _n( '%s image', '%s images', $count, 'brikpanel' ),
                    brikpanel_number( $count )
                );
            }

            $out[]          = [
                'product' => '' !== $title ? $title : __( '(no title)', 'brikpanel' ),
                'url'     => (string) get_edit_post_link( $pid, 'raw' ),
                'image'   => $this->where_label( (string) ( $row['w'] ?? '' ), (int) ( $row['v'] ?? 0 ) ),
                'detail'  => $detail,
                'problem' => 'deleted' === ( $row['r'] ?? '' )
                    ? __( 'Deleted from the Media Library', 'brikpanel' )
                    : __( 'File missing on the server', 'brikpanel' ),
            ];
            $listed[ $pid ] = true;
        }

        $products = (int) ( $m['products'] ?? 0 );

        return [
            'title' => brikpanel_safe_sprintf(
                /* translators: %s: number of products. */
                _n( '%s product with missing images', '%s products with missing images', $products, 'brikpanel' ),
                brikpanel_number( $products )
            ),
            'rows'  => $out,
            'note'  => $products > count( $listed )
                ? brikpanel_safe_sprintf(
                    /* translators: 1: products in the list, 2: all products with missing images. */
                    _n(
                        'Only the first %1$s of %2$s product is listed. Fix these and scan again to see the rest.',
                        'Only the first %1$s of %2$s products are listed. Fix these and scan again to see the rest.',
                        $products,
                        'brikpanel'
                    ),
                    brikpanel_number( count( $listed ) ),
                    brikpanel_number( $products )
                )
                : '',
        ];
    }

    /**
     * Where on the product a missing image was shown, in the viewer's language.
     *
     * @param string $where        'main', 'gallery', 'variation' or 'vgallery'.
     * @param int    $variation_id Variation, for the last two.
     * @return string
     */
    private function where_label( $where, $variation_id ) {
        if ( 'main' === $where ) {
            return __( 'Main image', 'brikpanel' );
        }
        if ( 'gallery' === $where ) {
            return _x( 'Gallery', 'where a product image is shown', 'brikpanel' );
        }
        if ( 'variation' !== $where && 'vgallery' !== $where ) {
            return '';
        }

        $options   = '';
        $variation = ( $variation_id > 0 && function_exists( 'wc_get_product' ) ) ? wc_get_product( $variation_id ) : null;
        if ( $variation && function_exists( 'brikpanel_formatted_variation' ) ) {
            $options = brikpanel_plain_label( brikpanel_formatted_variation( $variation, false ) );
        }
        if ( '' === $options ) {
            $options = '#' . (int) $variation_id;
        }

        return 'vgallery' === $where
            ? brikpanel_safe_sprintf(
                /* translators: %s: the variation's options, e.g. "Blue, L". */
                __( 'Variation gallery: %s', 'brikpanel' ),
                $options
            )
            : brikpanel_safe_sprintf(
                /* translators: %s: the variation's options, e.g. "Blue, L". */
                __( 'Variation image: %s', 'brikpanel' ),
                $options
            );
    }

    // =========================================================================
    // CLEANUP — images deleted from the Media Library
    // =========================================================================

    /**
     * Take every image the Media Library no longer has off the products that
     * still list it: gallery entries (in their order), BrikPanel variation
     * galleries, and the featured or variation image in the rare case one is
     * left (WordPress clears those itself when an image is deleted).
     *
     * This is the cleanup WooCommerce's own product images box does silently
     * whenever a product is opened there. Nothing can be uploaded "again"
     * under these ids: the image is gone from the Media Library, only its id
     * stayed behind. Files missing from the server are left alone; those
     * images still exist and are the merchant's to upload again.
     *
     * Fields are written directly, as that box does, so no product save (and
     * no other plugin's save hook) runs. Every changed value is kept in the
     * restore point first. Products are walked in the scan's order and set
     * (published and private), so the numbers match the card; a cleanup that
     * runs out of REQUEST_BUDGET continues where it stopped on the next click.
     *
     * @param array $args Unused.
     * @return array { removed: int (products changed, the unit the card
     *                 counts in), images: int, has_more: bool, message: string }
     */
    public function run_fix( array $args = [] ) {
        // Fix and undo use separate locks in the framework on purpose, but here
        // they must not overlap: an undo emptying the restore point while a
        // cleanup appends to it would lose the new values for good.
        if ( get_transient( 'brikpanel_bc_undo_' . $this->get_id() ) ) {
            return [
                'removed'  => 0,
                'images'   => 0,
                'has_more' => true,
                'message'  => __( 'An undo is running for this check. Try again in a moment.', 'brikpanel' ),
            ];
        }

        $this->expire_backup();

        $started  = microtime( true );
        $size     = $this->batch_size();
        $cursor   = max( 0, (int) get_transient( self::FIX_CURSOR_TRANSIENT ) );
        $removed  = 0;
        $products = [];
        $has_more = false;
        $full     = false;
        $deleted  = []; // image id => bool, asked once per image

        while ( true ) {
            $product_ids = $this->fetch_product_ids( $cursor, $size );
            if ( empty( $product_ids ) ) {
                break;
            }

            $changes = [];
            foreach ( $this->collect_refs( $product_ids ) as $pid => $refs ) {
                $fields = [];
                foreach ( $refs as $ref ) {
                    $att_id = $ref[0];
                    if ( ! isset( $deleted[ $att_id ] ) ) {
                        $deleted[ $att_id ] = $this->is_deleted_image( $att_id );
                    }
                    if ( ! $deleted[ $att_id ] ) {
                        continue;
                    }
                    $post_id = in_array( $ref[1], [ 'variation', 'vgallery' ], true ) ? (int) $ref[2] : (int) $pid;
                    $key     = self::REF_KEYS[ $ref[1] ];
                    $fields[ $post_id . '|' . $key ] = [ $post_id, $key ];
                }
                foreach ( $fields as $field ) {
                    list( $post_id, $key ) = $field;
                    $old = get_post_meta( $post_id, $key, true );
                    $new = $this->without_deleted( $key, $old, $deleted );
                    if ( $this->same_value( $key, $old, $new ) ) {
                        continue;
                    }
                    $changes[] = [
                        'id' => $post_id,
                        'p'  => (int) $pid,
                        'k'  => $key,
                        'o'  => $old,
                        'n'  => $new,
                        'c'  => $this->ids_in( $key, $old ) - $this->ids_in( $key, $new ),
                    ];
                }
            }

            if ( ! empty( $changes ) ) {
                // Never change a product we could not put back.
                $index = $this->read_backup_index();
                if ( $index['count'] + count( $changes ) > self::MAX_BACKUP_ROWS ) {
                    $full = true;
                    break;
                }

                // Backup first: a request that dies between the two steps leaves
                // a restore point for values that were never changed, which undo
                // skips harmlessly (it only restores what still holds the new value).
                $this->append_backup( $changes );

                foreach ( $changes as $change ) {
                    $this->write_field( $change['id'], $change['k'], $change['n'] );
                    $removed += max( 0, (int) $change['c'] );
                    $products[ $change['p'] ] = true;
                }
                $this->flush_product_caches( $changes );
            }

            $cursor += count( $product_ids );
            if ( count( $product_ids ) < $size ) {
                break;
            }
            if ( microtime( true ) - $started > self::REQUEST_BUDGET ) {
                $has_more = true;
                break;
            }
            wp_cache_flush_runtime();
        }

        if ( $has_more ) {
            set_transient( self::FIX_CURSOR_TRANSIENT, $cursor, 10 * MINUTE_IN_SECONDS );
        } else {
            delete_transient( self::FIX_CURSOR_TRANSIENT );
        }

        // A finished cleanup leaves no deleted image: the card the page
        // reloads into is rescanned now (this check is batched, so the
        // framework cannot do it). An unfinished one is continued first.
        if ( ! $has_more && ! $full ) {
            $this->refresh_after_change();
        }

        return [
            'removed'  => count( $products ),
            'images'   => $removed,
            'has_more' => $has_more || $full,
            'message'  => $full
                ? __( 'The restore point is full. Undo the last correction or leave it in place, then run the check again.', 'brikpanel' )
                : '',
        ];
    }

    /**
     * Put every value the cleanups changed back, newest first. A value goes
     * back only while the product still holds exactly what the cleanup wrote:
     * a gallery the merchant changed since keeps the merchant's change.
     *
     * @return array { restored: int, message: string }
     */
    public function run_undo() {
        if ( get_transient( 'brikpanel_bc_fix_' . $this->get_id() ) ) {
            return [ 'restored' => 0, 'message' => __( 'A cleanup is running for this check. Try again in a moment.', 'brikpanel' ) ];
        }

        $index = $this->read_backup_index();
        if ( $index['count'] < 1 ) {
            return [ 'restored' => 0, 'message' => __( 'There is nothing to undo.', 'brikpanel' ) ];
        }

        $allowed  = array_values( array_unique( self::REF_KEYS ) );
        $restored = [];
        $skipped  = [];
        $touched  = [];

        for ( $i = $index['chunks'] - 1; $i >= 0; $i-- ) {
            $rows = (array) get_option( self::BACKUP_CHUNK_PREFIX . $i, [] );

            foreach ( array_reverse( $rows ) as $row ) {
                $post_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
                $parent  = isset( $row['p'] ) ? (int) $row['p'] : 0;
                $key     = isset( $row['k'] ) ? (string) $row['k'] : '';
                if ( $post_id <= 0 || $parent <= 0 || ! in_array( $key, $allowed, true ) || ! array_key_exists( 'o', $row ) ) {
                    continue;
                }

                $post = get_post( $post_id );
                if ( ! $post || ! in_array( $post->post_type, [ 'product', 'product_variation' ], true )
                    || ! $this->same_value( $key, get_post_meta( $post_id, $key, true ), $row['n'] ?? '' ) ) {
                    $skipped[ $parent ] = true;
                    continue;
                }

                $this->write_field( $post_id, $key, $row['o'] );
                $restored[ $parent ] = true;
                $touched[]           = [ 'id' => $post_id, 'p' => $parent ];
            }

            delete_option( self::BACKUP_CHUNK_PREFIX . $i );
            $index['count'] = max( 0, $index['count'] - count( $rows ) );
            update_option(
                self::BACKUP_OPTION,
                [
                    'time'     => $index['time'],
                    'count'    => $index['count'],
                    'products' => $index['products'],
                    'chunks'   => $i,
                ],
                false
            );
        }

        delete_option( self::BACKUP_OPTION );
        delete_transient( self::FIX_CURSOR_TRANSIENT );

        if ( ! empty( $touched ) ) {
            $this->flush_product_caches( $touched );
        }

        // The images are listed again on the card the page reloads into.
        $this->refresh_after_change();

        // A product restored in one field and kept in another counts once, as kept.
        $skipped = array_diff_key( $skipped, $restored );

        $message = '';
        if ( ! empty( $skipped ) ) {
            $message = brikpanel_safe_sprintf(
                /* translators: %s: number of products. */
                _n(
                    '%s product was changed after the cleanup, so it was left as it is.',
                    '%s products were changed after the cleanup, so they were left as they are.',
                    count( $skipped ),
                    'brikpanel'
                ),
                brikpanel_number( count( $skipped ) )
            );
        }

        return [ 'restored' => count( $restored ), 'message' => $message ];
    }

    /**
     * @param array $outcome run_fix() result.
     * @return string
     */
    public function bc_fix_done( array $outcome ) {
        $products = (int) ( $outcome['removed'] ?? 0 );
        if ( $products < 1 ) {
            return __( 'No product lists a deleted image any more.', 'brikpanel' );
        }
        return brikpanel_safe_sprintf(
            /* translators: %s: number of products. */
            _n( 'Deleted images removed from %s product.', 'Deleted images removed from %s products.', $products, 'brikpanel' ),
            brikpanel_number( $products )
        );
    }

    /**
     * @param array $outcome run_undo() result.
     * @return string
     */
    public function bc_undo_done( array $outcome ) {
        $restored = (int) ( $outcome['restored'] ?? 0 );
        return brikpanel_safe_sprintf(
            /* translators: %s: number of products. */
            _n( 'Images put back on %s product.', 'Images put back on %s products.', $restored, 'brikpanel' ),
            brikpanel_number( $restored )
        );
    }

    /**
     * A field's value with the deleted images taken out. Everything else in
     * it stays exactly as stored, in its order.
     *
     * @param string $key     Meta key (see REF_KEYS).
     * @param mixed  $value   Stored value.
     * @param array  $deleted Image id => whether it is deleted.
     * @return mixed '' when nothing is left.
     */
    private function without_deleted( $key, $value, array $deleted ) {
        if ( '_product_image_gallery' === $key ) {
            if ( ! is_scalar( $value ) ) {
                return $value;
            }
            $kept = array_filter( explode( ',', (string) $value ), static function ( $token ) use ( $deleted ) {
                $id = (int) trim( $token );
                return ! ( $id > 0 && ! empty( $deleted[ $id ] ) );
            } );
            return implode( ',', $kept );
        }

        if ( '_brikpanel_variation_gallery' === $key ) {
            if ( ! is_array( $value ) ) {
                return $value;
            }
            $kept = array_values( array_filter( $value, static function ( $id ) use ( $deleted ) {
                $id = (int) $id;
                return ! ( $id > 0 && ! empty( $deleted[ $id ] ) );
            } ) );
            return empty( $kept ) ? '' : $kept;
        }

        // _thumbnail_id
        $id = (int) $value;
        return ( $id > 0 && ! empty( $deleted[ $id ] ) ) ? '' : $value;
    }

    /**
     * Number of image ids a field holds.
     *
     * @param string $key
     * @param mixed  $value
     * @return int
     */
    private function ids_in( $key, $value ) {
        return count( $this->field_ids( $key, $value ) );
    }

    /**
     * The image ids a field holds, in order, for comparing two values.
     *
     * @param string $key
     * @param mixed  $value
     * @return int[]
     */
    private function field_ids( $key, $value ) {
        if ( '_product_image_gallery' === $key ) {
            return $this->gallery_ids( $value );
        }
        if ( '_brikpanel_variation_gallery' === $key ) {
            return is_array( $value ) ? array_values( array_filter( array_map( 'intval', $value ) ) ) : [];
        }
        $id = is_scalar( $value ) ? (int) $value : 0;
        return $id > 0 ? [ $id ] : [];
    }

    /**
     * Whether two values of a field hold the same images in the same order.
     *
     * @param string $key
     * @param mixed  $a
     * @param mixed  $b
     * @return bool
     */
    private function same_value( $key, $a, $b ) {
        return $this->field_ids( $key, $a ) === $this->field_ids( $key, $b );
    }

    /**
     * Write a field the way its owner does: an empty gallery is stored as ''
     * (WooCommerce), an empty variation gallery and an empty featured image
     * are removed (BrikPanel's editor, WordPress).
     *
     * @param int    $post_id
     * @param string $key
     * @param mixed  $value
     * @return void
     */
    private function write_field( $post_id, $key, $value ) {
        $empty = ( '' === $value || [] === $value || null === $value );

        if ( '_product_image_gallery' === $key ) {
            update_post_meta( $post_id, $key, $empty ? '' : $value );
            return;
        }
        if ( $empty ) {
            delete_post_meta( $post_id, $key );
            return;
        }
        update_post_meta( $post_id, $key, $value );
    }

    /**
     * Drop every cache that could still show a changed product's old images.
     *
     * @param array $changes Rows with `id` (product or variation) and `p` (product).
     * @return void
     */
    private function flush_product_caches( array $changes ) {
        $parents = [];
        foreach ( $changes as $change ) {
            clean_post_cache( (int) $change['id'] );
            $parents[ (int) $change['p'] ] = true;
        }
        foreach ( array_keys( $parents ) as $parent ) {
            clean_post_cache( $parent );
            if ( function_exists( 'wc_delete_product_transients' ) ) {
                wc_delete_product_transients( $parent );
            }
        }
    }

    /**
     * Bring the stored card up to date after a cleanup or an undo, so the
     * page that reloads shows the products as they are now.
     *
     * The scan runner only ever rescans this (batched) check slice by slice
     * in the background. Here the scan runs in this request with its state in
     * memory; a store too big for REQUEST_BUDGET hands the remaining slices
     * to the runner, which continues from the same one (the page then shows
     * the progress bar and reloads when it is done). A scan already running
     * may have read some products before the change, so another one is
     * queued behind it instead.
     *
     * @return void
     */
    private function refresh_after_change() {
        if ( ! class_exists( 'Brikpanel_BrikControl_Storage' ) ) {
            return;
        }

        if ( Brikpanel_BrikControl_Storage::is_scan_active() ) {
            if ( class_exists( 'Brikpanel_BrikControl_Runner' ) ) {
                Brikpanel_BrikControl_Runner::trigger_manual_scan();
            }
            return;
        }

        $started = microtime( true );
        $size    = $this->batch_size();
        $partial = $this->start_partial();
        $cursor  = 0;

        while ( true ) {
            $count   = $this->scan_batch( $partial, $cursor, $size );
            $cursor += $count;

            if ( $count < $size ) {
                $result = $cursor > 0
                    ? $this->finalise( $partial, $started, false )
                    : $this->build_empty_result( $started );
                Brikpanel_BrikControl_Storage::save_check_result( $this->get_id(), $result );
                return;
            }

            wp_cache_flush_runtime();

            if ( microtime( true ) - $started > self::REQUEST_BUDGET && class_exists( 'Brikpanel_BrikControl_Runner' ) ) {
                $this->save_partial( $partial );
                Brikpanel_BrikControl_Runner::continue_check( $this->get_id(), $cursor, (int) $partial['total_products'] );
                return;
            }
        }
    }

    // =========================================================================
    // RESTORE POINT
    // =========================================================================

    /**
     * @return array{time:int,count:int,products:int,chunks:int}
     */
    private function read_backup_index() {
        $stored = get_option( self::BACKUP_OPTION, [] );

        if ( ! is_array( $stored ) ) {
            return [ 'time' => 0, 'count' => 0, 'products' => 0, 'chunks' => 0 ];
        }

        return [
            'time'     => isset( $stored['time'] ) ? (int) $stored['time'] : 0,
            'count'    => isset( $stored['count'] ) ? max( 0, (int) $stored['count'] ) : 0,
            'products' => isset( $stored['products'] ) ? max( 0, (int) $stored['products'] ) : 0,
            'chunks'   => isset( $stored['chunks'] ) ? max( 0, (int) $stored['chunks'] ) : 0,
        ];
    }

    /**
     * @param array $index read_backup_index().
     * @return bool
     */
    private function backup_expired( array $index ) {
        return $index['time'] > 0 && ( time() - $index['time'] ) > self::BACKUP_TTL;
    }

    /**
     * Drop a restore point that has outlived BACKUP_TTL.
     *
     * @return void
     */
    private function expire_backup() {
        $index = $this->read_backup_index();
        if ( $index['count'] > 0 && $this->backup_expired( $index ) ) {
            $this->purge_backup_chunks( $index['chunks'] );
            delete_option( self::BACKUP_OPTION );
        }
    }

    /**
     * Add changed values to the restore point, spilling into a new chunk when
     * full. The product count is what the undo question names; a product
     * cleaned in two separate runs counts twice, which only ever happens when
     * a new image was deleted between them.
     *
     * @param array $rows Changes: id, p, k, o (old value), n (written value).
     * @return void
     */
    private function append_backup( array $rows ) {
        $index = $this->read_backup_index();

        // Starting fresh: sweep chunks an interrupted undo may have left, so two
        // cleanups are never mixed into one undo.
        if ( $index['count'] < 1 ) {
            $this->purge_backup_chunks( $index['chunks'] );
            $index['chunks']   = 0;
            $index['products'] = 0;
        }

        $chunk_index = max( 0, $index['chunks'] - 1 );
        $current     = ( $index['chunks'] > 0 )
            ? (array) get_option( self::BACKUP_CHUNK_PREFIX . $chunk_index, [] )
            : [];

        $parents = [];
        foreach ( $rows as $row ) {
            if ( count( $current ) >= self::BACKUP_CHUNK_ROWS ) {
                // Never autoloaded: read only when the merchant undoes.
                update_option( self::BACKUP_CHUNK_PREFIX . $chunk_index, $current, false );
                $chunk_index++;
                $current = [];
            }
            $current[]                  = [
                'id' => (int) $row['id'],
                'p'  => (int) $row['p'],
                'k'  => (string) $row['k'],
                'o'  => $row['o'],
                'n'  => $row['n'],
            ];
            $parents[ (int) $row['p'] ] = true;
        }

        update_option( self::BACKUP_CHUNK_PREFIX . $chunk_index, $current, false );

        update_option(
            self::BACKUP_OPTION,
            [
                'time'     => time(),
                'count'    => $index['count'] + count( $rows ),
                'products' => $index['products'] + count( $parents ),
                'chunks'   => $chunk_index + 1,
            ],
            false
        );
    }

    /**
     * Delete leftover restore-point chunks.
     *
     * @param int $known How many chunks the index claimed.
     * @return void
     */
    private function purge_backup_chunks( $known ) {
        global $wpdb;

        for ( $i = 0; $i < max( 0, (int) $known ); $i++ ) {
            delete_option( self::BACKUP_CHUNK_PREFIX . $i );
        }

        $stale = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( self::BACKUP_CHUNK_PREFIX ) . '%'
            )
        );

        foreach ( (array) $stale as $name ) {
            delete_option( $name );
        }
    }
}
