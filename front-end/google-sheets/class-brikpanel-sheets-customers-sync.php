<?php
/**
 * BrikPanel — Sheets customers snapshot (chained to the nightly RFM
 * recompute).
 *
 * Reads from {prefix}brikpanel_customer_metrics — the table populated by
 * brikpanel_recompute_customer_metrics_handler() — and overwrites a
 * "Customers" tab in the target spreadsheet with one row per customer.
 *
 * Schedules itself to run right after each successful RFM recompute, so
 * users see fresh segments without configuring a separate schedule.
 *
 * @package BrikPanel
 * @since   2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Sheets_Customers_Sync {

	const HOOK = 'brikpanel_gs_customers_snapshot';

	const OPT_ENABLED  = 'brikpanel_gs_customers_enabled';
	const OPT_TAB_NAME = 'brikpanel_gs_customers_tab';
	const OPT_LAST     = 'brikpanel_gs_customers_last_sync';

	const BATCH_SIZE = 500;

	public function __construct() {
		add_action( 'brikpanel_cron_register', [ $this, 'register' ] );

		// Chain after the nightly RFM recompute. AS executes both handlers
		// on the same hook in priority order; ours runs after the recompute
		// has written its rows.
		add_action( 'brikpanel_recompute_customer_metrics', [ $this, 'maybe_chain_after_recompute' ], 100 );
	}

	public static function is_enabled() {
		return get_option( self::OPT_ENABLED, 'no' ) === 'yes';
	}

	public static function tab_name() {
		$name = (string) get_option( self::OPT_TAB_NAME, 'Customers' );
		return $name !== '' ? $name : 'Customers';
	}

	public function register() {
		Brikpanel_Cron::register_handler(
			self::HOOK,
			[ $this, 'handle' ],
			[ 'label' => __( 'Sheets — customers + RFM snapshot', 'brikpanel' ) ]
		);
	}

	public function maybe_chain_after_recompute() {
		if ( ! self::is_enabled() ) {
			return;
		}
		Brikpanel_Cron::enqueue_async( self::HOOK, [], [ 'unique' => true ] );
	}

	public static function trigger_manual() {
		Brikpanel_Cron::enqueue_async( self::HOOK, [], [ 'unique' => true ] );
	}

	// =========================================================================
	// Handler
	// =========================================================================

	public function handle( $args = [] ) {
		if ( ! self::is_enabled() || ! Brikpanel_Sheets_Tokens::is_connected() ) {
			return [ 'rows' => 0 ];
		}
		$target = Brikpanel_Sheets_Order_Sync::resolve_active_target();
		if ( ! $target ) {
			Brikpanel_Sheets_Logger::log( 'customers', 'No active spreadsheet configured; skipping customers sync.' );
			return [ 'rows' => 0 ];
		}

		$columns = Brikpanel_Sheets_Mapping::get_columns( 'customers' );
		$headers = Brikpanel_Sheets_Mapping::headers_for( 'customers', $columns );
		$tab     = self::tab_name();

		$client = new Brikpanel_Sheets_Client();
		try {
			$client->ensure_tab( $target['spreadsheet_id'], $tab, $headers );
			// Clear, then rewrite header + body.
			$client->values_clear( $target['spreadsheet_id'], Brikpanel_Sheets_Client::a1_quote_tab( $tab ) );
		} catch ( Brikpanel_Sheets_Exception $e ) {
			Brikpanel_Sheets_Logger::log( 'customers', 'ensure/clear failed: ' . $e->getMessage(), $e->http_code );
			throw $e;
		}

		// Write header first.
		try {
			$client->values_update(
				$target['spreadsheet_id'],
				Brikpanel_Sheets_Client::a1_quote_tab( $tab ) . '!A1',
				[ $headers ],
				'RAW'
			);
		} catch ( Brikpanel_Sheets_Exception $e ) {
			Brikpanel_Sheets_Logger::log( 'customers', 'header write failed: ' . $e->getMessage(), $e->http_code );
			throw $e;
		}

		// Stream the metrics table in batches via append (header already in row 1).
		global $wpdb;
		$tbl = $wpdb->prefix . 'brikpanel_customer_metrics';

		$offset = 0;
		$total_written = 0;
		while ( true ) {
			$rows_raw = $wpdb->get_results( $wpdb->prepare(
				"SELECT m.customer_key, m.user_id, m.customer_email, m.first_order_date, m.last_order_date,
				        m.order_count, m.total_spent, m.aov, m.recency_days,
				        m.r_score, m.f_score, m.m_score, m.rfm_segment,
				        u.display_name,
				        bm_fn.meta_value AS billing_first_name,
				        bm_ln.meta_value AS billing_last_name
				 FROM {$tbl} m
				 LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID AND m.user_id > 0
				 LEFT JOIN {$wpdb->usermeta} bm_fn ON bm_fn.user_id = u.ID AND bm_fn.meta_key = 'billing_first_name'
				 LEFT JOIN {$wpdb->usermeta} bm_ln ON bm_ln.user_id = u.ID AND bm_ln.meta_key = 'billing_last_name'
				 ORDER BY m.total_spent DESC
				 LIMIT %d OFFSET %d",
				self::BATCH_SIZE, $offset
			) ); // phpcs:ignore

			if ( empty( $rows_raw ) ) {
				break;
			}

			$batch = [];
			foreach ( $rows_raw as $r ) {
				$name = trim( trim( (string) $r->billing_first_name . ' ' . (string) $r->billing_last_name ) );
				if ( $name === '' ) {
					$name = (string) $r->display_name;
				}
				if ( $name === '' ) {
					$name = (string) $r->customer_email;
				}
				$row = [];
				foreach ( $columns as $col ) {
					switch ( $col ) {
						case 'customer_key': $row[] = (string) $r->customer_key; break;
						case 'user_id':      $row[] = (int)    $r->user_id;      break;
						case 'name':         $row[] = $name;                     break;
						case 'email':        $row[] = (string) $r->customer_email; break;
						case 'first_order':  $row[] = (string) ( $r->first_order_date ?? '' ); break;
						case 'last_order':   $row[] = (string) ( $r->last_order_date ?? '' );  break;
						case 'order_count':  $row[] = (int)    $r->order_count;  break;
						case 'total_spent':  $row[] = round( (float) $r->total_spent, 4 ); break;
						case 'aov':          $row[] = round( (float) $r->aov, 4 ); break;
						case 'recency_days': $row[] = $r->recency_days !== null ? (int) $r->recency_days : ''; break;
						case 'r_score':      $row[] = (int) $r->r_score; break;
						case 'f_score':      $row[] = (int) $r->f_score; break;
						case 'm_score':      $row[] = (int) $r->m_score; break;
						case 'rfm_segment':  $row[] = (string) ( $r->rfm_segment ?? '' ); break;
						default:             $row[] = '';
					}
				}
				$batch[] = $row;
			}

			try {
				$client->append_rows( $target['spreadsheet_id'], $tab, $batch, 'RAW' );
			} catch ( Brikpanel_Sheets_Exception $e ) {
				Brikpanel_Sheets_Logger::log( 'customers', 'append failed at offset ' . $offset . ': ' . $e->getMessage(), $e->http_code );
				throw $e;
			}

			$total_written += count( $batch );
			$offset        += self::BATCH_SIZE;
		}

		update_option( self::OPT_LAST, [ 'ts' => time(), 'rows' => $total_written ], false );
		return [ 'rows' => $total_written ];
	}
}
