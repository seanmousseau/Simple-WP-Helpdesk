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
			} );
	}

	// Status breakdown — doughnut chart.
	fetchReport( 'status_breakdown' ).then( function ( data ) {
		if ( ! data ) { return; }
		var labels = Object.keys( data );
		var counts = labels.map( function ( k ) { return data[ k ]; } );
		var ctx    = document.getElementById( 'swh-chart-status' );
		if ( ! ctx ) { return; }
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
		if ( ! data || ! data.length ) { return; }
		var labels  = data.map( function ( d ) { return d.week; } );
		var opened  = data.map( function ( d ) { return d.opened; } );
		var closed  = data.map( function ( d ) { return d.closed; } );
		var ctx     = document.getElementById( 'swh-chart-trend' );
		if ( ! ctx ) { return; }
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
