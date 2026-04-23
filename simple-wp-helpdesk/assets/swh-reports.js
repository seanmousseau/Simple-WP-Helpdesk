/**
 * Simple WP Helpdesk — reporting dashboard charts.
 *
 * Fetches metrics from the swh_report_data AJAX endpoint and renders
 * Chart.js charts on the Reports admin page.
 *
 * @since 3.0.0
 * @package Simple_WP_Helpdesk
 */

/* global Chart, swhReports */

document.addEventListener( 'DOMContentLoaded', function () {
	if ( typeof swhReports === 'undefined' || typeof Chart === 'undefined' ) {
		return;
	}

	/**
	 * Formats a duration in seconds as a human-readable string ("2h 14m").
	 *
	 * @param {number} seconds - Duration in seconds.
	 * @returns {string} Formatted duration.
	 */
	function formatDuration( seconds ) {
		if ( ! seconds || seconds <= 0 ) {
			return 'N/A';
		}
		var h = Math.floor( seconds / 3600 );
		var m = Math.floor( ( seconds % 3600 ) / 60 );
		return ( h > 0 ? h + 'h ' : '' ) + m + 'm';
	}

	/**
	 * Posts to the AJAX endpoint and returns a Promise resolving to the response data.
	 *
	 * @param {string} type - The report type key.
	 * @returns {Promise<*>} Resolves with the data payload on success.
	 */
	/**
	 * Shows the empty-state placeholder and hides the canvas for a chart.
	 *
	 * @param {HTMLElement} canvas   - The chart canvas element.
	 * @param {HTMLElement} emptyEl  - The empty-state paragraph element.
	 */
	function showEmpty( canvas, emptyEl ) {
		if ( canvas ) { canvas.hidden = true; }
		if ( emptyEl ) { emptyEl.hidden = false; }
	}

	function fetchReport( type ) {
		var body = new URLSearchParams();
		body.append( 'action', 'swh_report_data' );
		body.append( 'nonce', swhReports.nonce );
		body.append( 'type', type );
		return fetch( swhReports.ajaxurl, { method: 'POST', body: body } )
			.then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( 'HTTP ' + r.status );
				}
				return r.json();
			} )
			.then( function ( json ) {
				if ( json.success ) { return json.data; }
				return { __error: true, message: ( json.data && json.data.message ) ? json.data.message : 'Report unavailable.' };
			} )
			.catch( function ( e ) {
				return { __error: true, message: ( e && e.message ) ? e.message : 'Request failed.' };
			} );
	}

	function showError( canvas, emptyEl, message ) {
		if ( canvas ) { canvas.hidden = true; }
		if ( emptyEl ) {
			emptyEl.hidden = false;
			emptyEl.textContent = message || 'Could not load report data.';
		}
	}

	// KPI summary cards — show skeletons while loading (#332, #335).
	var kpiIds = [ 'total', 'open', 'resolution', 'first-response' ];

	function showKpiSkeleton() {
		var grid = document.getElementById( 'swh-kpi-grid' );
		if ( grid ) { grid.setAttribute( 'aria-busy', 'true' ); }
		kpiIds.forEach( function ( id ) {
			var skel = document.getElementById( 'swh-kpi-' + id + '-skeleton' );
			var val  = document.getElementById( 'swh-kpi-' + id );
			if ( skel ) { skel.removeAttribute( 'hidden' ); }
			if ( val )  { val.setAttribute( 'hidden', '' ); }
		} );
	}

	function hideKpiSkeleton() {
		var grid = document.getElementById( 'swh-kpi-grid' );
		if ( grid ) { grid.setAttribute( 'aria-busy', 'false' ); }
		kpiIds.forEach( function ( id ) {
			var skel = document.getElementById( 'swh-kpi-' + id + '-skeleton' );
			var val  = document.getElementById( 'swh-kpi-' + id );
			if ( skel ) { skel.setAttribute( 'hidden', '' ); }
			if ( val )  { val.removeAttribute( 'hidden' ); }
		} );
	}

	showKpiSkeleton();
	fetchReport( 'kpi' ).then( function ( data ) {
		hideKpiSkeleton();
		if ( ! data || data.__error ) { return; }
		var total = document.getElementById( 'swh-kpi-total' );
		var open  = document.getElementById( 'swh-kpi-open' );
		var res   = document.getElementById( 'swh-kpi-resolution' );
		var frt   = document.getElementById( 'swh-kpi-first-response' );
		if ( total ) { total.textContent = data.total; }
		if ( open )  { open.textContent  = data.open; }
		if ( res )   { res.textContent   = formatDuration( data.avg_resolution ); }
		if ( frt )   { frt.textContent   = formatDuration( data.avg_first_response ); }
	} ).catch( function () {
		hideKpiSkeleton();
	} );

	// Status breakdown — doughnut chart.
	var statusCard = document.getElementById( 'swh-report-card-status' );
	var statusSkel = document.getElementById( 'swh-chart-status-skeleton' );
	if ( statusCard ) { statusCard.setAttribute( 'aria-busy', 'true' ); }

	fetchReport( 'status_breakdown' ).then( function ( data ) {
		if ( statusCard ) { statusCard.setAttribute( 'aria-busy', 'false' ); }
		if ( statusSkel ) { statusSkel.setAttribute( 'hidden', '' ); }
		var ctx      = document.getElementById( 'swh-chart-status' );
		var emptyEl  = document.getElementById( 'swh-chart-status-empty' );
		if ( ctx ) { ctx.removeAttribute( 'hidden' ); }
		if ( data && data.__error ) { showError( ctx, emptyEl, data.message ); return; }
		var isEmpty  = ! data || ! Object.keys( data ).length || Object.values( data ).every( function ( v ) { return v === 0; } );
		if ( ! ctx || isEmpty ) { showEmpty( ctx, emptyEl ); return; }
		var labels = Object.keys( data );
		var counts = labels.map( function ( k ) { return data[ k ]; } );
		new Chart( ctx, {
			type: 'doughnut',
			data: {
				labels: labels,
				datasets: [ { data: counts } ],
			},
			options: { plugins: { legend: { position: 'bottom' } } },
		} );
	} ).catch( function () {
		if ( statusCard ) { statusCard.setAttribute( 'aria-busy', 'false' ); }
		if ( statusSkel ) { statusSkel.setAttribute( 'hidden', '' ); }
		var ctx     = document.getElementById( 'swh-chart-status' );
		var emptyEl = document.getElementById( 'swh-chart-status-empty' );
		if ( ctx ) { ctx.removeAttribute( 'hidden' ); }
		showEmpty( ctx, emptyEl );
	} );

	// Weekly trend — bar chart.
	var trendCard = document.getElementById( 'swh-report-card-trend' );
	var trendSkel = document.getElementById( 'swh-chart-trend-skeleton' );
	if ( trendCard ) { trendCard.setAttribute( 'aria-busy', 'true' ); }

	fetchReport( 'weekly_trend' ).then( function ( data ) {
		if ( trendCard ) { trendCard.setAttribute( 'aria-busy', 'false' ); }
		if ( trendSkel ) { trendSkel.setAttribute( 'hidden', '' ); }
		var ctx     = document.getElementById( 'swh-chart-trend' );
		var emptyEl = document.getElementById( 'swh-chart-trend-empty' );
		if ( ctx ) { ctx.removeAttribute( 'hidden' ); }
		if ( data && data.__error ) { showError( ctx, emptyEl, data.message ); return; }
		if ( ! ctx || ! data || ! data.length ) { showEmpty( ctx, emptyEl ); return; }
		var labels  = data.map( function ( d ) { return d.week; } );
		var opened  = data.map( function ( d ) { return d.opened; } );
		var closed  = data.map( function ( d ) { return d.closed; } );
		new Chart( ctx, {
			type: 'bar',
			data: {
				labels: labels,
				datasets: [
					{ label: 'Opened', data: opened, backgroundColor: '#0073aa' },
					{ label: 'Closed', data: closed, backgroundColor: '#00a32a' },
				],
			},
			options: { plugins: { legend: { position: 'bottom' } }, scales: { x: { stacked: false } } },
		} );
	} ).catch( function () {
		if ( trendCard ) { trendCard.setAttribute( 'aria-busy', 'false' ); }
		if ( trendSkel ) { trendSkel.setAttribute( 'hidden', '' ); }
		var ctx     = document.getElementById( 'swh-chart-trend' );
		var emptyEl = document.getElementById( 'swh-chart-trend-empty' );
		if ( ctx ) { ctx.removeAttribute( 'hidden' ); }
		showEmpty( ctx, emptyEl );
	} );

	// Average resolution time.
	fetchReport( 'avg_resolution_time' ).then( function ( data ) {
		var el = document.getElementById( 'swh-avg-resolution' );
		if ( ! el ) { return; }
		el.textContent = ( data && ! data.__error ) ? formatDuration( data.avg_seconds ) + ( data.count ? ' (' + data.count + ' tickets)' : '' ) : 'N/A';
	} );

	// Average first response time.
	fetchReport( 'first_response_time' ).then( function ( data ) {
		var el = document.getElementById( 'swh-avg-first-response' );
		if ( ! el ) { return; }
		el.textContent = ( data && ! data.__error ) ? formatDuration( data.avg_seconds ) + ( data.count ? ' (' + data.count + ' tickets)' : '' ) : 'N/A';
	} );
} );
