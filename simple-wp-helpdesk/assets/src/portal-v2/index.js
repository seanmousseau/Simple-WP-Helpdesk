/**
 * Portal v2 progressive enhancement — vanilla JS, no React.
 *
 * - Scrolls thread to bottom on page load.
 * - Form submit → fetch POST → optimistic bubble append.
 * - ⋮ menu open/close + outside-click + Esc dismiss.
 * - Cmd/Ctrl+Enter submits from textarea.
 * - ISO timestamps → relative "X ago" labels.
 * - Falls back to standard non-AJAX POST if JS fails.
 */

import './style.scss';

const createBubble = ( text, kind, timeLabel ) => {
	const article = document.createElement( 'article' );
	article.className = `swh-msg swh-msg-${ kind }`;
	const bubble = document.createElement( 'div' );
	bubble.className = 'swh-msg-bubble';
	bubble.textContent = text;
	const meta = document.createElement( 'div' );
	meta.className = 'swh-msg-meta';
	meta.textContent = timeLabel;
	article.appendChild( bubble );
	article.appendChild( meta );
	return article;
};

const formatRelative = ( iso ) => {
	if ( ! iso ) {
		return '';
	}
	const diff = Math.max(
		0,
		Math.floor( ( Date.now() - new Date( iso ).getTime() ) / 1000 )
	);
	if ( diff < 60 ) {
		return `${ diff }s ago`;
	}
	if ( diff < 3600 ) {
		return `${ Math.floor( diff / 60 ) }m ago`;
	}
	if ( diff < 86400 ) {
		return `${ Math.floor( diff / 3600 ) }h ago`;
	}
	return `${ Math.floor( diff / 86400 ) }d ago`;
};

const appendHiddenField = ( form, name, value ) => {
	const input = document.createElement( 'input' );
	input.type = 'hidden';
	input.name = name;
	input.value = value;
	form.appendChild( input );
};

const runMenuAction = ( root, action ) => {
	const ticketId = root.querySelector( 'input[name="ticket_id"]' )?.value;
	const token = root.querySelector( 'input[name="token"]' )?.value;
	const composer = root.querySelector( '.swh-portal-v2-composer' );
	// getAttribute() — composer.action is shadowed by <input name="action">.
	const actionURL = composer?.getAttribute( 'action' );
	const nonceField = composer?.querySelector(
		'input[name="swh_portal_reply_nonce"]'
	);
	if ( ! ticketId || ! token || ! actionURL ) {
		return;
	}

	// eslint-disable-next-line no-alert
	if ( action === 'close' && ! window.confirm( 'Close this ticket?' ) ) {
		return;
	}

	const f = document.createElement( 'form' );
	f.method = 'post';
	f.action = actionURL;
	appendHiddenField( f, 'action', `swh_portal_${ action }` );
	appendHiddenField( f, 'ticket_id', ticketId );
	appendHiddenField( f, 'token', token );
	if ( nonceField ) {
		appendHiddenField( f, nonceField.name, nonceField.value );
	}
	document.body.appendChild( f );
	f.submit();
};

const init = () => {
	const root = document.querySelector( '.swh-portal-v2' );
	if ( ! root ) {
		return;
	}

	const thread = root.querySelector( '#swh-portal-thread' );
	const form = root.querySelector( '.swh-portal-v2-composer' );
	const ta = form?.querySelector( 'textarea' );
	const fileInput = form?.querySelector( 'input[type="file"]' );
	const attachList = form?.querySelector( '.swh-portal-v2-attachment-list' );
	const errorBox = form?.querySelector( '.swh-portal-v2-composer-error' );
	const menuTrigger = root.querySelector( '.swh-portal-v2-menu-trigger' );
	const menu = root.querySelector( '.swh-portal-v2-menu' );

	const ACCEPTED_EXT = [ 'jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt' ];
	const maxMb = parseInt( fileInput?.dataset.maxMb || '5', 10 );
	const maxCount = parseInt( fileInput?.dataset.maxCount || '5', 10 );
	let selectedFiles = [];

	const showError = ( msg ) => {
		if ( ! errorBox ) return;
		errorBox.textContent = msg || '';
		errorBox.hidden = ! msg;
	};

	const renderAttachmentList = () => {
		if ( ! attachList ) return;
		// Clear children safely without innerHTML
		while ( attachList.firstChild ) {
			attachList.removeChild( attachList.firstChild );
		}
		if ( selectedFiles.length === 0 ) {
			attachList.hidden = true;
			return;
		}
		attachList.hidden = false;
		selectedFiles.forEach( ( f, idx ) => {
			const li = document.createElement( 'li' );
			li.className = 'swh-portal-v2-attachment-chip';
			const sizeKb = Math.round( f.size / 1024 );
			const label = document.createElement( 'span' );
			label.textContent = `📎 ${ f.name } (${ sizeKb }KB)`;
			li.appendChild( label );
			const rm = document.createElement( 'button' );
			rm.type = 'button';
			rm.className = 'swh-portal-v2-attachment-remove';
			rm.setAttribute( 'aria-label', `Remove ${ f.name }` );
			rm.textContent = '×';
			rm.addEventListener( 'click', () => {
				selectedFiles.splice( idx, 1 );
				syncInputFromSelected();
				renderAttachmentList();
				showError( '' );
			} );
			li.appendChild( rm );
			attachList.appendChild( li );
		} );
	};

	const syncInputFromSelected = () => {
		if ( ! fileInput ) return;
		const dt = new DataTransfer();
		selectedFiles.forEach( ( f ) => dt.items.add( f ) );
		fileInput.files = dt.files;
	};

	if ( fileInput ) {
		fileInput.addEventListener( 'change', () => {
			showError( '' );
			const incoming = Array.from( fileInput.files || [] );
			const errors = [];
			const accepted = [];
			for ( const f of incoming ) {
				const ext = ( f.name.split( '.' ).pop() || '' ).toLowerCase();
				if ( ! ACCEPTED_EXT.includes( ext ) ) {
					errors.push( `${ f.name }: file type not allowed` );
					continue;
				}
				if ( f.size > maxMb * 1024 * 1024 ) {
					errors.push( `${ f.name }: exceeds ${ maxMb }MB limit` );
					continue;
				}
				accepted.push( f );
			}
			const dedup = new Map();
			[ ...selectedFiles, ...accepted ].forEach( ( f ) => dedup.set( `${ f.name }:${ f.size }`, f ) );
			selectedFiles = Array.from( dedup.values() );
			if ( selectedFiles.length > maxCount ) {
				errors.push( `Too many files — max ${ maxCount }. Extras were dropped.` );
				selectedFiles = selectedFiles.slice( 0, maxCount );
			}
			syncInputFromSelected();
			renderAttachmentList();
			if ( errors.length ) showError( errors.join( ' · ' ) );
		} );
	}

	// Scroll to bottom on load.
	if ( thread ) {
		thread.scrollTop = thread.scrollHeight;
	}

	// Swap ISO timestamps to relative labels.
	root.querySelectorAll( '.swh-msg-time[datetime]' ).forEach( ( el ) => {
		const iso = el.getAttribute( 'datetime' );
		el.textContent = formatRelative( iso );
	} );

	// Optimistic submit via fetch.
	if ( form && thread && ta ) {
		form.addEventListener( 'submit', async ( e ) => {
			e.preventDefault();
			const text = ta.value.trim();
			if ( ! text && selectedFiles.length === 0 ) {
				return; // empty reply with no attachments — silent no-op
			}

			showError( '' );
			const bubbleText = text || `📎 ${ selectedFiles.length } file${ selectedFiles.length === 1 ? '' : 's' }`;
			const optimistic = createBubble( bubbleText, 'customer', 'just now' );
			thread.appendChild( optimistic );
			thread.scrollTop = thread.scrollHeight;
			ta.value = '';

			try {
				const data = new FormData( form );
				data.set( 'reply_body', text );
				const actionUrl = form.getAttribute( 'action' );
				const response = await fetch( actionUrl, {
					method: 'POST',
					body: data,
					credentials: 'same-origin',
					headers: {
						Accept: 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
					},
				} );
				if ( ! response.ok ) {
					throw new Error( `Server returned ${ response.status }` );
				}
				optimistic.classList.add( 'swh-msg-sent' );
				selectedFiles = [];
				syncInputFromSelected();
				renderAttachmentList();
			} catch ( err ) {
				optimistic.classList.add( 'swh-msg-failed' );
				ta.value = text;
				showError( `Send failed: ${ err.message || 'network error' }. Your text was restored.` );
			}
		} );

		// Cmd/Ctrl+Enter submits.
		ta.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Enter' && ( e.metaKey || e.ctrlKey ) ) {
				e.preventDefault();
				form.requestSubmit();
			}
		} );
	}

	// ⋮ menu open/close + outside-click + Esc.
	if ( menuTrigger && menu ) {
		const closeMenu = () => {
			menu.hidden = true;
			menuTrigger.setAttribute( 'aria-expanded', 'false' );
		};
		const openMenu = () => {
			menu.hidden = false;
			menuTrigger.setAttribute( 'aria-expanded', 'true' );
		};

		menuTrigger.addEventListener( 'click', ( e ) => {
			e.stopPropagation();
			if ( menu.hidden ) {
				openMenu();
			} else {
				closeMenu();
			}
		} );
		document.addEventListener( 'click', ( e ) => {
			if ( menu.hidden ) {
				return;
			}
			if (
				! menu.contains( e.target ) &&
				! menuTrigger.contains( e.target )
			) {
				closeMenu();
			}
		} );
		document.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Escape' && ! menu.hidden ) {
				closeMenu();
			}
		} );

		// Menu item actions.
		menu.querySelectorAll( 'button[data-action]' ).forEach( ( btn ) => {
			btn.addEventListener( 'click', () => {
				const action = btn.getAttribute( 'data-action' );
				if ( action ) {
					runMenuAction( root, action );
				}
			} );
		} );
	}
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
