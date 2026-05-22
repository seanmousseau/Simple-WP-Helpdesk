import { render } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { registerInboxStore } from './store/inbox';

apiFetch.use(
	apiFetch.createNonceMiddleware( window.swhInbox?.restNonce || '' )
);
apiFetch.use(
	apiFetch.createRootURLMiddleware(
		window.swhInbox?.restUrl || '/wp-json/swh/v1/'
	)
);

registerInboxStore();

const Inbox = () => (
	<div className="swh-inbox-loading">
		<p>Loading inbox… (store registered, components in next task)</p>
	</div>
);

const mountInbox = () => {
	const root = document.getElementById( 'swh-inbox-root' );
	if ( ! root ) return;
	render( <Inbox />, root );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mountInbox );
} else {
	mountInbox();
}
