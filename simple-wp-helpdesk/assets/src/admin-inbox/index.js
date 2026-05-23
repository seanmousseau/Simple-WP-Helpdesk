import './style.scss';
import './drawer.scss';
import './palette.scss';
import './views.scss';
import { render } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { dispatch as wpDispatch } from '@wordpress/data';
import { registerInboxStore, SWH_INBOX_STORE } from './store/inbox';
import { registerDrawerStore } from './store/drawer';
import { registerPaletteStore } from './store/palette';
import { registerViewsStore } from './store/views';
import { Inbox } from './components/Inbox';

apiFetch.use(
	apiFetch.createNonceMiddleware( window.swhInbox?.restNonce || '' )
);
apiFetch.use(
	apiFetch.createRootURLMiddleware(
		window.swhInbox?.restUrl || '/wp-json/swh/v1/'
	)
);

/**
 * Parse a URL hash produced by the reports drill-down click handler.
 *
 * Expected format: #filter=status:open&range_start=2026-04-22&range_end=2026-05-22
 *
 * @param {string} hash window.location.hash
 * @return {{ key: string, label: string, rangeStart: string|null, rangeEnd: string|null }|null} Parsed synthetic view descriptor, or null if hash has no filter.
 */
const parseHashFilter = ( hash ) => {
	if ( ! hash || hash[ 0 ] !== '#' ) {
		return null;
	}
	const params = new URLSearchParams( hash.slice( 1 ) );
	const filter = params.get( 'filter' );
	if ( ! filter ) {
		return null;
	}
	const colonIdx = filter.indexOf( ':' );
	if ( colonIdx < 1 ) {
		return null;
	}
	const filterKey = filter.slice( 0, colonIdx );
	const filterValue = filter.slice( colonIdx + 1 );
	if ( ! filterKey || ! filterValue ) {
		return null;
	}

	const rangeStart = params.get( 'range_start' ) || null;
	const rangeEnd = params.get( 'range_end' ) || null;
	const key = `${ filterKey }:${ filterValue }`;

	let label = '';
	if ( filterKey === 'status' ) {
		const human = filterValue.replace( /_/g, ' ' );
		label = human.charAt( 0 ).toUpperCase() + human.slice( 1 ) + ' tickets';
	} else {
		label = `Filtered: ${ filter }`;
	}
	if ( rangeStart && rangeEnd ) {
		label += ` · ${ rangeStart } → ${ rangeEnd }`;
	}
	return { key, label, rangeStart, rangeEnd };
};

registerInboxStore();
registerDrawerStore();
registerPaletteStore();
registerViewsStore();

// Parse URL hash set by the reports drill-down and dispatch synthetic view.
// Must run AFTER registerInboxStore() and BEFORE render(). v4.0.0 #359.
const synth = parseHashFilter( window.location.hash );
if ( synth ) {
	wpDispatch( SWH_INBOX_STORE ).setSyntheticView( synth );
}

const mountInbox = () => {
	const root = document.getElementById( 'swh-inbox-root' );
	if ( ! root ) {
		return;
	}
	render( <Inbox />, root );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mountInbox );
} else {
	mountInbox();
}
