<?php
/**
 * BrikPanel — BrikControl Image Health Check
 *
 * Walks every published / private product (including variations) and looks
 * at the store's product imagery on three axes:
 *  - oversize  : how many product images are larger than 1 MB.
 *  - missing   : how many point to a file that is no longer on disk.
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
 * Storage shape inside `facts` (schema 3; schema 2 had the same keys but its
 * status and score also graded the WebP / AVIF share; results written before
 * 3.3.25 kept the same keys under `metadata`, next to sentences in the scan's
 * language):
 *   {
 *     no_products?: true,
 *     totals: { products, attachments, oversized, webp_avif, legacy, missing_files },
 *     percentages: { oversized_pct, modern_pct, missing_pct },
 *     thresholds: { oversize_bytes, warn_oversized, crit_oversized, warn_modern, crit_modern },
 *     largest: [ { id, post_id, bytes, size_mb, mime } ... ],
 *     plugins: { active: { slug => label }, recommendations: [ { slug, label, search } ... ] }
 *   }
 *
 * Hem basit hem variable ürünler taranır:
 *   - Featured image
 *   - _product_image_gallery (CSV)
 *   - Variation thumbnails (variable ürünler için)
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
     * Figures only; the card writes the sentences (see bc_present()).
     *
     * 3: status and score come from oversized and missing images only; a
     *    stored schema 2 result still graded the WebP / AVIF share, so it is
     *    rescanned once (Storage::maybe_heal()).
     *
     * @return int
     */
    public function bc_schema() {
        return 3;
    }

    /**
     * Run a single batch slice. The runner passes the current cursor and
     * we own the partial-state option so we can resume where we left off.
     *
     * @param array $state { cursor?: int, total?: int }
     * @return array CheckResult with batch_state populated.
     */
    public function run( array $state = [] ) {
        $started = microtime( true );
        $cursor  = isset( $state['cursor'] ) ? max( 0, (int) $state['cursor'] ) : 0;

        $batch_size = (int) apply_filters( 'brikpanel_brikcontrol_image_batch_size', $this->get_batch_size() );

        // Resume partial state when continuing a multi-batch scan.
        $partial = $cursor === 0 ? $this->fresh_partial() : $this->load_partial();
        if ( $cursor === 0 ) {
            $partial['total_products']   = $this->count_products();
            // Snapshot at scan start so every batch in this run uses the same
            // optimizer-active flag — a plugin deactivation mid-scan won't
            // make some attachments count as "modern via sidecar" and others
            // not. The flag is what gates sidecar credit (see
            // score_attachment): without an active optimizer, sidecar files
            // sitting on disk no longer translate into served WebP because
            // the plugin's .htaccess / <picture> rewrite is gone.
            $partial['optimizer_active'] = Brikpanel_BrikControl_Image_Plugins::any_active();
            $this->save_partial( $partial );
        }

        $product_ids = $this->fetch_product_ids( $cursor, $batch_size );

        if ( empty( $product_ids ) && $cursor > 0 ) {
            // Cursor overshoot — finalise.
            return $this->finalise( $partial, $started );
        }

        if ( empty( $product_ids ) && $cursor === 0 ) {
            // Empty store — produce a friendly "ok" result and clear partial.
            $this->clear_partial();
            return $this->build_empty_result( $started );
        }

        // Collect attachment IDs for this batch (deduped against the running
        // seen_attachments set so the same gallery image shared between
        // products is only weighed once).
        $seen      =& $partial['seen_attachments'];
        $batch_ids = [];
        foreach ( $product_ids as $pid ) {
            foreach ( $this->collect_product_attachment_ids( $pid ) as $att_id ) {
                if ( $att_id <= 0 || isset( $seen[ $att_id ] ) ) {
                    continue;
                }
                $seen[ $att_id ] = $pid;
                $batch_ids[]    = $att_id;
            }
        }

        $threshold = (int) apply_filters( 'brikpanel_brikcontrol_image_threshold_bytes', self::DEFAULT_THRESHOLD_BYTES );

        foreach ( $batch_ids as $att_id ) {
            $pid = $seen[ $att_id ];
            $this->score_attachment( $att_id, $pid, $threshold, $partial );
        }

        $partial['products_scanned'] = $cursor + count( $product_ids );

        // Decide if more batches needed.
        $done = ( count( $product_ids ) < $batch_size );
        if ( $done ) {
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
            'cursor' => $cursor + count( $product_ids ),
            'total'  => $partial['total_products'],
            'done'   => false,
        ];
        return $result;
    }

    // =========================================================================
    // ATTACHMENT COLLECTION — covers simple + variable products
    // =========================================================================

    /**
     * Featured + gallery + (for variable products) every variation thumbnail.
     *
     * @param int $product_id
     * @return int[]
     */
    private function collect_product_attachment_ids( $product_id ) {
        $ids = [];

        $featured = (int) get_post_thumbnail_id( $product_id );
        if ( $featured > 0 ) {
            $ids[] = $featured;
        }

        $gallery = get_post_meta( $product_id, '_product_image_gallery', true );
        if ( ! empty( $gallery ) ) {
            foreach ( explode( ',', (string) $gallery ) as $g ) {
                $g = (int) trim( $g );
                if ( $g > 0 ) {
                    $ids[] = $g;
                }
            }
        }

        // Variations — only the variable type has children worth walking.
        $product_type = $this->get_product_type( $product_id );
        if ( $product_type === 'variable' && function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $product_id );
            if ( $product && method_exists( $product, 'get_children' ) ) {
                foreach ( $product->get_children() as $variation_id ) {
                    $vid = (int) get_post_thumbnail_id( $variation_id );
                    if ( $vid > 0 ) {
                        $ids[] = $vid;
                    }
                }
            }
        }

        return $ids;
    }

    /**
     * Reads product type from the term cache without instantiating wc_get_product.
     * Falls back to the WC helper when needed.
     *
     * @param int $product_id
     * @return string
     */
    private function get_product_type( $product_id ) {
        $terms = wp_get_object_terms( $product_id, 'product_type', [ 'fields' => 'names' ] );
        if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
            return (string) $terms[0];
        }
        return 'simple';
    }

    /**
     * @param int $att_id
     * @param int $product_id Product the attachment first appeared under.
     * @param int $threshold  Oversize threshold in bytes.
     * @param array $partial  Mutated by reference via the caller.
     */
    private function score_attachment( $att_id, $product_id, $threshold, array &$partial ) {
        $partial['totals']['attachments']++;

        $path = get_attached_file( $att_id, true );
        $size = $this->resolve_filesize( $att_id, $path );
        $mime = (string) get_post_mime_type( $att_id );

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
            && $this->has_webp_sidecar( $path );

        if ( $is_native_modern || $has_modern_sidecar ) {
            $partial['totals']['webp_avif']++;
            if ( $has_modern_sidecar ) {
                $partial['totals']['webp_via_sidecar']++;
            }
        } elseif ( $mime !== '' && strpos( $mime, 'image/' ) === 0 ) {
            $partial['totals']['legacy']++;
        }

        if ( $size === false ) {
            $partial['totals']['missing_files']++;
            return;
        }

        if ( $size >= $threshold ) {
            $partial['totals']['oversized']++;
            $this->maybe_record_largest( $att_id, $product_id, $size, $mime, $partial );
        }
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
     *   1. Local file via get_attached_file() + filesize().
     *   2. WP-stored attachment metadata (`filesize`) — covers offload plugins
     *      that strip the file off the local FS but still record metadata.
     *   3. False — surfaced as missing_files.
     *
     * @param int          $att_id
     * @param string|false $path Pre-resolved attached path; pass false to
     *                           force the lookup. Avoids reading the file
     *                           path twice when score_attachment() already
     *                           has it.
     * @return int|false
     */
    private function resolve_filesize( $att_id, $path = false ) {
        if ( $path === false ) {
            $path = get_attached_file( $att_id, true );
        }
        if ( $path && @file_exists( $path ) ) {
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
            ],
            'largest'          => [],
            'seen_attachments' => [], // att_id => product_id
            'started_at'       => time(),
        ];
    }

    private function save_partial( array $partial ) {
        update_option( self::PARTIAL_OPTION, $partial, false );
    }

    private function load_partial() {
        $stored = get_option( self::PARTIAL_OPTION, null );
        return is_array( $stored ) ? wp_parse_args( $stored, $this->fresh_partial() ) : $this->fresh_partial();
    }

    private function clear_partial() {
        delete_option( self::PARTIAL_OPTION );
    }


    private function finalise( array $partial, $started_at ) {
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

        $this->clear_partial();
        return $result;
    }

    private function build_empty_result( $started_at ) {
        $result                = $this->make_result_skeleton();
        $result['status']      = 'ok';
        $result['facts']       = [
            'no_products' => true,
            'totals'      => [
                'attachments'   => 0,
                'oversized'     => 0,
                'webp_avif'     => 0,
                'legacy'        => 0,
                'missing_files' => 0,
                'products'      => 0,
            ],
            'percentages' => [ 'oversized_pct' => 0, 'modern_pct' => 0, 'missing_pct' => 0 ],
            'largest'     => [],
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
            $recs[] = [
                'text'     => brikpanel_safe_sprintf(
                    /* translators: %s: number of product images. */
                    _n(
                        '%s product image points to a file that no longer exists on disk. Upload it again or remove it from the product.',
                        '%s product images point to files that no longer exist on disk. Upload them again or remove them from their products.',
                        $missing,
                        'brikpanel'
                    ),
                    brikpanel_number( $missing )
                ),
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

        $r['recommendations'] = $recs;
        $r['metadata']        = [
            'stats'   => $stats,
            'largest' => isset( $f['largest'] ) && is_array( $f['largest'] ) ? $f['largest'] : [],
            'plugins' => [ 'active' => $active ],
        ];

        return $r;
    }
}
