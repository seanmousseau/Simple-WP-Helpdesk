// simple-wp-helpdesk/assets/src/views-settings/index.js
import { render } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { ViewsSettingsPage } from './ViewsSettingsPage';

apiFetch.use(
	apiFetch.createNonceMiddleware( window.swhViewsSettings?.restNonce || '' )
);
// Use the site REST root (e.g. /wp-json/) rather than the namespace root
// (/wp-json/swh/v1/). apiFetch request paths already include /swh/v1/…, so
// combining with a namespace-prefixed root would produce duplicated segments.
apiFetch.use(
	apiFetch.createRootURLMiddleware(
		window.swhViewsSettings?.restRoot || '/wp-json/'
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
