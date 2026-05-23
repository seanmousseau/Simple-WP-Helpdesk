/**
 * Reports v2 progressive enhancement.
 *
 * - Persists selected range in localStorage (swh_reports_range).
 * - Re-fetches each report when range changes.
 * - Paints delta chips on each card (good/bad/neutral coloring per report type).
 * - Wires drill-down click handlers: chart segment → inbox URL hash navigation.
 */

import './style.scss';

const STORAGE_KEY = 'swh_reports_range';
const PRESETS = {
	'7d': () => ( { start: daysAgo( 7 ), end: today(), mode: 'shift' } ),
	'30d': () => ( { start: daysAgo( 30 ), end: today(), mode: 'shift' } ),
	'90d': () => ( { start: daysAgo( 90 ), end: today(), mode: 'shift' } ),
	ytd: () => ( { start: yearStart(), end: today(), mode: 'ytd' } ),
};

// Whether higher numbers are "good" (green) or "bad" (red) per report type.
// For categorical reports, semantics vary per segment (handled in renderDeltaChip).
const DIRECTION = {
	'status_breakdown:open': 'bad',
	'status_breakdown:in_progress': 'neutral',
	'status_breakdown:resolved': 'good',
	'status_breakdown:closed': 'good',
	avg_resolution_time: 'bad',
	first_response_time: 'bad',
	weekly_trend: 'neutral',
	kpi: 'neutral',
};

const today = () => new Date().toISOString().slice( 0, 10 );
const daysAgo = ( n ) => {
	const d = new Date();
	d.setUTCDate( d.getUTCDate() - n );
	return d.toISOString().slice( 0, 10 );
};
const yearStart = () => new Date().toISOString().slice( 0, 4 ) + '-01-01';

const getSavedRange = () => {
	try {
		return JSON.parse( localStorage.getItem( STORAGE_KEY ) || 'null' );
	} catch ( e ) {
		return null;
	}
};
const saveRange = ( range ) => {
	try {
		localStorage.setItem( STORAGE_KEY, JSON.stringify( range ) );
	} catch ( e ) {
		// Silently ignore storage errors (private browsing, quota exceeded).
	}
};

const formatDelta = ( pct ) => {
	if ( pct === null || pct === undefined ) return '— no prior data';
	const arrow = pct > 0 ? '↑' : pct < 0 ? '↓' : '→';
	return `${ arrow } ${ Math.abs( pct ).toFixed( 1 ) }%`;
};

const directionClass = ( reportType, segment, delta ) => {
	if ( delta === null || delta === undefined || delta === 0 ) return 'is-neutral';
	const key = segment ? `${ reportType }:${ segment }` : reportType;
	const dir = DIRECTION[ key ] || DIRECTION[ reportType ] || 'neutral';
	if ( dir === 'neutral' ) return 'is-neutral';
	// If "higher is bad" → positive delta = bad, negative = good.
	if ( dir === 'bad' ) return delta > 0 ? 'is-bad' : 'is-good';
	if ( dir === 'good' ) return delta > 0 ? 'is-good' : 'is-bad';
	return 'is-neutral';
};

const fetchReport = async ( type, range ) => {
	const url =
		( window.swhReports && window.swhReports.ajaxUrl ) ||
		window.ajaxurl ||
		'/wp-admin/admin-ajax.php';
	const nonce = ( window.swhReports && window.swhReports.nonce ) || '';
	const params = new URLSearchParams( {
		action: 'swh_report_data',
		nonce,
		type,
		range_start: range.start,
		range_end: range.end,
		compare_to_prior: '1',
	} );
	const response = await fetch( url, {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: params.toString(),
	} );
	if ( ! response.ok ) throw new Error( `HTTP ${ response.status }` );
	const json = await response.json();
	if ( ! json.success ) throw new Error( json.data?.message || 'Server error' );
	return json.data;
};

const renderDeltaChip = ( reportType, payload ) => {
	const slot = document.querySelector(
		`[data-delta-for="${ reportType }"]`
	);
	if ( ! slot ) return;

	const data = payload && payload.data;
	const prior = payload && payload.prior;

	if ( data === null || data === undefined ) {
		slot.textContent = '— no prior data';
		slot.className = 'swh-report-delta is-neutral';
		return;
	}

	if ( typeof data === 'number' ) {
		const priorNum = typeof prior === 'number' ? prior : null;
		if ( priorNum === null || priorNum === 0 ) {
			slot.textContent = '— no prior data';
			slot.className = 'swh-report-delta is-neutral';
			return;
		}
		const delta = ( ( data - priorNum ) / priorNum ) * 100;
		slot.textContent = formatDelta( delta );
		slot.className = `swh-report-delta ${ directionClass( reportType, null, delta ) }`;
		return;
	}

	// Object (e.g. status_breakdown returns { open: N, resolved: M, ... }).
	if ( typeof data === 'object' && prior && typeof prior === 'object' ) {
		const currentTotal = Object.values( data ).reduce(
			( a, b ) => a + ( Number( b ) || 0 ),
			0
		);
		const priorTotal = Object.values( prior ).reduce(
			( a, b ) => a + ( Number( b ) || 0 ),
			0
		);
		if ( priorTotal === 0 ) {
			slot.textContent = '— no prior data';
			slot.className = 'swh-report-delta is-neutral';
			return;
		}
		const delta = ( ( currentTotal - priorTotal ) / priorTotal ) * 100;
		slot.textContent = `Total ${ formatDelta( delta ) }`;
		slot.className = `swh-report-delta ${ directionClass( reportType, null, delta ) }`;
		return;
	}

	// Nested data object (avg_resolution_time returns { avg_seconds, count }).
	if ( typeof data === 'object' && data !== null && 'avg_seconds' in data ) {
		const priorObj =
			prior && typeof prior === 'object' && 'avg_seconds' in prior
				? prior
				: null;
		if ( ! priorObj || priorObj.avg_seconds === 0 ) {
			slot.textContent = '— no prior data';
			slot.className = 'swh-report-delta is-neutral';
			return;
		}
		const delta =
			( ( data.avg_seconds - priorObj.avg_seconds ) /
				priorObj.avg_seconds ) *
			100;
		slot.textContent = formatDelta( delta );
		slot.className = `swh-report-delta ${ directionClass( reportType, null, delta ) }`;
		return;
	}

	slot.textContent = '— no prior data';
	slot.className = 'swh-report-delta is-neutral';
};

const priorSpan = () => document.querySelector( '[data-prior]' );
const currentSpan = () => document.querySelector( '[data-current]' );

const refreshAll = async ( range ) => {
	const cards = document.querySelectorAll(
		'.swh-report-card[data-report-type]'
	);
	const curSpan = currentSpan();
	const priSpan = priorSpan();
	if ( curSpan ) curSpan.textContent = `${ range.start } → ${ range.end }`;
	if ( priSpan ) priSpan.textContent = '…';

	const fetches = Array.from( cards ).map( ( card ) => {
		const type = card.getAttribute( 'data-report-type' );
		return fetchReport( type, range )
			.then( ( payload ) => {
				renderDeltaChip( type, payload );
				if ( payload.prior_range && priSpan ) {
					priSpan.textContent = `${ payload.prior_range.start } → ${ payload.prior_range.end }`;
				}
				// Notify any chart hooks that fresh data is available.
				// The existing chart-renderer code subscribes via `swh:report-refresh`.
				card.dispatchEvent(
					new CustomEvent( 'swh:report-refresh', {
						detail: { type, payload, range },
						bubbles: true,
					} )
				);
			} )
			.catch( ( err ) => {
				// eslint-disable-next-line no-console
				console.error( '[swh-reports] report fetch failed', { type, err } );
			} );
	} );
	await Promise.all( fetches );
};

const setActiveRange = ( key, range ) => {
	document.querySelectorAll( '.swh-range-btn' ).forEach( ( b ) =>
		b.classList.toggle(
			'is-active',
			b.getAttribute( 'data-range' ) === key
		)
	);
	const custom = document.querySelector( '.swh-range-custom' );
	if ( custom ) custom.hidden = key !== 'custom';
	refreshAll( range );
};

const wireRangeSelector = () => {
	document.querySelectorAll( '.swh-range-btn' ).forEach( ( btn ) => {
		btn.addEventListener( 'click', () => {
			const key = btn.getAttribute( 'data-range' );
			if ( ! key ) return;
			if ( key === 'custom' ) {
				setActiveRange( key, getSavedRange() || PRESETS[ '30d' ]() );
				return;
			}
			const preset = PRESETS[ key ];
			const range = preset ? preset() : PRESETS[ '30d' ]();
			saveRange( { ...range, preset: key } );
			setActiveRange( key, range );
		} );
	} );

	const applyBtn = document.querySelector( '.swh-range-apply' );
	if ( applyBtn ) {
		applyBtn.addEventListener( 'click', () => {
			const fromEl = document.querySelector( '.swh-range-from' );
			const toEl = document.querySelector( '.swh-range-to' );
			const start = fromEl ? fromEl.value : '';
			const end = toEl ? toEl.value : '';
			if ( ! start || ! end ) return;
			const range = { start, end, mode: 'shift' };
			saveRange( { ...range, preset: 'custom' } );
			setActiveRange( 'custom', range );
		} );
	}
};

const wireDrillDown = () => {
	document
		.querySelectorAll(
			'.swh-report-card[data-filter-key] [data-clickable="1"]'
		)
		.forEach( ( chart ) => {
			chart.addEventListener( 'click', ( e ) => {
				const card = chart.closest(
					'.swh-report-card[data-filter-key]'
				);
				if ( ! card ) return;
				const filterKey = card.getAttribute( 'data-filter-key' );
				const targetEl = e.target.closest( '[data-filter-value]' );
				const filterValue = targetEl
					? targetEl.getAttribute( 'data-filter-value' )
					: null;
				if ( ! filterValue ) return;
				const range = getSavedRange() || PRESETS[ '30d' ]();
				const hash = `#filter=${ filterKey }:${ filterValue }&range_start=${ range.start }&range_end=${ range.end }`;
				const inboxUrl =
					( window.swhReports && window.swhReports.inboxUrl ) ||
					location.pathname.replace(
						/\/wp-admin\/.*/,
						'/wp-admin/'
					) +
						'edit.php?post_type=helpdesk_ticket';
				window.location.assign( inboxUrl + hash );
			} );
		} );
};

const init = () => {
	if ( ! document.querySelector( '.swh-reports-range-bar' ) ) return;
	wireRangeSelector();
	wireDrillDown();

	// Initial fetch using saved or default range.
	const saved = getSavedRange();
	const initialKey = ( saved && saved.preset ) || '30d';
	if ( initialKey === 'custom' && saved ) {
		const fromEl = document.querySelector( '.swh-range-from' );
		const toEl = document.querySelector( '.swh-range-to' );
		if ( fromEl ) fromEl.value = saved.start;
		if ( toEl ) toEl.value = saved.end;
		setActiveRange( 'custom', saved );
	} else {
		const preset = PRESETS[ initialKey ];
		setActiveRange( initialKey, preset ? preset() : PRESETS[ '30d' ]() );
	}
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
