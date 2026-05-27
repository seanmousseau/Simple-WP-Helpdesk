/**
 * Simple WP Helpdesk — API key management UI.
 *
 * v4.1.0 Phase 6 (#365 UI half). Wires the Integrations tab generate form,
 * the shown-once plaintext modal, and the revoke confirmation modal to the
 * `swh_api_key_generate` / `swh_api_key_revoke` admin-AJAX endpoints.
 *
 * Security invariants enforced here:
 *  - Plaintext is held in a closure-scoped variable + a readonly input only
 *    while the modal is open. It is wiped from BOTH on close.
 *  - Plaintext is never written to localStorage, sessionStorage, or window.
 *  - Rows are built with explicit DOM nodes (textContent + setAttribute) —
 *    no innerHTML, no interpolated user data, no DOMPurify dependency.
 *  - The nonce + AJAX URL come from the localized swhApiKeys global.
 *
 * @since 4.1.0
 * @package Simple_WP_Helpdesk
 */

/* global swhApiKeys, swhToast */

( function () {
	'use strict';

	if ( typeof swhApiKeys === 'undefined' ) {
		return;
	}

	var i18n = swhApiKeys.i18n || {};

	/** Mutable closure state for the secret modal. */
	var modal = {
		plaintext: null, // String while modal open; null when closed.
		copied:    false,
		opener:    null, // Element to restore focus to on close.
	};

	/** Currently-targeted revoke entry. */
	var revokeTarget = { id: null, label: null, opener: null };

	/** DOM refs — resolved on DOMContentLoaded. */
	var refs = {};

	// ---------------------------------------------------------------------
	// Utilities
	// ---------------------------------------------------------------------

	/**
	 * Replace %s / %d in a translated string with one argument.
	 *
	 * @param {string} template Translated template containing %s or %d.
	 * @param {*}      value    Substitution value.
	 * @returns {string}
	 */
	function format( template, value ) {
		if ( typeof template !== 'string' ) {
			return '';
		}
		return template.replace( /%[sd]/, String( value ) );
	}

	/**
	 * Format a unix timestamp using the operator's WordPress date_format
	 * (a small subset — Y, m, d, F, j). Good enough for table cells.
	 *
	 * @param {number} ts
	 * @returns {string}
	 */
	function formatDate( ts ) {
		if ( ! ts ) {
			return '—';
		}
		var d = new Date( ts * 1000 );
		var fmt = swhApiKeys.dateFormat || 'Y-m-d';
		var pad = function ( n ) {
			return n < 10 ? '0' + n : String( n );
		};
		var months = [ 'January', 'February', 'March', 'April', 'May', 'June',
			'July', 'August', 'September', 'October', 'November', 'December' ];
		return fmt
			.replace( /Y/g, String( d.getFullYear() ) )
			.replace( /m/g, pad( d.getMonth() + 1 ) )
			.replace( /d/g, pad( d.getDate() ) )
			.replace( /F/g, months[ d.getMonth() ] )
			.replace( /j/g, String( d.getDate() ) );
	}

	/**
	 * POST to admin-ajax with the locked nonce. Returns a promise resolving
	 * with the parsed JSON envelope.
	 *
	 * @param {string} action
	 * @param {Object} payload
	 * @returns {Promise<{ok: boolean, status: number, body: Object}>}
	 */
	function ajax( action, payload ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( '_nonce', swhApiKeys.nonce );
		Object.keys( payload || {} ).forEach( function ( key ) {
			var val = payload[ key ];
			if ( val === null || val === undefined ) {
				return;
			}
			body.append( key, String( val ) );
		} );

		return window.fetch( swhApiKeys.ajaxUrl, {
			method:      'POST',
			credentials: 'same-origin',
			body:        body,
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				return { ok: res.ok, status: res.status, body: data };
			} );
		} );
	}

	/**
	 * Surface an error to the operator. Uses the shared swhToast if
	 * available, falling back to alert() so we never silently drop.
	 *
	 * @param {string} message
	 */
	function showError( message ) {
		if ( typeof swhToast === 'function' ) {
			swhToast( message, 'error' );
		} else {
			// eslint-disable-next-line no-alert
			window.alert( message );
		}
	}

	/**
	 * Surface a success to the operator.
	 *
	 * @param {string} message
	 */
	function showSuccess( message ) {
		if ( typeof swhToast === 'function' ) {
			swhToast( message, 'success' );
		}
	}

	/**
	 * Tiny DOM helper — create an element with optional attributes and
	 * textContent. Avoids innerHTML entirely.
	 *
	 * @param {string} tag
	 * @param {Object} [attrs]
	 * @param {string} [text]
	 * @returns {Element}
	 */
	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				if ( k === 'className' ) {
					node.className = attrs[ k ];
				} else {
					node.setAttribute( k, attrs[ k ] );
				}
			} );
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = text;
		}
		return node;
	}

	// ---------------------------------------------------------------------
	// Table updates
	// ---------------------------------------------------------------------

	/**
	 * Build a row Element matching the server-rendered structure. Uses
	 * createElement + textContent throughout — no innerHTML.
	 *
	 * @param {Object} entry
	 * @returns {HTMLTableRowElement}
	 */
	function buildRow( entry ) {
		var isRevoked = entry.revoked_at && entry.revoked_at > 0;
		var rowClass  = isRevoked ? 'swh-api-key-row swh-api-key-row--revoked' : 'swh-api-key-row';
		var scopeText = entry.scope === 'write' ? i18n.scopeWrite : i18n.scopeRead;
		var rateText  = ( entry.rate_limit === null || entry.rate_limit === undefined || entry.rate_limit === '' )
			? i18n.rateLimitDefault
			: format( i18n.rateLimitFormat, entry.rate_limit );
		var lastUsed  = entry.last_used_at > 0 ? formatDate( entry.last_used_at ) : i18n.never;
		var created   = entry.created_at > 0 ? formatDate( entry.created_at ) : '—';

		var tr = el( 'tr', { className: rowClass, 'data-swh-apikey-id': entry.id } );
		tr.appendChild( el( 'td', { className: 'swh-api-key-cell-label' }, entry.label ) );
		tr.appendChild( el( 'td', null, scopeText ) );

		var prefixCell = el( 'td' );
		prefixCell.appendChild( el( 'code', null, ( entry.prefix || '' ) + '…' ) );
		tr.appendChild( prefixCell );

		tr.appendChild( el( 'td', null, lastUsed ) );
		tr.appendChild( el( 'td', null, rateText ) );
		tr.appendChild( el( 'td', null, created ) );

		var actionsCell = el( 'td' );
		if ( isRevoked ) {
			actionsCell.appendChild(
				el( 'span', { className: 'swh-api-key-revoked-label' }, format( i18n.revokedAt, formatDate( entry.revoked_at ) ) )
			);
		} else {
			actionsCell.appendChild(
				el( 'button', {
					type:                    'button',
					className:               'button button-link-delete swh-api-key-revoke-btn',
					'data-swh-apikey-id':    entry.id,
					'data-swh-apikey-label': entry.label,
					'aria-label':            format( i18n.revokeAria, entry.label ),
				}, i18n.revoke )
			);
		}
		tr.appendChild( actionsCell );
		return tr;
	}

	/**
	 * Remove the empty-state row if present (called before inserting the
	 * first real row).
	 */
	function clearEmptyRow() {
		var empty = refs.tbody.querySelector( '.swh-api-keys-empty-row' );
		if ( empty ) {
			empty.parentNode.removeChild( empty );
		}
	}

	function appendRow( entry ) {
		clearEmptyRow();
		refs.tbody.appendChild( buildRow( entry ) );
	}

	function replaceRow( id, entry ) {
		var existing = refs.tbody.querySelector( 'tr[data-swh-apikey-id="' + id.replace( /"/g, '' ) + '"]' );
		if ( ! existing ) {
			return;
		}
		existing.parentNode.replaceChild( buildRow( entry ), existing );
	}

	// ---------------------------------------------------------------------
	// Modal: shown-once secret
	// ---------------------------------------------------------------------

	/**
	 * Compute the tabbable elements inside the secret modal in tab order.
	 *
	 * @returns {Element[]}
	 */
	function modalTabbables() {
		// Order matters: Copy first (initial focus), then the input, then Close.
		var list = [
			refs.copyBtn,
			refs.plaintextInput,
			refs.closeBtn,
		];
		if ( ! refs.confirm.hidden ) {
			list.push( refs.cancelClose );
			list.push( refs.confirmClose );
		}
		return list.filter( function ( node ) { return node && ! node.disabled; } );
	}

	/**
	 * Open the secret modal with a freshly generated plaintext key.
	 *
	 * @param {string} plaintext
	 */
	function openSecretModal( plaintext ) {
		modal.plaintext = plaintext;
		modal.copied    = false;
		modal.opener    = document.activeElement;

		refs.plaintextInput.value = plaintext;
		refs.copyBtn.classList.remove( 'is-copied' );
		refs.copyLabel.textContent = i18n.copy;
		refs.live.textContent = '';
		refs.confirm.hidden = true;

		refs.modal.hidden = false;
		document.body.classList.add( 'swh-apikey-modal-open' );

		// Initial focus: Copy button (per locked design).
		window.requestAnimationFrame( function () {
			refs.copyBtn.focus();
		} );
	}

	/**
	 * Tear down the secret modal. Wipes plaintext from BOTH the closure and
	 * the input. Restores focus to the opener.
	 */
	function closeSecretModal() {
		modal.plaintext = null;
		modal.copied    = false;
		refs.plaintextInput.value = '';
		refs.live.textContent = '';
		refs.confirm.hidden = true;
		refs.modal.hidden = true;
		document.body.classList.remove( 'swh-apikey-modal-open' );

		if ( modal.opener && typeof modal.opener.focus === 'function' ) {
			modal.opener.focus();
		}
		modal.opener = null;
	}

	/**
	 * User clicked Close. If they haven't copied yet, show the inline
	 * confirmation. Otherwise close immediately.
	 */
	function attemptClose() {
		if ( modal.copied ) {
			closeSecretModal();
			return;
		}
		refs.confirm.hidden = false;
		window.requestAnimationFrame( function () {
			refs.cancelClose.focus();
		} );
	}

	/**
	 * Copy plaintext to clipboard with reliable feedback.
	 * Tries modern Clipboard API first; falls back to selection + execCommand.
	 */
	function doCopy() {
		var text = modal.plaintext;
		if ( ! text ) {
			return;
		}
		var afterCopy = function () {
			modal.copied = true;
			refs.copyBtn.classList.add( 'is-copied' );
			refs.copyLabel.textContent = i18n.copied;
			refs.live.textContent = i18n.copyAnnounced;
			window.setTimeout( function () {
				if ( ! refs.modal.hidden ) {
					refs.copyBtn.classList.remove( 'is-copied' );
					refs.copyLabel.textContent = i18n.copy;
				}
			}, 2000 );
		};
		var fallback = function () {
			try {
				refs.plaintextInput.select();
				refs.plaintextInput.setSelectionRange( 0, text.length );
				var ok = document.execCommand( 'copy' );
				if ( ok ) {
					afterCopy();
				} else {
					showError( i18n.copyFailed );
				}
			} catch ( e ) {
				showError( i18n.copyFailed );
			}
		};
		if ( window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText ) {
			window.navigator.clipboard.writeText( text ).then( afterCopy, fallback );
		} else {
			fallback();
		}
	}

	// ---------------------------------------------------------------------
	// Modal: revoke confirmation
	// ---------------------------------------------------------------------

	function openRevokeModal( id, label, opener ) {
		revokeTarget.id     = id;
		revokeTarget.label  = label;
		revokeTarget.opener = opener;
		refs.revokeTargetLabel.textContent = label;
		refs.revokeModal.hidden = false;
		document.body.classList.add( 'swh-apikey-modal-open' );
		window.requestAnimationFrame( function () {
			refs.revokeCancel.focus();
		} );
	}

	function closeRevokeModal() {
		refs.revokeModal.hidden = true;
		document.body.classList.remove( 'swh-apikey-modal-open' );
		var opener = revokeTarget.opener;
		revokeTarget = { id: null, label: null, opener: null };
		if ( opener && typeof opener.focus === 'function' ) {
			opener.focus();
		}
	}

	// ---------------------------------------------------------------------
	// Submit handlers
	// ---------------------------------------------------------------------

	function onGenerate() {
		var label = refs.labelInput.value.trim();
		var scope = ( document.querySelector( 'input[name="swh_apikey_scope"]:checked' ) || {} ).value || 'read';
		var rate  = refs.rateInput.value.trim();

		refs.generateBtn.disabled = true;
		refs.generateBtn.textContent = i18n.generating;

		ajax( 'swh_api_key_generate', {
			label:      label,
			scope:      scope,
			rate_limit: rate,
		} ).then( function ( res ) {
			refs.generateBtn.disabled = false;
			refs.generateBtn.textContent = i18n.generate;

			if ( ! res.ok || ! res.body || ! res.body.success ) {
				var msg = ( res.body && res.body.data && res.body.data.message ) || i18n.genericError;
				showError( msg );
				return;
			}
			var entry     = res.body.data.entry;
			var plaintext = res.body.data.plaintext;
			appendRow( entry );
			openSecretModal( plaintext );
			showSuccess( i18n.generated );

			// Reset form to clean state for the next key.
			refs.labelInput.value = '';
			refs.rateInput.value  = '';
			var readRadio = document.querySelector( 'input[name="swh_apikey_scope"][value="read"]' );
			if ( readRadio ) {
				readRadio.checked = true;
			}
		} ).catch( function () {
			refs.generateBtn.disabled = false;
			refs.generateBtn.textContent = i18n.generate;
			showError( i18n.networkError );
		} );
	}

	function onRevokeConfirmed() {
		var id    = revokeTarget.id;
		var label = revokeTarget.label;
		if ( ! id ) {
			return;
		}
		refs.revokeConfirm.disabled = true;
		refs.revokeConfirm.textContent = i18n.revoking;

		ajax( 'swh_api_key_revoke', { id: id } ).then( function ( res ) {
			refs.revokeConfirm.disabled = false;
			refs.revokeConfirm.textContent = i18n.revokeKey;

			if ( ! res.ok || ! res.body || ! res.body.success ) {
				var msg = ( res.body && res.body.data && res.body.data.message ) || i18n.genericError;
				showError( msg );
				closeRevokeModal();
				return;
			}
			// Reuse the previous row's metadata + flip revoked_at — the revoke
			// endpoint returns only { revoked_at } so we read existing cells.
			var existing = refs.tbody.querySelector( 'tr[data-swh-apikey-id="' + id.replace( /"/g, '' ) + '"]' );
			var prefix   = '';
			if ( existing ) {
				var codeNode = existing.querySelector( 'code' );
				if ( codeNode ) {
					prefix = ( codeNode.textContent || '' ).replace( /…$/, '' );
				}
			}
			var entry = {
				id:           id,
				label:        label,
				scope:        existing && existing.children[ 1 ].textContent === i18n.scopeWrite ? 'write' : 'read',
				prefix:       prefix,
				last_used_at: 0,
				rate_limit:   null,
				created_at:   0,
				revoked_at:   res.body.data.revoked_at,
			};
			replaceRow( id, entry );
			showSuccess( i18n.revoked );
			closeRevokeModal();
		} ).catch( function () {
			refs.revokeConfirm.disabled = false;
			refs.revokeConfirm.textContent = i18n.revokeKey;
			showError( i18n.networkError );
			closeRevokeModal();
		} );
	}

	// ---------------------------------------------------------------------
	// Bind
	// ---------------------------------------------------------------------

	function bind() {
		refs.section           = document.querySelector( '.swh-api-keys-section' );
		refs.tbody             = document.getElementById( 'swh-api-keys-tbody' );
		refs.generateBtn       = document.getElementById( 'swh-apikey-generate' );
		refs.labelInput        = document.getElementById( 'swh-apikey-label' );
		refs.rateInput         = document.getElementById( 'swh-apikey-rate-limit' );
		refs.modal             = document.getElementById( 'swh-apikey-modal' );
		refs.plaintextInput    = document.getElementById( 'swh-apikey-plaintext-input' );
		refs.copyBtn           = document.getElementById( 'swh-apikey-copy' );
		refs.copyLabel         = refs.copyBtn ? refs.copyBtn.querySelector( '.swh-apikey-copy-label' ) : null;
		refs.live              = document.getElementById( 'swh-apikey-modal-live' );
		refs.confirm           = document.getElementById( 'swh-apikey-modal-confirm' );
		refs.cancelClose       = document.getElementById( 'swh-apikey-cancel-close' );
		refs.confirmClose      = document.getElementById( 'swh-apikey-confirm-close' );
		refs.closeBtn          = document.getElementById( 'swh-apikey-close' );
		refs.revokeModal       = document.getElementById( 'swh-apikey-revoke-modal' );
		refs.revokeTargetLabel = document.getElementById( 'swh-apikey-revoke-target-label' );
		refs.revokeCancel      = document.getElementById( 'swh-apikey-revoke-cancel' );
		refs.revokeConfirm     = document.getElementById( 'swh-apikey-revoke-confirm' );

		if ( ! refs.section || ! refs.generateBtn ) {
			return;
		}

		refs.generateBtn.addEventListener( 'click', onGenerate );
		refs.copyBtn.addEventListener( 'click', doCopy );
		refs.closeBtn.addEventListener( 'click', attemptClose );
		refs.cancelClose.addEventListener( 'click', function () {
			refs.confirm.hidden = true;
			refs.copyBtn.focus();
		} );
		refs.confirmClose.addEventListener( 'click', closeSecretModal );

		// Backdrop click → same as Close (un-copied-confirm path applies).
		Array.prototype.forEach.call(
			refs.modal.querySelectorAll( '[data-swh-apikey-backdrop]' ),
			function ( node ) { node.addEventListener( 'click', attemptClose ); }
		);

		// Revoke modal wiring.
		refs.revokeCancel.addEventListener( 'click', closeRevokeModal );
		refs.revokeConfirm.addEventListener( 'click', onRevokeConfirmed );
		Array.prototype.forEach.call(
			refs.revokeModal.querySelectorAll( '[data-swh-apikey-backdrop]' ),
			function ( node ) { node.addEventListener( 'click', closeRevokeModal ); }
		);

		// Delegated revoke-button click.
		refs.tbody.addEventListener( 'click', function ( ev ) {
			var btn = ev.target.closest && ev.target.closest( '.swh-api-key-revoke-btn' );
			if ( ! btn ) {
				return;
			}
			openRevokeModal(
				btn.getAttribute( 'data-swh-apikey-id' ),
				btn.getAttribute( 'data-swh-apikey-label' ),
				btn
			);
		} );

		// Keyboard handling: ESC closes; focus trap inside the open modal.
		document.addEventListener( 'keydown', function ( ev ) {
			if ( ev.key !== 'Escape' && ev.key !== 'Tab' ) {
				return;
			}
			if ( ! refs.modal.hidden ) {
				if ( ev.key === 'Escape' ) {
					ev.preventDefault();
					attemptClose();
					return;
				}
				trapTab( ev, modalTabbables() );
				return;
			}
			if ( ! refs.revokeModal.hidden ) {
				if ( ev.key === 'Escape' ) {
					ev.preventDefault();
					closeRevokeModal();
					return;
				}
				trapTab( ev, [ refs.revokeCancel, refs.revokeConfirm ] );
			}
		} );
	}

	/**
	 * Wrap-around tab trap for an ordered list of focusables.
	 *
	 * @param {KeyboardEvent} ev
	 * @param {Element[]}     focusables
	 */
	function trapTab( ev, focusables ) {
		if ( ! focusables.length ) {
			return;
		}
		var first = focusables[ 0 ];
		var last  = focusables[ focusables.length - 1 ];
		if ( ev.shiftKey && document.activeElement === first ) {
			ev.preventDefault();
			last.focus();
		} else if ( ! ev.shiftKey && document.activeElement === last ) {
			ev.preventDefault();
			first.focus();
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', bind );
	} else {
		bind();
	}
}() );
