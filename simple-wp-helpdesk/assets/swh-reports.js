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
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( json.success ) { return json.data; }
				return null;
			} )
			.catch( function () { return null; } );
	}

	// Status breakdown — doughnut chart.
	fetchReport( 'status_breakdown' ).then( function ( data ) {
		var ctx      = document.getElementById( 'swh-chart-status' );
		var emptyEl  = document.getElementById( 'swh-chart-status-empty' );
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
	} );

	// Weekly trend — bar chart.
	fetchReport( 'weekly_trend' ).then( function ( data ) {
		var ctx     = document.getElementById( 'swh-chart-trend' );
		var emptyEl = document.getElementById( 'swh-chart-trend-empty' );
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
	} );

	// Average resolution time.
	fetchReport( 'avg_resolution_time' ).then( function ( data ) {
		var el = document.getElementById( 'swh-avg-resolution' );
		if ( ! el ) { return; }
		el.textContent = data ? formatDuration( data.avg_seconds ) + ( data.count ? ' (' + data.count + ' tickets)' : '' ) : 'N/A';
	} );

	// Average first response time.
	fetchReport( 'first_response_time' ).then( function ( data ) {
		var el = document.getElementById( 'swh-avg-first-response' );
		if ( ! el ) { return; }
		el.textContent = data ? formatDuration( data.avg_seconds ) + ( data.count ? ' (' + data.count + ' tickets)' : '' ) : 'N/A';
	} );
} );
