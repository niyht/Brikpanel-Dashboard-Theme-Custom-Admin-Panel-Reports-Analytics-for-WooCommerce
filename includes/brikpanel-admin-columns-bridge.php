<?php
/**
 * BrikPanel - Admin Columns compatibility bridge.
 *
 * Surfaces the columns a merchant configured in the Admin Columns plugin
 * inside BrikPanel's own products list.
 *
 * Admin Columns never reaches that list on its own, for two independent
 * reasons:
 *
 *  1. Its entire table pipeline hangs off the `current_screen` action, and
 *     wp-admin/admin-ajax.php never fires it — there is no WP_Screen in an
 *     AJAX request, so none of Admin Columns' hooks are ever registered.
 *  2. It registers its headings on `manage_edit-product_columns` (the screen
 *     id) while BrikPanel replays `manage_product_posts_columns` (the post
 *     type). Replaying the screen-id filter is not an option either: Admin
 *     Columns' callback discards the incoming headings wholesale and returns
 *     only its own set, which would wipe BrikPanel's synthetic baseline along
 *     with every other plugin's column.
 *
 * Values are the one place the two agree: Admin Columns renders cells through
 * `manage_product_posts_custom_column`, which Brikpanel_ASE_Bridge already
 * replays. So this bridge does exactly two things — declare the configured
 * columns (with Admin Columns' own labels, widths and ordering) through
 * `brikpanel_products_columns`, and boot Admin Columns' value service before
 * the products-list row loop starts. Cell rendering, sanitisation and the
 * Throwable guards all stay in the existing replay path.
 *
 * Everything here talks to internal Admin Columns classes rather than a
 * documented public API, so every call is guarded and every failure degrades
 * to "no Admin Columns columns" rather than to a broken screen.
 *
 * @package BrikPanel
 * @since 3.2.77
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Admin_Columns_Bridge {

	/**
	 * Memoised lookups, keyed by blog id. Admin Columns stores its
	 * configuration in a per-site table, so on multisite a request that
	 * switches blogs must not be served another site's columns.
	 *
	 * @var array<int, \AC\ListScreen|false>
	 */
	private static $list_screen = [];

	/**
	 * Memoised column maps by blog id: column id => [ 'label', 'width' ].
	 *
	 * @var array<int, array>
	 */
	private static $columns = [];

	/**
	 * Memoised result of booting the value service, by blog id.
	 *
	 * @var array<int, bool>
	 */
	private static $booted = [];

	/**
	 * Cache key for the current site.
	 *
	 * @return int
	 */
	private static function site_key() {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
	}

	/**
	 * Registers the filter. Priority 30 puts these columns after the Product
	 * Code column (20), i.e. at the tail with the other plugin columns.
	 */
	public static function init() {
		add_filter( 'brikpanel_products_columns', [ __CLASS__, 'register_columns' ], 30, 2 );
	}

	/**
	 * Declares the configured Admin Columns columns to the products list.
	 *
	 * @param array $cols    Column definitions.
	 * @param int   $user_id User the definitions are resolved for.
	 * @return array
	 */
	public static function register_columns( $cols, $user_id ) {
		if ( ! is_array( $cols ) ) {
			return $cols;
		}

		// Admin Columns hooks `manage_product_posts_custom_column` and throws
		// if that action has already fired, so its value service has to be up
		// before the row loop renders its first cell. get_column_defs() runs at
		// the top of ajax_fetch_products(), well ahead of the loop, which makes
		// this filter the natural — and only guaranteed — pre-loop touchpoint.
		if ( wp_doing_ajax() ) {
			self::boot_values();
		}

		foreach ( self::get_columns() as $id => $meta ) {
			// A native BrikPanel column, or one another plugin already
			// contributed, wins: it is rendered by code that knows this table.
			if ( isset( $cols[ $id ] ) ) {
				continue;
			}

			$cols[ $id ] = [
				'label'   => $meta['label'],
				// Opt-in, like every other plugin column. The table never
				// widens itself; the merchant ticks the column on from the
				// Columns picker, under "Plugin columns".
				'default' => false,
				// Dynamic column: JS injects the <th>/<td> and hides it by
				// class. No render callback — the cell comes from the replayed
				// column action, which is where Admin Columns writes it.
				'extra'   => true,
				'width'   => $meta['width'],
			];
		}

		return $cols;
	}

	/**
	 * The Admin Columns list screen configured for products, or null.
	 *
	 * @return \AC\ListScreen|null
	 */
	private static function list_screen() {
		$site = self::site_key();

		if ( isset( self::$list_screen[ $site ] ) ) {
			return self::$list_screen[ $site ] ?: null;
		}

		self::$list_screen[ $site ] = false;

		if ( ! class_exists( '\AC\Registry' )
			|| ! class_exists( '\AC\ListScreenRepository\Storage' )
			|| ! class_exists( '\AC\Type\TableId' ) ) {
			return null;
		}

		try {
			$storage = \AC\Registry::get( \AC\ListScreenRepository\Storage::class );

			if ( ! $storage instanceof \AC\ListScreenRepository\Storage ) {
				return null;
			}

			$table_id    = new \AC\Type\TableId( 'product' );
			$list_screen = null;

			// The merchant's active layout, when one has been recorded.
			if ( class_exists( '\AC\Table\TablePreference' ) ) {
				$list_id = ( new \AC\Table\TablePreference() )->get_list_id( $table_id );

				if ( $list_id ) {
					$list_screen = $storage->find( $list_id );
				}
			}

			// That preference is only ever written from Admin Columns' own
			// `current_screen` service, so on a store where nobody has opened
			// the native products list it does not exist at all. Fall back to
			// the first active configuration for this table — the free version
			// only ever stores one per table anyway.
			if ( ! $list_screen && class_exists( '\AC\Type\ListScreenStatus' ) ) {
				$found = $storage->find_all_by_table_id(
					$table_id,
					null,
					\AC\Type\ListScreenStatus::create_active()
				);

				foreach ( $found as $candidate ) {
					$list_screen = $candidate;
					break;
				}
			}

			if ( ! $list_screen || ! $list_screen->is_user_allowed( wp_get_current_user() ) ) {
				return null;
			}

			self::$list_screen[ $site ] = $list_screen;
		} catch ( \Throwable $e ) {
			self::$list_screen[ $site ] = false;

			return null;
		}

		return self::$list_screen[ $site ] ?: null;
	}

	/**
	 * The configured columns worth surfacing, keyed by column id.
	 *
	 * @return array<string, array{label: string, width: string}>
	 */
	private static function get_columns() {
		$site = self::site_key();

		if ( isset( self::$columns[ $site ] ) ) {
			return self::$columns[ $site ];
		}

		self::$columns[ $site ] = [];

		$list_screen = self::list_screen();

		if ( ! $list_screen ) {
			return self::$columns[ $site ];
		}

		try {
			$columns = $list_screen->get_columns();

			// Honour the order the merchant dragged the columns into.
			if ( class_exists( '\AC\ColumnRepository\Sort\ManualOrder' ) ) {
				$columns = ( new \AC\ColumnRepository\Sort\ManualOrder( $list_screen->get_id() ) )
					->sort( $columns );
			}

			$table_screen = $list_screen->get_table_screen();
		} catch ( \Throwable $e ) {
			return self::$columns[ $site ];
		}

		foreach ( $columns as $column ) {
			try {
				// The exact test for "will Admin Columns actually draw this?".
				// Its own render factory returns nothing for a column with an
				// empty formatter set, and that is precisely the two kinds we
				// must not surface:
				//   - "original" columns, i.e. the ones WooCommerce, an SEO
				//     plugin or a taxonomy already contribute. Admin Columns
				//     only re-lists those; it renders them empty, and they
				//     already reach this table as native or replayed columns.
				//   - the upsell placeholders whose body is a "this is only
				//     available in the Pro version" message.
				if ( 0 === $column->get_formatters()->count() ) {
					continue;
				}

				// Belt and braces alongside the formatter test above.
				if ( 0 === strpos( (string) $column->get_type(), 'placeholder-' ) ) {
					continue;
				}

				$id = (string) $column->get_id();

				if ( '' === $id ) {
					continue;
				}

				self::$columns[ $site ][ $id ] = [
					'label' => self::column_label( $column, $table_screen ),
					'width' => self::column_width( $column ),
				];
			} catch ( \Throwable $e ) {
				// One malformed column must not cost the rest.
				continue;
			}
		}

		return self::$columns[ $site ];
	}

	/**
	 * The merchant's own label for a column, falling back to the column type's
	 * default. Markup is stripped: some column types put icons in the label,
	 * and this string is rendered as a plain <th> here.
	 *
	 * @param \AC\Column      $column       Column.
	 * @param \AC\TableScreen $table_screen Table screen the column belongs to.
	 * @return string
	 */
	private static function column_label( $column, $table_screen ) {
		$label = '';

		$setting = $column->get_setting( 'label' );

		if ( $setting && $setting->has_input() ) {
			$label = (string) $setting->get_input()->get_value();
		}

		if ( '' === trim( $label ) ) {
			$label = (string) $column->get_label();
		}

		$label = (string) apply_filters(
			'ac/column/heading/label',
			$label,
			$column->get_context(),
			$table_screen
		);

		$label = trim( html_entity_decode( wp_strip_all_tags( $label ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		// Admin Columns is content to leave an unlabelled header; this table
		// is not, because the Columns picker would show a nameless checkbox.
		return ( '' !== $label ) ? $label : (string) $column->get_label();
	}

	/**
	 * The merchant's configured width as a CSS length, or '' when unset.
	 * Mirrors how Admin Columns itself reads the pair of width settings.
	 *
	 * @param \AC\Column $column Column.
	 * @return string
	 */
	private static function column_width( $column ) {
		$width_setting = $column->get_setting( 'width' );

		if ( ! $width_setting || ! $width_setting->has_input() ) {
			return '';
		}

		$width = (int) $width_setting->get_input()->get_value();

		if ( $width < 1 ) {
			return '';
		}

		$unit_setting = $column->get_setting( 'width_unit' );
		$unit         = ( $unit_setting && $unit_setting->has_input() )
			? (string) $unit_setting->get_input()->get_value()
			: 'px';

		if ( ! in_array( $unit, [ 'px', '%' ], true ) ) {
			return '';
		}

		return $width . $unit;
	}

	/**
	 * Registers Admin Columns' value service so its cells render inside the
	 * replayed column action. Idempotent; AJAX only.
	 *
	 * @return bool Whether the service is hooked up.
	 */
	private static function boot_values() {
		$site = self::site_key();

		if ( isset( self::$booted[ $site ] ) ) {
			return self::$booted[ $site ];
		}

		self::$booted[ $site ] = false;

		if ( ! class_exists( '\AC\Service\ManageValue' ) ) {
			return false;
		}

		// Admin Columns throws once the action has fired, because hooking it
		// then would silently miss rows. Nothing in this request fires it
		// before the products-list loop today, but check rather than rely on
		// that staying true.
		if ( did_action( 'manage_product_posts_custom_column' ) ) {
			return false;
		}

		$list_screen = self::list_screen();

		if ( ! $list_screen ) {
			return false;
		}

		try {
			( new \AC\Service\ManageValue() )->handle( $list_screen, $list_screen->get_table_screen() );

			self::$booted[ $site ] = true;
		} catch ( \Throwable $e ) {
			// Registration happens outside any output buffer, so a throw here
			// would land in the JSON body. Swallow it and render without the
			// Admin Columns cells.
			self::$booted[ $site ] = false;
		}

		return self::$booted[ $site ];
	}
}

Brikpanel_Admin_Columns_Bridge::init();
