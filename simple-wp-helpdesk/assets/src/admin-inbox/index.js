import { render } from '@wordpress/element';

const Inbox = () => (
	<div className="swh-inbox-loading">
		<p>Loading inbox…</p>
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
