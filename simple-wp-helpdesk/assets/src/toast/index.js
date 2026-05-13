/**
 * Simple WP Helpdesk — Toast notifications.
 *
 * v3.7.0 proof-of-concept consumer for the @wordpress/scripts build system
 * (issue #390). Ports the existing inline `swhToast()` function out of
 * `assets/swh-admin.js` so future v4.x JS work can be authored as ES modules
 * and bundled via `@wordpress/scripts`.
 *
 * The public global `window.swhToast` retains its original signature and
 * visual behaviour — CSS lives in `assets/swh-admin.css` and is unchanged.
 *
 * @since 3.7.0
 * @package Simple_WP_Helpdesk
 */

/* global swhAdmin */

/**
 * Displays a transient toast notification in the bottom-right corner.
 *
 * @param {string}                     message    Text to display.
 * @param {'success'|'error'|'info'}   [type]     Visual variant. Defaults to 'success'.
 * @param {number}                     [duration] Auto-dismiss delay in milliseconds. Defaults to 4000.
 */
function swhToast( message, type, duration ) {
	const allowed = [ 'success', 'error', 'info' ];
	type = ( allowed.indexOf( type ) !== -1 ) ? type : 'success';
	duration = duration || 4000;

	const dismissLabel = ( typeof swhAdmin !== 'undefined' && swhAdmin.i18n && swhAdmin.i18n.dismissNotification )
		? swhAdmin.i18n.dismissNotification
		: 'Dismiss';

	const toast = document.createElement( 'div' );
	toast.className = 'swh-toast swh-toast--' + type;
	toast.setAttribute( 'role', 'status' );
	toast.setAttribute( 'aria-live', 'polite' );
	toast.setAttribute( 'aria-atomic', 'true' );

	const msg = document.createElement( 'span' );
	msg.className = 'swh-toast__message';
	msg.textContent = message;

	const btn = document.createElement( 'button' );
	btn.className = 'swh-toast__dismiss';
	btn.type = 'button';
	btn.setAttribute( 'aria-label', dismissLabel );
	btn.textContent = '×';

	toast.appendChild( msg );
	toast.appendChild( btn );
	document.body.appendChild( toast );

	let timer;

	function dismiss() {
		clearTimeout( timer );
		toast.classList.remove( 'swh-toast--visible' );
		toast.addEventListener( 'transitionend', function () { toast.remove(); }, { once: true } );
	}

	btn.addEventListener( 'click', dismiss );

	requestAnimationFrame( function () {
		requestAnimationFrame( function () {
			toast.classList.add( 'swh-toast--visible' );
		} );
	} );

	timer = setTimeout( dismiss, duration );
}

// Expose globally for legacy callers (inline scripts, swh-admin.js, etc.).
window.swhToast = swhToast;

export { swhToast };
