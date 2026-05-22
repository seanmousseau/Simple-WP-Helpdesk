import './style.scss';
import './drawer.scss';
import './palette.scss';
import './views.scss';
import { render } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { registerInboxStore } from './store/inbox';
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

registerInboxStore();
registerDrawerStore();
registerPaletteStore();
registerViewsStore();

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
