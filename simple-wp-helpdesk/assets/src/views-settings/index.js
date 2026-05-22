// simple-wp-helpdesk/assets/src/views-settings/index.js
import { render } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { ViewsSettingsPage } from './ViewsSettingsPage';

apiFetch.use(
	apiFetch.createNonceMiddleware( window.swhViewsSettings?.restNonce || '' )
);
apiFetch.use(
	apiFetch.createRootURLMiddleware(
		window.swhViewsSettings?.restUrl || '/wp-json/swh/v1/'
	)
);

const mount = () => {
	const root = document.getElementById( 'swh-views-settings-root' );
	if ( ! root ) {
		return;
	}
	render( <ViewsSettingsPage />, root );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
