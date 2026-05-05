<?php
/**
 * BrikPanel — BrikControl Image Health Check
 *
 * Walks every published / private product (including variations) and scores
 * the store's product imagery on two axes:
 *  - oversize  : how many product images are larger than 1 MB.
 *  - modernity : what share of product images are served as WebP / AVIF.
 *
 * Both signals predict perceived load speed on product pages, which Google
 * weighs heavily for e-commerce SEO and which directly correlates with
 * conversion. The check ships recommendations (and a "no optimizer found"
 * critical flag) so the user knows what to install — BrikPanel intentionally
 * does NOT do the conversion itself.
 *
 * Batching: products → 200 per batch. The cursor counts product offsets, not
 * attachment offsets, because the unique attachment set is computed per
 * product (avoiding cross-batch deduplication state).
 *
 * Storage shape inside metadata:
 *   {
 *     totals: { products, attachments, oversized, webp_avif, legacy, missing_files },
 *     percentages: { oversized_pct, modern_pct, missing_pct },
 *     largest: [ { id, post_id, product_title, edit_url, size_mb, mime } ... ],
 *     plugins: { active: { slug => label }, recommendations: [ ... ] }
 *   }
 *
 * Hem basit hem variable ürünler taranır:
 *   - Featured image
 *   - _product_image_gallery (CSV)
 *   - Variation thumbnails (variable ürünler için)
 *
 * @package BrikPanel
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_BrikControl_Image_Health_Check extends Brikpanel_BrikControl_Check {

    const PARTIAL_OPTION = 'brikpanel_brikcontrol_image_partial';
    const DEFAULT_THRESHOLD_BYTES   = 1048576;   // 1 MB
    const DEFAULT_MODERN_THRESHOLD  = 60;        // % WebP/AVIF needed for "ok"
    const DEFAULT_WARN_OVERSIZED_PCT = 10;
    const DEFAULT_CRIT_OVERSIZED_PCT = 25;
    const DEFAULT_WARN_MODERN_PCT    = 60;
    const DEFAULT_CRIT_MODERN_PCT    = 20;
    const LARGEST_KEEP               = 10;

    public function get_id() {
        return 'image_health';
    }

    public function get_label() {
        return __( 'Product Image Health', 'brikpanel' );
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
            $partial['total_products'] = $this->count_products();
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

        $result = $this->make_result_skeleton();
        $result['status']      = 'unknown';
        $result['summary']     = sprintf(
            /* translators: 1: scanned products, 2: total products */
            __( 'Scanning… %1$s / %2$s products', 'brikpanel' ),
            number_format_i18n( $partial['products_scanned'] ),
            number_format_i18n( $partial['total_products'] )
        );
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

        $size = $this->resolve_filesize( $att_id );
        $mime = (string) get_post_mime_type( $att_id );

        // MIME bucket
        if ( $mime === 'image/webp' || $mime === 'image/avif' ) {
            $partial['totals']['webp_avif']++;
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
     * Lookup order:
     *   1. Local file via get_attached_file() + filesize().
     *   2. WP-stored attachment metadata (`filesize`) — covers offload plugins
     *      that strip the file off the local FS but still record metadata.
     *   3. False — surfaced as missing_files.
     *
     * @param int $att_id
     * @return int|false
     */
    private function resolve_filesize( $att_id ) {
        $path = get_attached_file( $att_id, true );
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

        $entry = [
            'id'         => $att_id,
            'post_id'    => $product_id,
            'bytes'      => (int) $size,
            'size_mb'    => round( $size / 1048576, 2 ),
            'mime'       => $mime,
            'edit_url'   => get_edit_post_link( $product_id, 'raw' ),
            'media_url'  => admin_url( 'post.php?post=' . $att_id . '&action=edit' ),
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
            'totals'           => [
                'products'      => 0,
                'attachments'   => 0,
                'oversized'     => 0,
                'webp_avif'     => 0,
                'legacy'        => 0,
                'missing_files' => 0,
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
        $crit_mod  = (float) apply_filters( 'brikpanel_brikcontrol_image_critical_modern_pct', self::DEFAULT_CRIT_MODERN_PCT );
        $warn_mod  = (float) apply_filters( 'brikpanel_brikcontrol_image_warning_modern_pct', self::DEFAULT_WARN_MODERN_PCT );

        // Status
        $status = 'ok';
        if ( $oversized_pct >= $crit_over || $modern_pct < $crit_mod || $missing_pct >= 5 ) {
            $status = 'critical';
        } elseif ( $oversized_pct >= $warn_over || $modern_pct < $warn_mod ) {
            $status = 'warning';
        }

        // Composite score (0-100). Heavier weight on oversize since it's the
        // metric most directly tied to bandwidth/LCP.
        $oversize_score = max( 0, 100 - ( $oversized_pct * 4 ) );
        $modern_score   = min( 100, $modern_pct + 20 );
        $score          = (int) round( ( $oversize_score * 0.6 ) + ( $modern_score * 0.4 ) );

        // Summary line
        if ( $attachments === 0 ) {
            $summary = __( 'No product images found yet.', 'brikpanel' );
        } else {
            $summary = sprintf(
                /* translators: 1: oversize percent, 2: oversize count, 3: total attachments, 4: modern format percent */
                __( '%1$s%% oversized (%2$s of %3$s) · %4$s%% modern format', 'brikpanel' ),
                number_format_i18n( $oversized_pct, 1 ),
                number_format_i18n( $oversized ),
                number_format_i18n( $attachments ),
                number_format_i18n( $modern_pct, 1 )
            );
        }

        // Plugin context
        $active_plugins = Brikpanel_BrikControl_Image_Plugins::get_active();
        $any_active     = ! empty( $active_plugins );
        $recommended    = $any_active ? [] : Brikpanel_BrikControl_Image_Plugins::get_recommendations();

        // Recommendations
        $recommendations = $this->build_recommendations(
            $status,
            $oversized,
            $oversized_pct,
            $modern_pct,
            $missing,
            $any_active,
            $recommended
        );

        $message = $attachments === 0
            ? __( 'Add product images to start the health check.', 'brikpanel' )
            : sprintf(
                /* translators: 1: oversized count, 2: modern format percent, 3: missing files */
                __( '%1$s product images are larger than 1 MB. Modern formats (WebP / AVIF) cover %2$s%% of your library. Missing files: %3$s.', 'brikpanel' ),
                number_format_i18n( $oversized ),
                number_format_i18n( $modern_pct, 1 ),
                number_format_i18n( $missing )
            );

        $result                  = $this->make_result_skeleton();
        $result['status']        = $status;
        $result['score']         = max( 0, min( 100, $score ) );
        $result['summary']       = $summary;
        $result['message']       = $message;
        $result['recommendations'] = $recommendations;
        $result['metadata']      = [
            'totals'      => [
                'attachments'   => $attachments,
                'oversized'     => $oversized,
                'webp_avif'     => $modern,
                'legacy'        => (int) $totals['legacy'],
                'missing_files' => $missing,
                'products'      => (int) $partial['products_scanned'],
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
                'recommendations' => $recommended,
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
        $result['score']       = 100;
        $result['summary']     = __( 'No products yet — nothing to check.', 'brikpanel' );
        $result['message']     = __( 'Add at least one published product to enable image health checks.', 'brikpanel' );
        $result['metadata']    = [
            'totals' => [
                'attachments'   => 0,
                'oversized'     => 0,
                'webp_avif'     => 0,
                'legacy'        => 0,
                'missing_files' => 0,
                'products'      => 0,
            ],
            'percentages' => [ 'oversized_pct' => 0, 'modern_pct' => 0, 'missing_pct' => 0 ],
            'largest'  => [],
            'plugins'  => [
                'active'          => Brikpanel_BrikControl_Image_Plugins::get_active(),
                'recommendations' => [],
            ],
        ];
        $result['duration_ms'] = (int) round( ( microtime( true ) - $started_at ) * 1000 );
        $result['batch_state'] = [ 'cursor' => 0, 'total' => 0, 'done' => true ];
        return $result;
    }

    /**
     * @return array<int, array{text:string, priority:string, link?:array{url:string,label:string}}>
     */
    private function build_recommendations(
        $status,
        $oversized,
        $oversized_pct,
        $modern_pct,
        $missing,
        $optimizer_active,
        array $recommended_plugins
    ) {
        $out = [];

        if ( ! $optimizer_active && $status !== 'ok' ) {
            // Top-line: install an optimiser. We pick the first 3 from the
            // catalogue and emit a recommendation per plugin so users can
            // pick the one that matches their stack. URL is left empty here
            // — the renderer resolves it per viewer via
            // Brikpanel_BrikControl_Image_Plugins::resolve_install_url(),
            // because scans run inside AS workers where current_user_can()
            // is always false and we want the in-admin install link for
            // capable viewers.
            foreach ( $recommended_plugins as $plugin ) {
                $out[] = [
                    'text'     => sprintf(
                        /* translators: %s: plugin name */
                        __( 'Install %s to compress images and convert to WebP/AVIF automatically.', 'brikpanel' ),
                        $plugin['label']
                    ),
                    'priority' => 'high',
                    'link'     => [
                        'plugin_slug'   => $plugin['slug'],
                        'plugin_search' => $plugin['search'],
                        'label'         => __( 'Install plugin', 'brikpanel' ),
                    ],
                ];
            }
        }

        if ( $oversized > 0 ) {
            $out[] = [
                'text'     => sprintf(
                    /* translators: 1: count, 2: percent */
                    __( '%1$s product images (%2$s%%) are larger than 1 MB. These slow down your product pages and hurt SEO. Compress them to under 200 KB where possible.', 'brikpanel' ),
                    number_format_i18n( $oversized ),
                    number_format_i18n( $oversized_pct, 1 )
                ),
                'priority' => $oversized_pct >= 25 ? 'high' : 'medium',
            ];
        }

        if ( $modern_pct < 60 ) {
            $out[] = [
                'text'     => sprintf(
                    /* translators: %s: modern format percent */
                    __( 'Only %s%% of your product images use WebP / AVIF. Modern formats are 30–50%% smaller than JPEG/PNG with no visible quality loss.', 'brikpanel' ),
                    number_format_i18n( $modern_pct, 1 )
                ),
                'priority' => $modern_pct < 20 ? 'high' : 'medium',
            ];
        }

        if ( $missing > 0 ) {
            $out[] = [
                'text'     => sprintf(
                    /* translators: %s: missing file count */
                    __( '%s product images point to files that no longer exist on disk. Re-upload them or detach the missing media references.', 'brikpanel' ),
                    number_format_i18n( $missing )
                ),
                'priority' => 'high',
            ];
        }

        if ( empty( $out ) ) {
            $out[] = [
                'text'     => __( 'Your product images are well-optimised. Keep an eye on this metric as you upload new products.', 'brikpanel' ),
                'priority' => 'low',
            ];
        }

        return $out;
    }
}
