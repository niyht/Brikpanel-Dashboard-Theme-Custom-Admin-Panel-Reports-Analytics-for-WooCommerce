<?php
/**
 * BrikPanel Developer Hooks API
 *
 * Exposes a small set of curated actions and filters so third parties can
 * extend the BrikPanel product editor, settings, and save flow without
 * patching the plugin. Also renders a self-contained documentation modal
 * inside the BrikPanel settings tab.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render developer-registered cards at a given position inside the
 * BrikPanel product editor.
 *
 * Position identifiers: 'top', 'middle', 'bottom'.
 *
 * Boxes are registered via the `brikpanel_product_editor_boxes` filter.
 * Each box must be an array with:
 *   - id       (string) Unique slug, used for the wrapper element id.
 *   - title    (string) Optional heading text (rendered in a <label>).
 *   - position (string) 'top' | 'middle' | 'bottom' (default 'middle').
 *   - priority (int)    Ordering within a position (default 10).
 *   - callback (callable) Receives ($product_id, $product, $position).
 *
 * @param string          $position   Render slot.
 * @param int             $product_id Product ID (0 for new).
 * @param WC_Product|null $product    Product object or null.
 */
function brikpanel_render_editor_boxes( $position, $product_id, $product ) {
    $boxes = apply_filters( 'brikpanel_product_editor_boxes', [], (int) $product_id, $product );
    if ( ! is_array( $boxes ) || empty( $boxes ) ) {
        return;
    }

    $filtered = [];
    foreach ( $boxes as $box ) {
        if ( ! is_array( $box ) || empty( $box['callback'] ) || ! is_callable( $box['callback'] ) ) {
            continue;
        }
        $pos = isset( $box['position'] ) ? (string) $box['position'] : 'middle';
        if ( $pos !== $position ) {
            continue;
        }
        $filtered[] = [
            'id'       => isset( $box['id'] ) ? sanitize_html_class( (string) $box['id'] ) : 'brikpanel-pe-ext-' . wp_generate_uuid4(),
            'title'    => isset( $box['title'] ) ? (string) $box['title'] : '',
            'priority' => isset( $box['priority'] ) ? (int) $box['priority'] : 10,
            'callback' => $box['callback'],
        ];
    }

    if ( empty( $filtered ) ) {
        return;
    }

    usort( $filtered, static function ( $a, $b ) {
        return $a['priority'] <=> $b['priority'];
    } );

    // When ACF is active, make sure its hidden form-data block (nonce,
    // post_id, …) is on the page exactly once. Developers commonly render
    // ACF fields inside their box via acf_render_fields(); without this block
    // ACF's save_post handler can't verify its nonce and silently drops the
    // whole $_POST['acf'] payload. The JS save collector forwards both the
    // ACF inputs and this block to the BrikPanel save endpoint.
    brikpanel_pe_emit_acf_form_data( $product_id );

    foreach ( $filtered as $box ) {
        echo '<div class="brikpanel-pe-card brikpanel-pe-ext-card" id="' . esc_attr( $box['id'] ) . '" data-bpe-position="' . esc_attr( $position ) . '">';
        if ( $box['title'] !== '' ) {
            echo '<label class="brikpanel-pe-ext-title">' . esc_html( $box['title'] ) . '</label>';
        }
        echo '<div class="brikpanel-pe-ext-body">';
        try {
            call_user_func( $box['callback'], (int) $product_id, $product, $position );
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                echo '<p class="brikpanel-pe-help-text">' . esc_html( $e->getMessage() ) . '</p>';
            }
        }
        echo '</div></div>';
    }
}

/**
 * Emit ACF's hidden form-data block (`#acf-form-data`: _acf_nonce, _acf_post_id,
 * _acf_screen, …) at most once per request, only when ACF is active.
 *
 * ACF's native `save_post` handler bails unless `acf_verify_nonce('post')`
 * passes, which needs the `_acf_nonce` produced here. The BrikPanel editor
 * fires `save_post` / `save_post_product` on save, and the JS payload forwards
 * this block alongside the developer box inputs, so ACF fields rendered inside
 * a `brikpanel_product_editor_boxes` card persist with no extra glue code.
 *
 * Guarded with a static so it is never printed twice (which would create
 * duplicate hidden-input ids), regardless of how many editor boxes render or
 * whether the native metaboxes card also requests it.
 *
 * @param int $product_id Product being edited (0 for new).
 */
function brikpanel_pe_emit_acf_form_data( $product_id ) {
    static $done = false;
    if ( $done || ! function_exists( 'acf_form_data' ) ) {
        return;
    }
    $done = true;
    acf_form_data( [
        'screen'  => 'post',
        'post_id' => (int) $product_id,
    ] );
}

/**
 * Catalogue of public developer hooks, used to build the docs modal.
 *
 * Each entry: [ name, type (action|filter), signature, description, example ].
 *
 * @return array<int, array<string, string>>
 */
function brikpanel_get_developer_hooks() {
    return [
        [
            'name'        => 'brikpanel_before_product_save',
            'type'        => 'action',
            'signature'   => 'do_action( "brikpanel_before_product_save", int $product_id, array $post_data )',
            'description' => __( 'Fires right before the BrikPanel simplified editor persists a product. Use it to validate or pre-process submitted data. Runs for both simple and variable products.', 'brikpanel' ),
            'example'     => "add_action( 'brikpanel_before_product_save', function ( \$product_id, \$post_data ) {\n    // e.g. enforce a minimum price before save\n    if ( isset( \$post_data['regular_price'] ) && (float) \$post_data['regular_price'] < 1 ) {\n        wp_send_json_error( [ 'message' => 'Price too low.' ] );\n    }\n}, 10, 2 );",
        ],
        [
            'name'        => 'brikpanel_after_product_save',
            'type'        => 'action',
            'signature'   => 'do_action( "brikpanel_after_product_save", int $product_id, WC_Product $product, array $post_data )',
            'description' => __( 'Fires after a product has been fully saved through the BrikPanel editor, including variations. Ideal for syncing to external systems, audit logs, or cache invalidation.', 'brikpanel' ),
            'example'     => "add_action( 'brikpanel_after_product_save', function ( \$product_id, \$product, \$post_data ) {\n    // e.g. push product to an external ERP\n    my_erp_sync_product( \$product_id, \$product->get_name() );\n}, 10, 3 );",
        ],
        [
            'name'        => 'brikpanel_product_editor_boxes',
            'type'        => 'filter',
            'signature'   => 'apply_filters( "brikpanel_product_editor_boxes", array $boxes, int $product_id, ?WC_Product $product )',
            'description' => __( 'Register custom cards inside the BrikPanel product editor. Each box renders as a native BrikPanel card at the chosen position (top, middle, bottom) and is sorted by priority.', 'brikpanel' ),
            'example'     => "add_filter( 'brikpanel_product_editor_boxes', function ( \$boxes, \$product_id, \$product ) {\n    \$boxes[] = [\n        'id'       => 'my-notes-card',\n        'title'    => __( 'Internal notes', 'my-plugin' ),\n        'position' => 'bottom',\n        'priority' => 20,\n        'callback' => function ( \$product_id, \$product ) {\n            \$notes = get_post_meta( \$product_id, '_my_notes', true );\n            echo '<textarea name=\"my_notes\" rows=\"4\" style=\"width:100%\">' . esc_textarea( \$notes ) . '</textarea>';\n        },\n    ];\n    return \$boxes;\n}, 10, 3 );",
        ],
        [
            'name'        => 'brikpanel_editor_visible_sections',
            'type'        => 'filter',
            'signature'   => 'apply_filters( "brikpanel_editor_visible_sections", array $sections, int $product_id )',
            'description' => __( 'Filter which built-in BrikPanel editor sections are rendered. Useful for hiding sections programmatically (e.g. for specific user roles or product types).', 'brikpanel' ),
            'example'     => "add_filter( 'brikpanel_editor_visible_sections', function ( \$sections, \$product_id ) {\n    if ( ! current_user_can( 'manage_woocommerce' ) ) {\n        \$sections = array_diff( \$sections, [ 'cogs', 'seo' ] );\n    }\n    return \$sections;\n}, 10, 2 );",
        ],
        [
            'name'        => 'brikpanel_settings_fields',
            'type'        => 'filter',
            'signature'   => 'apply_filters( "brikpanel_settings_fields", array $fields )',
            'description' => __( 'Filter the full list of BrikPanel settings fields rendered on the WooCommerce → Settings → BrikPanel tab. Lets you append your own WC settings fields (sections, checkboxes, selects…) to the tab.', 'brikpanel' ),
            'example'     => "add_filter( 'brikpanel_settings_fields', function ( \$fields ) {\n    \$fields[] = [\n        'name' => __( 'My integration', 'my-plugin' ),\n        'type' => 'title',\n        'id'   => 'my_integration_title',\n    ];\n    \$fields[] = [\n        'name'    => __( 'Enable sync', 'my-plugin' ),\n        'id'      => 'my_integration_enabled',\n        'type'    => 'checkbox',\n        'default' => 'no',\n    ];\n    \$fields[] = [ 'type' => 'sectionend', 'id' => 'my_integration_title' ];\n    return \$fields;\n} );",
        ],
        [
            'name'        => 'brikpanel_products_columns',
            'type'        => 'filter',
            'signature'   => 'apply_filters( "brikpanel_products_columns", array $columns, int $user_id )',
            'description' => __( 'Register additional columns in the BrikPanel modern products list. Each column definition accepts an id, label, width and optional render callback.', 'brikpanel' ),
            'example'     => "add_filter( 'brikpanel_products_columns', function ( \$cols, \$user_id ) {\n    \$cols['vendor'] = [\n        'label'  => __( 'Vendor', 'my-plugin' ),\n        'width'  => 120,\n        'render' => function ( \$product ) {\n            return esc_html( get_post_meta( \$product->get_id(), '_vendor', true ) );\n        },\n    ];\n    return \$cols;\n}, 10, 2 );",
        ],
        [
            'name'        => 'brikpanel_bulk_batch_size',
            'type'        => 'filter',
            'signature'   => 'apply_filters( "brikpanel_bulk_batch_size", int $size, string $job_type )',
            'description' => __( 'Tune how many products BrikPanel processes per batch during bulk operations. Lower for shared hosting, higher on beefy servers.', 'brikpanel' ),
            'example'     => "add_filter( 'brikpanel_bulk_batch_size', function ( \$size, \$job_type ) {\n    return \$job_type === 'delete' ? 25 : 50;\n}, 10, 2 );",
        ],
        [
            'name'        => 'brikpanel_pe_active_seo_plugin',
            'type'        => 'filter',
            'signature'   => 'apply_filters( "brikpanel_pe_active_seo_plugin", array|null $detected )',
            'description' => __( 'Override which SEO plugin BrikPanel surfaces inside the product editor. Return an array with keys slug, label, metabox_ids, or null to disable integration.', 'brikpanel' ),
            'example'     => "add_filter( 'brikpanel_pe_active_seo_plugin', function ( \$detected ) {\n    // Force Rank Math even if another plugin is also active\n    return [\n        'slug'        => 'rank-math',\n        'label'       => 'Rank Math',\n        'metabox_ids' => [ 'rank_math_metabox' ],\n    ];\n} );",
        ],
    ];
}

/**
 * Output the developer-docs settings row: a small card with a button that
 * opens a modal listing every public BrikPanel hook, with copyable examples.
 *
 * Hooked to WC's custom field-type dispatcher via
 * `woocommerce_admin_field_brikpanel_dev_docs`.
 *
 * @param array $field Settings field definition.
 */
function brikpanel_render_dev_docs_field( $field ) {
    $hooks = brikpanel_get_developer_hooks();
    ?>
    <tr valign="top" class="brikpanel-dev-docs-row">
        <th scope="row" class="titledesc">
            <label><?php esc_html_e( 'Developer documentation', 'brikpanel' ); ?></label>
        </th>
        <td class="forminp">
            <p class="brikpanel-dev-docs-intro">
                <?php esc_html_e( 'BrikPanel exposes hooks and filters so you can extend the product editor, settings and save flow without modifying the plugin.', 'brikpanel' ); ?>
            </p>
            <button type="button" class="brikpanel-dev-docs-open-btn" id="brikpanel-dev-docs-open">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
                <?php esc_html_e( 'Open developer docs', 'brikpanel' ); ?>
            </button>
        </td>
    </tr>
    <?php
    // Modal markup + data payload. Rendered once at the end of the tab.
    add_action( 'admin_footer', 'brikpanel_render_dev_docs_modal' );
    // Stash hooks for the footer callback via a static shared variable.
    brikpanel_dev_docs_modal_payload( $hooks );
}

/**
 * Stash-and-retrieve the hooks payload for the footer modal renderer.
 * Static storage keeps the data inside a single request without polluting
 * global namespace.
 *
 * @param array|null $set  Pass non-null to store, null to read.
 * @return array
 */
function brikpanel_dev_docs_modal_payload( $set = null ) {
    static $payload = [];
    if ( $set !== null ) {
        $payload = $set;
    }
    return $payload;
}

/**
 * Render the developer-docs modal, CSS and interaction script.
 * Runs once via admin_footer on the BrikPanel settings tab.
 */
function brikpanel_render_dev_docs_modal() {
    static $rendered = false;
    if ( $rendered ) {
        return;
    }
    $rendered = true;

    $hooks = brikpanel_dev_docs_modal_payload();
    if ( empty( $hooks ) ) {
        return;
    }
    ?>
    <div class="brikpanel-dev-docs-overlay" id="brikpanel-dev-docs-overlay" aria-hidden="true">
        <div class="brikpanel-dev-docs-modal" role="dialog" aria-modal="true" aria-labelledby="brikpanel-dev-docs-title">
            <div class="brikpanel-dev-docs-header">
                <div class="brikpanel-dev-docs-header-text">
                    <h2 id="brikpanel-dev-docs-title"><?php esc_html_e( 'BrikPanel developer hooks', 'brikpanel' ); ?></h2>
                    <p><?php esc_html_e( 'Actions and filters you can use from a plugin or your theme\'s functions.php.', 'brikpanel' ); ?></p>
                </div>
                <button type="button" class="brikpanel-dev-docs-close" id="brikpanel-dev-docs-close" aria-label="<?php esc_attr_e( 'Close', 'brikpanel' ); ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="brikpanel-dev-docs-search-wrap">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="search" id="brikpanel-dev-docs-search" placeholder="<?php esc_attr_e( 'Search hooks…', 'brikpanel' ); ?>" autocomplete="off">
            </div>
            <div class="brikpanel-dev-docs-body" id="brikpanel-dev-docs-body">
                <?php foreach ( $hooks as $hook ) : ?>
                    <?php
                    $type_label = $hook['type'] === 'action'
                        ? __( 'Action', 'brikpanel' )
                        : __( 'Filter', 'brikpanel' );
                    ?>
                    <article class="brikpanel-dev-docs-item" data-hook-name="<?php echo esc_attr( strtolower( $hook['name'] . ' ' . $hook['description'] ) ); ?>">
                        <header class="brikpanel-dev-docs-item-head">
                            <div class="brikpanel-dev-docs-item-title">
                                <span class="brikpanel-dev-docs-badge brikpanel-dev-docs-badge--<?php echo esc_attr( $hook['type'] ); ?>"><?php echo esc_html( $type_label ); ?></span>
                                <code class="brikpanel-dev-docs-name"><?php echo esc_html( $hook['name'] ); ?></code>
                            </div>
                            <button type="button" class="brikpanel-dev-docs-copy-name" data-copy="<?php echo esc_attr( $hook['name'] ); ?>">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                <span><?php esc_html_e( 'Copy name', 'brikpanel' ); ?></span>
                            </button>
                        </header>
                        <p class="brikpanel-dev-docs-desc"><?php echo esc_html( $hook['description'] ); ?></p>
                        <div class="brikpanel-dev-docs-sig">
                            <code><?php echo esc_html( $hook['signature'] ); ?></code>
                        </div>
                        <div class="brikpanel-dev-docs-example">
                            <div class="brikpanel-dev-docs-example-head">
                                <span><?php esc_html_e( 'Example', 'brikpanel' ); ?></span>
                                <button type="button" class="brikpanel-dev-docs-copy-example" data-copy="<?php echo esc_attr( $hook['example'] ); ?>">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                    <span><?php esc_html_e( 'Copy code', 'brikpanel' ); ?></span>
                                </button>
                            </div>
                            <pre class="brikpanel-dev-docs-code"><code><?php echo esc_html( $hook['example'] ); ?></code></pre>
                        </div>
                    </article>
                <?php endforeach; ?>
                <div class="brikpanel-dev-docs-empty" id="brikpanel-dev-docs-empty" hidden>
                    <?php esc_html_e( 'No hooks match your search.', 'brikpanel' ); ?>
                </div>
            </div>
        </div>
    </div>
    <?php brikpanel_render_dev_docs_assets(); ?>
    <?php
}

/**
 * Inline CSS + JS for the docs modal. Kept self-contained so the modal works
 * on the WC settings screen without any extra enqueue plumbing.
 */
function brikpanel_render_dev_docs_assets() {
    ?>
    <style>
    .brikpanel-dev-docs-intro {
        margin: 0 0 .75rem;
        color: #616161;
        font-size: 13px;
        line-height: 1.5;
        max-width: 640px;
    }
    .brikpanel-dev-docs-open-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: .5rem 1rem;
        background: #303030;
        color: #fff;
        border: none;
        border-radius: .5rem;
        font-size: 13px;
        font-weight: 550;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        cursor: pointer;
        box-shadow: inset 0 -1px 0 rgba(0,0,0,.2), inset 0 1px 0 rgba(255,255,255,.1);
        transition: background .15s ease;
    }
    .brikpanel-dev-docs-open-btn:hover {
        background: #1a1a1a;
        color: #fff;
    }
    .brikpanel-dev-docs-open-btn:focus {
        outline: none;
        box-shadow: inset 0 -1px 0 rgba(0,0,0,.2), inset 0 1px 0 rgba(255,255,255,.1), 0 0 0 2px #303030;
    }
    .brikpanel-dev-docs-open-btn svg { flex-shrink: 0; }

    .brikpanel-dev-docs-overlay {
        position: fixed;
        inset: 0;
        background: rgba(20,20,20,.6);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 100050;
        padding: 24px;
    }
    .brikpanel-dev-docs-overlay.is-open {
        display: flex;
        animation: brikpanelDevDocsFade .15s ease;
    }
    @keyframes brikpanelDevDocsFade {
        from { opacity: 0; }
        to   { opacity: 1; }
    }
    .brikpanel-dev-docs-modal {
        background: #fff;
        border-radius: 12px;
        width: 100%;
        max-width: 820px;
        max-height: calc(100vh - 48px);
        display: flex;
        flex-direction: column;
        box-shadow: 0 20px 60px rgba(0,0,0,.25);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        color: #303030;
        overflow: hidden;
        animation: brikpanelDevDocsRise .2s cubic-bezier(.16,1,.3,1);
    }
    @keyframes brikpanelDevDocsRise {
        from { transform: translateY(8px) scale(.98); opacity: 0; }
        to   { transform: none; opacity: 1; }
    }
    .brikpanel-dev-docs-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        padding: 1.25rem 1.5rem 1rem;
        border-bottom: 1px solid #e3e3e3;
    }
    .brikpanel-dev-docs-header-text h2 {
        margin: 0 0 4px;
        font-size: 18px;
        font-weight: 600;
        color: #303030;
    }
    .brikpanel-dev-docs-header-text p {
        margin: 0;
        font-size: 13px;
        color: #616161;
        line-height: 1.5;
    }
    .brikpanel-dev-docs-close {
        background: transparent;
        border: none;
        width: 32px;
        height: 32px;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #616161;
        cursor: pointer;
        flex-shrink: 0;
        transition: background .15s ease, color .15s ease;
    }
    .brikpanel-dev-docs-close:hover { background: #f1f1f1; color: #303030; }

    .brikpanel-dev-docs-search-wrap {
        position: relative;
        padding: .75rem 1.5rem;
        border-bottom: 1px solid #e3e3e3;
        background: #fafafa;
    }
    .brikpanel-dev-docs-search-wrap svg {
        position: absolute;
        left: 1.75rem;
        top: 50%;
        transform: translateY(-50%);
        color: #8a8a8a;
        pointer-events: none;
    }
    .brikpanel-dev-docs-search-wrap input[type="search"] {
        width: 100%;
        padding: .5rem .75rem .5rem 2rem;
        border: 1px solid #8a8a8a;
        border-radius: .5rem;
        font-size: 14px;
        font-family: inherit;
        background: #fff;
        color: #303030;
        box-shadow: none;
        outline: none;
        transition: box-shadow .15s ease, border-color .15s ease;
    }
    .brikpanel-dev-docs-search-wrap input[type="search"]:focus {
        border-color: #303030;
        box-shadow: 0 0 0 1px #303030;
    }

    .brikpanel-dev-docs-body {
        padding: 1rem 1.5rem 1.5rem;
        overflow-y: auto;
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .brikpanel-dev-docs-item {
        border: 1px solid #e3e3e3;
        border-radius: .75rem;
        padding: 1rem 1.1rem;
        background: #fff;
        box-shadow: 0 1px 2px rgba(0,0,0,.04);
    }
    .brikpanel-dev-docs-item-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        flex-wrap: wrap;
    }
    .brikpanel-dev-docs-item-title {
        display: inline-flex;
        align-items: center;
        gap: .5rem;
        flex-wrap: wrap;
    }
    .brikpanel-dev-docs-badge {
        display: inline-block;
        padding: 2px 8px;
        font-size: 11px;
        font-weight: 600;
        border-radius: 10px;
        letter-spacing: .02em;
        text-transform: uppercase;
        line-height: 1.4;
    }
    .brikpanel-dev-docs-badge--action {
        background: #eef3ff;
        color: #2d4e9a;
    }
    .brikpanel-dev-docs-badge--filter {
        background: #f3efff;
        color: #5b3aa8;
    }
    .brikpanel-dev-docs-name {
        font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
        font-size: 13px;
        color: #303030;
        background: #f1f1f1;
        padding: 3px 8px;
        border-radius: 5px;
    }

    .brikpanel-dev-docs-copy-name,
    .brikpanel-dev-docs-copy-example {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        background: #fff;
        border: 1px solid #e3e3e3;
        border-radius: 5px;
        padding: 4px 8px;
        font-size: 11.5px;
        font-weight: 550;
        color: #303030;
        cursor: pointer;
        font-family: inherit;
        transition: background .12s ease, border-color .12s ease;
    }
    .brikpanel-dev-docs-copy-name:hover,
    .brikpanel-dev-docs-copy-example:hover {
        background: #f7f7f7;
        border-color: #cfcfcf;
    }
    .brikpanel-dev-docs-copy-name.is-copied,
    .brikpanel-dev-docs-copy-example.is-copied {
        background: #e4f5e1;
        border-color: #b7e1b0;
        color: #1a6b15;
    }

    .brikpanel-dev-docs-desc {
        margin: .6rem 0 .75rem;
        font-size: 13px;
        color: #616161;
        line-height: 1.55;
    }
    .brikpanel-dev-docs-sig {
        font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
        font-size: 12.5px;
        background: #fafafa;
        border: 1px solid #e3e3e3;
        border-radius: 6px;
        padding: .5rem .75rem;
        color: #303030;
        word-break: break-word;
        margin-bottom: .75rem;
    }
    .brikpanel-dev-docs-sig code {
        background: transparent;
        padding: 0;
        color: inherit;
    }
    .brikpanel-dev-docs-example {
        border: 1px solid #e3e3e3;
        border-radius: 6px;
        overflow: hidden;
    }
    .brikpanel-dev-docs-example-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: .35rem .6rem .35rem .75rem;
        background: #fafafa;
        border-bottom: 1px solid #e3e3e3;
        font-size: 11.5px;
        font-weight: 600;
        color: #616161;
        text-transform: uppercase;
        letter-spacing: .04em;
    }
    .brikpanel-dev-docs-code {
        margin: 0;
        padding: .85rem 1rem;
        background: #1e1e1e;
        color: #f1f1f1;
        font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
        font-size: 12.5px;
        line-height: 1.55;
        overflow-x: auto;
        white-space: pre;
    }
    .brikpanel-dev-docs-code code {
        background: transparent;
        color: inherit;
        font-family: inherit;
        font-size: inherit;
        padding: 0;
    }
    .brikpanel-dev-docs-empty {
        text-align: center;
        padding: 2rem 1rem;
        color: #8a8a8a;
        font-size: 13px;
    }

    @media (max-width: 640px) {
        .brikpanel-dev-docs-overlay { padding: 0; }
        .brikpanel-dev-docs-modal {
            max-height: 100vh;
            border-radius: 0;
        }
    }
    </style>
    <script>
    (function () {
        var overlay  = document.getElementById('brikpanel-dev-docs-overlay');
        var openBtn  = document.getElementById('brikpanel-dev-docs-open');
        var closeBtn = document.getElementById('brikpanel-dev-docs-close');
        var search   = document.getElementById('brikpanel-dev-docs-search');
        var empty    = document.getElementById('brikpanel-dev-docs-empty');
        if (!overlay || !openBtn) return;

        var items = overlay.querySelectorAll('.brikpanel-dev-docs-item');

        function openModal() {
            overlay.classList.add('is-open');
            overlay.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            setTimeout(function () {
                if (search) { search.focus(); }
            }, 50);
        }
        function closeModal() {
            overlay.classList.remove('is-open');
            overlay.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        openBtn.addEventListener('click', openModal);
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeModal();
        });

        if (search) {
            search.addEventListener('input', function () {
                var q = search.value.trim().toLowerCase();
                var shown = 0;
                items.forEach(function (item) {
                    var hay = item.getAttribute('data-hook-name') || '';
                    var visible = !q || hay.indexOf(q) !== -1;
                    item.style.display = visible ? '' : 'none';
                    if (visible) shown++;
                });
                if (empty) empty.hidden = shown !== 0;
            });
        }

        function copyText(text, btn) {
            var done = function () {
                if (!btn) return;
                btn.classList.add('is-copied');
                var label = btn.querySelector('span');
                var original = label ? label.textContent : null;
                if (label) { label.textContent = '<?php echo esc_js( __( 'Copied!', 'brikpanel' ) ); ?>'; }
                setTimeout(function () {
                    btn.classList.remove('is-copied');
                    if (label && original !== null) { label.textContent = original; }
                }, 1400);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done).catch(function () { fallbackCopy(text); done(); });
            } else {
                fallbackCopy(text); done();
            }
        }
        function fallbackCopy(text) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.top = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (err) {}
            document.body.removeChild(ta);
        }

        overlay.addEventListener('click', function (e) {
            var copyBtn = e.target.closest('.brikpanel-dev-docs-copy-name, .brikpanel-dev-docs-copy-example');
            if (!copyBtn) return;
            e.preventDefault();
            copyText(copyBtn.getAttribute('data-copy') || '', copyBtn);
        });
    })();
    </script>
    <?php
}

// Register the custom WC settings field type so admins can drop a
// `brikpanel_dev_docs` entry into brikpanel_settings_fields() and have WC
// render it via the generic field-type dispatcher.
add_action( 'woocommerce_admin_field_brikpanel_dev_docs', 'brikpanel_render_dev_docs_field' );
