/**
 * BrikPanel Customers page: the Lifetime value, RFM segments and Cohort
 * retention tabs (once the Customer Analytics screen).
 *
 * The page shell (front-end/customers/brikpanel-customers.js) owns the tab
 * strip and calls window.brikpanelCustomerAnalytics.show( tab ); each tab
 * loads its data the first time it is shown. A click on an RFM card opens
 * All customers filtered to that segment.
 */
( function () {
	'use strict';

	if ( typeof window.brikpanelCA === 'undefined' ) {
		return;
	}
	var CFG = window.brikpanelCA;
	var i18n = CFG.i18n || {};

	var state = {
		topPage: 1,
		topPerPage: 25,
		histogramChart: null,
		rfmLoaded: false,
		rfmSegments: [],
		rfmDonut: null,
		ltvLoaded: false,
		cohortLoaded: false,
		cohortLine: null,
		cohortMonths: 12
	};

	// =========================================================================
	// Helpers
	// =========================================================================

	function el( id ) { return document.getElementById( id ); }

	// Numbers and percentages in the store's format, the percent sign where
	// the viewer's language writes it (front-end/shared/brikpanel-format.js).
	// It was the browser's language and "0 tekrar (0%)" (field test E2/E9).
	var BF = window.brikpanelFormat || null;
	function fmtNum( n, decimals ) {
		return BF ? BF.number( n || 0, decimals || 0, true ) : String( Number( n ) || 0 );
	}
	function fmtPct( v, decimals ) {
		return BF ? BF.percent( v || 0, decimals == null ? 1 : decimals ) : String( Number( v ) || 0 );
	}
	if ( BF && window.Chart ) {
		BF.chart( window.Chart );
	}

	function escapeHtml( str ) {
		if ( str === null || typeof str === 'undefined' ) { return ''; }
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	function fetchJSON( action, body ) {
		var data = new URLSearchParams();
		data.append( 'action', action );
		data.append( '_ajax_nonce', CFG.nonce );
		if ( body ) {
			Object.keys( body ).forEach( function ( k ) {
				data.append( k, body[ k ] );
			} );
		}
		return fetch( CFG.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: data.toString()
		} ).then( function ( r ) { return r.json(); } );
	}

	function showToast( message, isError ) {
		var existing = document.querySelector( '.bp-ca-toast' );
		if ( existing ) { existing.remove(); }

		var toast = document.createElement( 'div' );
		toast.className = 'bp-ca-toast' + ( isError ? ' is-error' : '' );
		toast.textContent = message;
		document.body.appendChild( toast );

		// Force reflow so the transition kicks in.
		void toast.offsetWidth;
		toast.classList.add( 'is-visible' );

		setTimeout( function () {
			toast.classList.remove( 'is-visible' );
			setTimeout( function () { toast.remove(); }, 350 );
		}, 3500 );
	}

	// A chart with nothing to draw gives way to one line saying why (field
	// test F3: empty axes and ten empty cards read as broken). The box around
	// the chart is hidden, never the canvas: Chart.js writes display:block on
	// the canvas itself. It is shown again before a chart is built, so the
	// chart measures a visible box.
	function showEmpty( boxId, msgId, isEmpty, message ) {
		var box = el( boxId ), msg = el( msgId );
		if ( box ) { box.hidden = !! isEmpty; }
		if ( msg ) {
			msg.textContent = isEmpty ? ( message || '' ) : '';
			msg.hidden = ! isEmpty;
		}
	}

	function dropChart( key ) {
		if ( state[ key ] ) {
			state[ key ].destroy();
			state[ key ] = null;
		}
	}

	// The line under the title, sent back by the server after a recompute
	// ("Not calculated yet" can become "No customers yet." or a date). The
	// header row is fitted by width, so it is measured again.
	function setMeta( text ) {
		var meta = el( 'bp-ca-meta' );
		if ( ! meta || typeof text !== 'string' || text === '' ) { return; }
		meta.textContent = text;
		var fit = window.brikpanelFitRow && window.brikpanelFitRow.auto( el( CFG.header_id || 'bp-cus-header' ) );
		if ( fit && typeof fit.refit === 'function' ) { fit.refit(); }
	}

	// =========================================================================
	// LTV Summary
	// =========================================================================

	function loadSummary() {
		fetchJSON( 'brikpanel_ca_ltv_summary' ).then( function ( res ) {
			if ( ! res || ! res.success ) { return; }
			var d = res.data;
			el( 'bp-ca-stat-customers' ).textContent = fmtNum( d.total_customers );
			// Built on the server: plural form for the count, the rate as a
			// finished percentage.
			el( 'bp-ca-stat-repeat' ).textContent = d.repeat_line || '';
			el( 'bp-ca-stat-avg-ltv' ).textContent = d.avg_ltv_display || '—';
			el( 'bp-ca-stat-median-ltv' ).textContent = d.median_ltv_display || '—';
			el( 'bp-ca-stat-total-ltv' ).textContent = d.total_ltv_display || '—';
			el( 'bp-ca-stat-avg-aov' ).textContent = d.avg_aov_display || '—';
			el( 'bp-ca-stat-max-ltv' ).textContent = d.max_ltv_display || '—';
		} );
	}

	// The top-customers table stacks into cards when it cannot show every column
	// in their card (field test B6: on a phone AOV, LTV and Last order scrolled
	// out of sight). Labels come from the header texts.
	var topFit = null;
	function fitFor( tableId, current ) {
		if ( current || ! window.brikpanelFitTable ) { return current; }
		var table = el( tableId );
		return table ? window.brikpanelFitTable( table, { labels: 'head', slack: 0 } ) : null;
	}
	function setTopBody( html ) {
		var body = el( 'bp-ca-top-customers-body' );
		if ( ! body ) { return; }
		body.innerHTML = html;
		topFit = fitFor( 'bp-ca-top-customers', topFit );
		if ( topFit ) { topFit.refit(); }
	}
	function emailHtml( email ) {
		email = String( email || '' );
		var at = email.indexOf( '@' );
		var dotBreak = function ( part ) { return escapeHtml( part ).split( '.' ).join( '<wbr>.' ); };
		if ( at < 0 ) { return escapeHtml( email ); }
		return dotBreak( email.slice( 0, at ) ) + '@<wbr>' + dotBreak( email.slice( at + 1 ) );
	}

	// =========================================================================
	// Top customers table
	// =========================================================================

	function loadTopCustomers() {
		setTopBody( '<tr><td class="bp-ca-empty" colspan="6">' + escapeHtml( i18n.loading || 'Loading…' ) + '</td></tr>' );

		fetchJSON( 'brikpanel_ca_ltv_top_customers', {
			page: state.topPage,
			per_page: state.topPerPage
		} ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				setTopBody( '<tr><td class="bp-ca-empty" colspan="6">' + escapeHtml( i18n.error || 'Could not load.' ) + '</td></tr>' );
				return;
			}
			var data = res.data;
			renderTopRows( data.items || [] );
			renderPagination( data );
		} );
	}

	function renderTopRows( items ) {
		if ( ! items.length ) {
			setTopBody( '<tr><td class="bp-ca-empty" colspan="6">' + escapeHtml( i18n.empty || 'No customers yet.' ) + '</td></tr>' );
			return;
		}

		var html = '';
		items.forEach( function ( c ) {
			var customerCell = '<div class="bp-ca-customer-cell">'
				+ '<div class="bp-ca-customer-name">' + escapeHtml( c.name )
				+ ( c.is_guest ? '<span class="bp-ca-guest-pill">' + escapeHtml( i18n.guest || 'Guest' ) + '</span>' : '' )
				+ '</div>'
				+ '<div class="bp-ca-customer-email"><span dir="ltr">' + emailHtml( c.email ) + '</span></div>'
				+ ( c.phone ? '<div class="bp-ca-customer-phone"><span dir="ltr">' + escapeHtml( c.phone ) + '</span></div>' : '' )
				+ '</div>';

			var href = c.profile_url || c.edit_url;
			if ( href ) {
				customerCell = '<a href="' + escapeHtml( href ) + '" style="color: inherit; text-decoration: none;">' + customerCell + '</a>';
			}

			var recencyText = '—';
			if ( c.recency_days !== null && typeof c.recency_days !== 'undefined' ) {
				if ( c.recency_days === 0 ) {
					recencyText = i18n.today || 'Today';
				} else if ( c.recency_days === 1 ) {
					recencyText = i18n.yesterday || '1 day';
				} else {
					recencyText = c.recency_days + ' ' + ( i18n.days_ago || 'd' );
				}
			}

			html += '<tr>'
				+ '<td class="brikpanel-fit-lead">' + customerCell + '</td>'
				+ '<td class="num">' + c.order_count + '</td>'
				+ '<td class="num">' + escapeHtml( c.aov_display ) + '</td>'
				+ '<td class="num brikpanel-fit-headline"><strong>' + escapeHtml( c.total_spent_display ) + '</strong></td>'
				+ '<td>' + escapeHtml( c.last_order || '—' ) + '</td>'
				+ '<td class="num">' + escapeHtml( recencyText ) + '</td>'
				+ '</tr>';
		} );
		setTopBody( html );
	}

	function renderPagination( data ) {
		var pag = el( 'bp-ca-pagination' );
		if ( data.pages <= 1 ) {
			pag.hidden = true;
			return;
		}
		pag.hidden = false;
		el( 'bp-ca-page-info' ).textContent = data.page + ' / ' + data.pages;
		el( 'bp-ca-prev' ).disabled = data.page <= 1;
		el( 'bp-ca-next' ).disabled = data.page >= data.pages;
	}

	// =========================================================================
	// Histogram (Chart.js)
	// =========================================================================

	function loadHistogram() {
		fetchJSON( 'brikpanel_ca_ltv_distribution' ).then( function ( res ) {
			if ( ! res || ! res.success ) { return; }
			renderHistogram( res.data || {} );
		} );
	}

	function renderHistogram( payload ) {
		var bins = payload.bins || [];
		if ( ! bins.length ) {
			// Brackets exist only once someone has spent money: either there
			// are no customers, or none of them has spent anything yet.
			dropChart( 'histogramChart' );
			showEmpty( 'bp-ca-ltv-wrap', 'bp-ca-ltv-empty', true,
				Number( payload.customers ) > 0 ? i18n.ltv_zero_spend : i18n.empty );
			return;
		}
		showEmpty( 'bp-ca-ltv-wrap', 'bp-ca-ltv-empty', false );

		var canvas = el( 'bp-ca-ltv-histogram' );
		if ( ! canvas || typeof Chart === 'undefined' ) { return; }

		var labels = bins.map( function ( b ) {
			// Use the upper bound of each bracket — concise label.
			return b.hi_display;
		} );
		var data = bins.map( function ( b ) { return b.customers; } );
		var tooltipLabels = bins.map( function ( b ) { return BF ? BF.range( b.lo_display, b.hi_display ) : b.lo_display + ' / ' + b.hi_display; } );

		if ( state.histogramChart ) {
			state.histogramChart.destroy();
		}

		state.histogramChart = new Chart( canvas.getContext( '2d' ), {
			type: 'bar',
			data: {
				labels: labels,
				datasets: [ {
					label: i18n.customers || 'Customers',
					data: data,
					backgroundColor: '#303030',
					borderRadius: 4,
					barPercentage: 0.85,
					categoryPercentage: 0.95
				} ]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: {
					legend: { display: false },
					tooltip: {
						callbacks: {
							title: function ( ctx ) { return tooltipLabels[ ctx[ 0 ].dataIndex ]; },
							label: function ( ctx ) {
								return ( i18n.customers || 'Customers' ) + ': ' + fmtNum( ctx.parsed.y );
							}
						}
					}
				},
				scales: {
					x: {
						grid: { display: false },
						ticks: { color: '#616161', font: { size: 11 }, maxRotation: 0, autoSkip: true }
					},
					y: {
						beginAtZero: true,
						ticks: { color: '#616161', font: { size: 11 }, precision: 0 },
						grid: { color: '#f1f1f1' }
					}
				}
			}
		} );
	}

	// =========================================================================
	// RFM tab
	// =========================================================================

	function loadRfmSummary() {
		var grid = el( 'bp-ca-rfm-grid' );
		grid.innerHTML = '<div class="bp-ca-empty">' + escapeHtml( i18n.loading || 'Loading…' ) + '</div>';

		fetchJSON( 'brikpanel_ca_rfm_summary' ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				showEmpty( 'bp-ca-rfm-layout', 'bp-ca-rfm-empty', false );
				grid.innerHTML = '<div class="bp-ca-empty">' + escapeHtml( i18n.error || 'Could not load.' ) + '</div>';
				return;
			}
			var d = res.data || {};
			state.rfmSegments = d.segments || [];

			// No customers, or customers the scoring pass has not placed in a
			// segment yet (the server says which): one line instead of ten
			// empty cards and a blank ring.
			var noCustomers = ! ( Number( d.total_customers ) > 0 );
			if ( noCustomers || d.scored === false ) {
				dropChart( 'rfmDonut' );
				showEmpty( 'bp-ca-rfm-layout', 'bp-ca-rfm-empty', true,
					noCustomers ? i18n.rfm_no_customers : ( d.rfm_supported === false ? i18n.rfm_unsupported : i18n.rfm_not_scored ) );
				return;
			}
			showEmpty( 'bp-ca-rfm-layout', 'bp-ca-rfm-empty', false );
			renderRfmGrid();
			renderRfmDonut();
		} );
	}

	function renderRfmGrid() {
		var grid = el( 'bp-ca-rfm-grid' );
		if ( ! state.rfmSegments.length ) {
			grid.innerHTML = '<div class="bp-ca-empty">' + escapeHtml( i18n.empty || 'No customers yet.' ) + '</div>';
			return;
		}
		var html = '';
		state.rfmSegments.forEach( function ( s ) {
			var emptyClass = s.customers === 0 ? ' is-empty' : '';
			html += '<button type="button" class="bp-ca-rfm-card' + emptyClass + '" data-segment="' + escapeHtml( s.key ) + '"'
				+ ( s.customers === 0 ? ' disabled' : '' ) + '>'
				+ '<div class="bp-ca-rfm-card-head">'
				+ '<span class="bp-ca-rfm-card-name">'
				+ '<span class="bp-ca-rfm-dot" style="background:' + escapeHtml( s.color ) + '"></span>'
				+ escapeHtml( s.label )
				+ '</span>'
				+ '<span class="bp-ca-rfm-card-count">' + escapeHtml( fmtNum( s.customers ) ) + '<span class="bp-ca-rfm-card-share">' + escapeHtml( fmtPct( s.share ) ) + '</span></span>'
				+ '</div>'
				+ '<div class="bp-ca-rfm-card-meta">'
				+ '<span>' + ( i18n.avg_ltv_short || 'LTV' ) + ': <strong>' + escapeHtml( s.avg_ltv_display ) + '</strong></span>'
				+ '<span>' + ( i18n.avg_orders_short || 'Orders' ) + ': <strong>' + escapeHtml( fmtNum( s.avg_orders, 1 ) ) + '</strong></span>'
				+ '</div>'
				+ '<div class="bp-ca-rfm-card-desc">' + escapeHtml( s.description ) + '</div>'
				+ '</button>';
		} );
		grid.innerHTML = html;

		// Wire up clicks.
		grid.querySelectorAll( '.bp-ca-rfm-card' ).forEach( function ( card ) {
			card.addEventListener( 'click', function () {
				if ( card.disabled ) { return; }
				var seg = card.getAttribute( 'data-segment' );
				if ( window.brikpanelCustomers && typeof window.brikpanelCustomers.openSegment === 'function' ) {
					window.brikpanelCustomers.openSegment( seg );
				}
			} );
		} );
	}

	function renderRfmDonut() {
		var canvas = el( 'bp-ca-rfm-donut' );
		if ( ! canvas || typeof Chart === 'undefined' ) { return; }

		var nonEmpty = state.rfmSegments.filter( function ( s ) { return s.customers > 0; } );
		var labels   = nonEmpty.map( function ( s ) { return s.label; } );
		var data     = nonEmpty.map( function ( s ) { return s.customers; } );
		var colors   = nonEmpty.map( function ( s ) { return s.color; } );

		if ( state.rfmDonut ) {
			state.rfmDonut.destroy();
		}

		state.rfmDonut = new Chart( canvas.getContext( '2d' ), {
			type: 'doughnut',
			data: {
				labels: labels,
				datasets: [ {
					data: data,
					backgroundColor: colors,
					borderWidth: 2,
					borderColor: '#fafafa'
				} ]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				cutout: '62%',
				plugins: {
					legend: { display: false },
					tooltip: {
						callbacks: {
							label: function ( ctx ) {
								var total = ctx.dataset.data.reduce( function ( a, b ) { return a + b; }, 0 );
								var pct   = total > 0 ? Math.round( ctx.parsed / total * 100 ) : 0;
								return ctx.label + ': ' + fmtNum( ctx.parsed ) + ' (' + fmtPct( pct, 0 ) + ')';
							}
						}
					}
				}
			}
		} );
	}

	// =========================================================================
	// Cohort tab
	// =========================================================================

	function loadCohort() {
		var heat = el( 'bp-ca-cohort-heatmap' );
		heat.innerHTML = '<div class="bp-ca-empty">' + escapeHtml( i18n.loading || 'Loading…' ) + '</div>';

		fetchJSON( 'brikpanel_ca_cohort_matrix', { months: state.cohortMonths } ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				heat.innerHTML = '<div class="bp-ca-empty">' + escapeHtml( i18n.error || 'Could not load.' ) + '</div>';
				return;
			}
			renderCohortHeatmap( res.data );
			renderCohortLine( res.data );
		} );
	}

	/**
	 * Map a retention rate (0..100) to a monochrome shade. We use a
	 * neutral gray scale rather than the reds/greens of typical heatmaps
	 * because the BrikPanel UI is monochrome — higher rate = darker cell.
	 */
	function cohortColor( rate ) {
		if ( rate <= 0 ) { return { bg: '#f7f7f7', fg: '#616161' }; }
		// Easing curve so the mid-range (15-50%) gets meaningful contrast.
		var t = Math.min( 1, rate / 100 );
		var eased = Math.pow( t, 0.6 );
		// Interpolate between #f1f1f1 (light gray) and #303030 (near-black).
		var r = Math.round( 241 - eased * ( 241 - 48 ) );
		var g = Math.round( 241 - eased * ( 241 - 48 ) );
		var b = Math.round( 241 - eased * ( 241 - 48 ) );
		// White, else the UI's near-black, else black: mid greys (about #77 to
		// #95) reach 4.5:1 with neither white nor #303030, but always with black.
		var fg = contrastRatio( r, 255 ) >= 4.5 ? '#ffffff' : ( contrastRatio( r, 48 ) >= 4.5 ? '#303030' : '#000000' );
		return { bg: 'rgb(' + r + ',' + g + ',' + b + ')', fg: fg };
	}

	/** WCAG contrast between two greys given by one channel value (0-255). */
	function contrastRatio( a, b ) {
		var lum = function ( v ) {
			v /= 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
		};
		var la = lum( a ), lb = lum( b );
		return ( Math.max( la, lb ) + 0.05 ) / ( Math.min( la, lb ) + 0.05 );
	}

	function renderCohortHeatmap( data ) {
		var heat = el( 'bp-ca-cohort-heatmap' );
		if ( ! data.cohorts || data.cohorts.length === 0 ) {
			heat.innerHTML = '<div class="bp-ca-empty">' + escapeHtml( i18n.cohort_empty || 'Not enough order history to build cohorts yet.' ) + '</div>';
			return;
		}

		var maxOffset = data.max_offset;
		var html = '';

		// Header row: cohort label, size, then M0 / M+1 / … / M+N
		html += '<div class="bp-ca-cohort-row bp-ca-cohort-header-row">';
		html += '<div class="bp-ca-cohort-cell bp-ca-cohort-cell-month">' + escapeHtml( i18n.cohort_month || 'Cohort' ) + '</div>';
		html += '<div class="bp-ca-cohort-cell bp-ca-cohort-cell-size">' + escapeHtml( i18n.cohort_size || 'Size' ) + '</div>';
		for ( var i = 0; i <= maxOffset; i++ ) {
			html += '<div class="bp-ca-cohort-cell bp-ca-cohort-cell-head">M+' + i + '</div>';
		}
		html += '</div>';

		// Rows: oldest cohort at top → newest at bottom.
		data.cohorts.forEach( function ( cohort ) {
			html += '<div class="bp-ca-cohort-row">';
			html += '<div class="bp-ca-cohort-cell bp-ca-cohort-cell-month">' + escapeHtml( cohort.cohort_month_label ) + '</div>';
			html += '<div class="bp-ca-cohort-cell bp-ca-cohort-cell-size">' + escapeHtml( fmtNum( cohort.cohort_size ) ) + '</div>';
			for ( var j = 0; j <= maxOffset; j++ ) {
				var cell = cohort.cells[ j ];
				if ( ! cell ) {
					// Future month for this cohort — no data yet.
					html += '<div class="bp-ca-cohort-cell bp-ca-cohort-cell-empty">—</div>';
					continue;
				}
				var c = cohortColor( cell.rate );
				var tooltip = cohort.cohort_month_label + ' → M+' + j + ': ' + fmtNum( cell.customers ) + '/' + fmtNum( cohort.cohort_size ) + ' (' + fmtPct( cell.rate ) + ')';
				html += '<div class="bp-ca-cohort-cell bp-ca-cohort-cell-data" '
					+ 'style="background:' + c.bg + ';color:' + c.fg + ';" '
					+ 'title="' + escapeHtml( tooltip ) + '">'
					+ escapeHtml( fmtPct( cell.rate ) ) + '</div>';
			}
			html += '</div>';
		} );

		heat.innerHTML = html;
	}

	function renderCohortLine( payload ) {
		// Month 0 is every cohort's first month (always all of it), so a line
		// needs someone who came back in a later month. Without that it was
		// a single dot on an empty axis.
		if ( ! payload.cohorts || ! payload.cohorts.length || ! ( Number( payload.max_offset ) > 0 ) ) {
			dropChart( 'cohortLine' );
			showEmpty( 'bp-ca-cohort-line-wrap', 'bp-ca-cohort-line-empty', true, i18n.cohort_line_empty );
			return;
		}
		showEmpty( 'bp-ca-cohort-line-wrap', 'bp-ca-cohort-line-empty', false );

		var canvas = el( 'bp-ca-cohort-line' );
		if ( ! canvas || typeof Chart === 'undefined' ) { return; }

		var avgByOffset = payload.avg_by_offset || [];
		var labels = avgByOffset.map( function ( a ) { return 'M+' + a.offset; } );
		var data   = avgByOffset.map( function ( a ) { return a.avg; } );

		if ( state.cohortLine ) { state.cohortLine.destroy(); }

		state.cohortLine = new Chart( canvas.getContext( '2d' ), {
			type: 'line',
			data: {
				labels: labels,
				datasets: [ {
					label: i18n.avg_retention || 'Avg retention',
					data: data,
					borderColor: '#303030',
					backgroundColor: 'rgba(48, 48, 48, 0.08)',
					borderWidth: 2,
					pointBackgroundColor: '#303030',
					pointRadius: 4,
					tension: 0.25,
					fill: true
				} ]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: {
					legend: { display: false },
					tooltip: {
						callbacks: {
							label: function ( ctx ) {
								return ( i18n.avg_retention || 'Avg retention' ) + ': ' + fmtPct( ctx.parsed.y );
							}
						}
					}
				},
				scales: {
					x: {
						grid: { display: false },
						ticks: { color: '#616161', font: { size: 11 } }
					},
					y: {
						beginAtZero: true,
						suggestedMax: 100,
						ticks: {
							color: '#616161',
							font: { size: 11 },
							callback: function ( v ) { return fmtPct( v, 0 ); }
						},
						grid: { color: '#f1f1f1' }
					}
				}
			}
		} );
	}

	// =========================================================================
	// Refresh / Recompute
	// =========================================================================

	function recomputeNow() {
		var btn = el( 'bp-ca-refresh' );
		btn.disabled = true;
		var originalText = btn.textContent;
		btn.textContent = i18n.refreshing || 'Refreshing…';

		fetchJSON( 'brikpanel_ca_recompute_now' ).then( function ( res ) {
			btn.disabled = false;
			btn.textContent = originalText;
			if ( res && res.success ) {
				setMeta( res.data.meta_text );
				showToast( ( i18n.recomputed || 'Recomputed' ) + ' (' + res.data.rows_written + ' ' + ( i18n.rows || 'rows' ) + ', ' + res.data.duration + 's)' );
				reloadAll();
			} else {
				showToast( ( res && res.data && res.data.message ) || ( i18n.error || 'Could not recompute.' ), true );
			}
		} ).catch( function () {
			btn.disabled = false;
			btn.textContent = originalText;
			showToast( i18n.error || 'Could not recompute.', true );
		} );
	}

	// =========================================================================
	// Tabs (the page shell owns the strip and calls show())
	// =========================================================================

	function show( tab ) {
		var root = el( 'bp-ca' );
		if ( ! root ) { return; }
		root.querySelectorAll( '.bp-ca-tabpanel' ).forEach( function ( p ) {
			p.hidden = p.getAttribute( 'data-panel' ) !== tab;
		} );
		// Each tab loads the first time it is shown, never before: a page opened
		// on All customers asks for none of this.
		if ( tab === 'ltv' && ! state.ltvLoaded ) {
			state.ltvLoaded = true;
			loadSummary();
			loadTopCustomers();
			loadHistogram();
		}
		if ( tab === 'rfm' && ! state.rfmLoaded ) {
			state.rfmLoaded = true;
			loadRfmSummary();
		}
		if ( tab === 'cohort' && ! state.cohortLoaded ) {
			state.cohortLoaded = true;
			loadCohort();
		}
	}

	// After a recompute or new exclusions: every tab already loaded, and the
	// customer list, which leaves excluded people out too.
	function reloadAll() {
		if ( state.ltvLoaded ) {
			loadSummary();
			loadTopCustomers();
			loadHistogram();
		}
		if ( state.rfmLoaded ) { loadRfmSummary(); }
		if ( state.cohortLoaded ) { loadCohort(); }
		if ( window.brikpanelSegmentsApi && typeof window.brikpanelSegmentsApi.reload === 'function' ) {
			window.brikpanelSegmentsApi.reload();
		}
	}

	// =========================================================================
	// Exclude-customers modal
	// =========================================================================

	var excl = {
		users: [],          // [{id,name,email,roles}]
		roles: [],          // selected role slugs
		availableRoles: [], // [{slug,label,count}]
		searchTimer: null,
		loaded: false
	};

	function updateExclBadge( count ) {
		var badge = el( 'bp-ca-excl-badge' );
		if ( ! badge ) { return; }
		count = parseInt( count, 10 ) || 0;
		if ( count > 0 ) {
			badge.textContent = count;
			badge.hidden = false;
		} else {
			badge.hidden = true;
		}
	}

	function renderExclChips() {
		var box = el( 'bp-ca-excl-chips' );
		if ( ! box ) { return; }
		if ( ! excl.users.length ) {
			box.innerHTML = '<div class="bp-ca-excl-empty">' + escapeHtml( i18n.excl_none_people || 'No people excluded yet.' ) + '</div>';
			return;
		}
		box.innerHTML = excl.users.map( function ( u ) {
			var sub = u.email || u.roles || '';
			return '<span class="bp-ca-chip" data-id="' + u.id + '">' +
				'<span class="bp-ca-chip-main">' + escapeHtml( u.name ) + '</span>' +
				( sub ? '<span class="bp-ca-chip-sub">' + escapeHtml( sub ) + '</span>' : '' ) +
				'<button type="button" class="bp-ca-chip-x" data-id="' + u.id + '" aria-label="' + escapeHtml( i18n.excl_remove || 'Remove' ) + '">&times;</button>' +
				'</span>';
		} ).join( '' );
		box.querySelectorAll( '.bp-ca-chip-x' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var id = parseInt( btn.getAttribute( 'data-id' ), 10 );
				excl.users = excl.users.filter( function ( u ) { return u.id !== id; } );
				renderExclChips();
			} );
		} );
	}

	function renderExclRoles() {
		var box = el( 'bp-ca-excl-roles' );
		if ( ! box ) { return; }
		box.innerHTML = excl.availableRoles.map( function ( r ) {
			var checked = excl.roles.indexOf( r.slug ) !== -1 ? ' checked' : '';
			var count = r.count ? ' <span class="bp-ca-role-count">' + r.count + ' ' + escapeHtml( i18n.members || 'members' ) + '</span>' : '';
			return '<label class="bp-ca-role-row">' +
				'<input type="checkbox" value="' + escapeHtml( r.slug ) + '"' + checked + '>' +
				'<span class="bp-ca-role-label">' + escapeHtml( r.label ) + count + '</span>' +
				'</label>';
		} ).join( '' );
		box.querySelectorAll( 'input[type=checkbox]' ).forEach( function ( cb ) {
			cb.addEventListener( 'change', function () {
				if ( cb.checked ) {
					if ( excl.roles.indexOf( cb.value ) === -1 ) { excl.roles.push( cb.value ); }
				} else {
					excl.roles = excl.roles.filter( function ( s ) { return s !== cb.value; } );
				}
			} );
		} );
	}

	function renderExclResults( users ) {
		var box = el( 'bp-ca-excl-results' );
		if ( ! box ) { return; }
		var existing = excl.users.map( function ( u ) { return u.id; } );
		var list = users.filter( function ( u ) { return existing.indexOf( u.id ) === -1; } );
		if ( ! list.length ) {
			box.innerHTML = '<div class="bp-ca-excl-result is-empty">' + escapeHtml( i18n.excl_no_results || 'No matching users.' ) + '</div>';
			box.hidden = false;
			return;
		}
		box.innerHTML = list.map( function ( u ) {
			return '<button type="button" class="bp-ca-excl-result" data-id="' + u.id + '">' +
				'<span class="bp-ca-chip-main">' + escapeHtml( u.name ) + '</span>' +
				( u.email ? '<span class="bp-ca-chip-sub">' + escapeHtml( u.email ) + '</span>' : '' ) +
				'</button>';
		} ).join( '' );
		box.hidden = false;
		box.querySelectorAll( '.bp-ca-excl-result' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var id = parseInt( btn.getAttribute( 'data-id' ), 10 );
				var picked = list.filter( function ( u ) { return u.id === id; } )[ 0 ];
				if ( picked ) {
					excl.users.push( picked );
					renderExclChips();
				}
				el( 'bp-ca-excl-search' ).value = '';
				box.hidden = true;
			} );
		} );
	}

	function searchExclUsers() {
		var term = el( 'bp-ca-excl-search' ).value.trim();
		if ( term.length < 2 ) {
			el( 'bp-ca-excl-results' ).hidden = true;
			return;
		}
		fetchJSON( 'brikpanel_ca_search_users', { q: term } ).then( function ( res ) {
			if ( res && res.success ) {
				renderExclResults( res.data.users || [] );
			}
		} ).catch( function () {} );
	}

	function openExclModal() {
		el( 'bp-ca-excl-overlay' ).hidden = false;
		document.body.classList.add( 'bp-ca-modal-open' );
		fetchJSON( 'brikpanel_ca_get_exclusions' ).then( function ( res ) {
			if ( res && res.success ) {
				excl.users = res.data.users || [];
				excl.roles = res.data.roles || [];
				excl.availableRoles = res.data.available_roles || [];
				excl.loaded = true;
				renderExclChips();
				renderExclRoles();
				updateExclBadge( res.data.resolved_count );
			}
		} ).catch( function () {} );
	}

	function closeExclModal() {
		el( 'bp-ca-excl-overlay' ).hidden = true;
		document.body.classList.remove( 'bp-ca-modal-open' );
		el( 'bp-ca-excl-results' ).hidden = true;
	}

	function saveExclusions() {
		var btn = el( 'bp-ca-excl-save' );
		var original = btn.textContent;
		btn.disabled = true;
		btn.textContent = i18n.excl_saving || 'Saving…';

		// fetchJSON appends each key verbatim, so use indexed keys for arrays.
		var params = {};
		excl.users.forEach( function ( u, idx ) { params[ 'user_ids[' + idx + ']' ] = u.id; } );
		excl.roles.forEach( function ( r, idx ) { params[ 'roles[' + idx + ']' ] = r; } );

		fetchJSON( 'brikpanel_ca_save_exclusions', params ).then( function ( res ) {
			btn.disabled = false;
			btn.textContent = original;
			if ( res && res.success ) {
				updateExclBadge( res.data.resolved_count );
				setMeta( res.data.meta_text );
				showToast( i18n.excl_saved || 'Exclusions saved' );
				closeExclModal();
				// Excluded people also leave the customer list.
				reloadAll();
			} else {
				showToast( ( res && res.data && res.data.message ) || ( i18n.error || 'Something went wrong.' ), true );
			}
		} ).catch( function () {
			btn.disabled = false;
			btn.textContent = original;
			showToast( i18n.error || 'Something went wrong.', true );
		} );
	}

	function bindExclModal() {
		var openBtn = el( 'bp-ca-exclude' );
		if ( ! openBtn ) { return; }
		openBtn.addEventListener( 'click', openExclModal );
		el( 'bp-ca-excl-close' ).addEventListener( 'click', closeExclModal );
		el( 'bp-ca-excl-cancel' ).addEventListener( 'click', closeExclModal );
		el( 'bp-ca-excl-save' ).addEventListener( 'click', saveExclusions );
		el( 'bp-ca-excl-overlay' ).addEventListener( 'click', function ( e ) {
			if ( e.target === el( 'bp-ca-excl-overlay' ) ) { closeExclModal(); }
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && ! el( 'bp-ca-excl-overlay' ).hidden ) { closeExclModal(); }
		} );
		var search = el( 'bp-ca-excl-search' );
		search.addEventListener( 'input', function () {
			clearTimeout( excl.searchTimer );
			excl.searchTimer = setTimeout( searchExclUsers, 250 );
		} );

		// Populate the header badge once, without opening the modal.
		fetchJSON( 'brikpanel_ca_get_exclusions' ).then( function ( res ) {
			if ( res && res.success ) { updateExclBadge( res.data.resolved_count ); }
		} ).catch( function () {} );
	}

	// =========================================================================
	// Init
	// =========================================================================

	function init() {
		bindExclModal();

		var refresh = el( 'bp-ca-refresh' );
		if ( refresh ) { refresh.addEventListener( 'click', recomputeNow ); }
		el( 'bp-ca-prev' ).addEventListener( 'click', function () {
			if ( state.topPage > 1 ) {
				state.topPage--;
				loadTopCustomers();
			}
		} );
		el( 'bp-ca-next' ).addEventListener( 'click', function () {
			state.topPage++;
			loadTopCustomers();
		} );

		// Cohort tab buttons
		el( 'bp-ca-cohort-window' ).addEventListener( 'change', function ( e ) {
			state.cohortMonths = parseInt( e.target.value, 10 ) || 12;
			loadCohort();
		} );
		el( 'bp-ca-cohort-export' ).addEventListener( 'click', function () {
			window.location.href = CFG.cohort_export_url;
		} );

		window.brikpanelCustomerAnalytics = { show: show, reload: reloadAll };
		if ( [ 'ltv', 'rfm', 'cohort' ].indexOf( CFG.initial_tab ) !== -1 ) {
			show( CFG.initial_tab );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
