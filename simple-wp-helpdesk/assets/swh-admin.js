/**
 * Simple WP Helpdesk — admin settings page interactions.
 *
 * Handles ARIA tab switching, keyboard navigation, reset-to-default
 * links on the Helpdesk Settings page, canned response management in
 * settings, and canned response insertion in the ticket editor.
 *
 * @since 2.1.0
 * @package Simple_WP_Helpdesk
 */

/* global swhAdmin */

/**
 * Toast notification renderer (`swhToast()`) was extracted to the
 * `@wordpress/scripts` build in v3.7.0 (#390). It now lives at
 * `assets/src/toast/index.js` and is built to
 * `assets/dist/toast/index.js`, then enqueued as the `swh-toast` handle
 * before this script. It exposes `window.swhToast` with the same
 * signature, so the call site below works unchanged.
 */

document.addEventListener( 'DOMContentLoaded', function () {
	const tabs         = document.querySelectorAll( '[role="tab"]' );
	const contents     = document.querySelectorAll( '.swh-tab-content' );
	const saveBtn      = document.getElementById( 'save-btn-container' );
	const activeTabInput = document.getElementById( 'swh_active_tab' );

	/**
	 * Activates a settings tab by panel ID, updating ARIA attributes, tabindex, and visibility.
	 *
	 * @param {string} tabId - The ID of the tab panel element to activate.
	 */
	function activateTab( tabId ) {
		tabs.forEach( function ( t ) {
			const isActive = t.dataset.tab === tabId;
			t.classList.toggle( 'nav-tab-active', isActive );
			t.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
			t.setAttribute( 'tabindex', isActive ? '0' : '-1' );
		} );
		contents.forEach( function ( c ) { c.style.display = 'none'; } );
		const tabEl = document.getElementById( tabId );
		if ( tabEl ) { tabEl.style.display = 'block'; }
		if ( saveBtn ) { saveBtn.style.display = ( tabId === 'tab-tools' ) ? 'none' : 'block'; }
		if ( activeTabInput ) { activeTabInput.value = tabId; }
		sessionStorage.setItem( 'swh_active_tab', tabId );
	}

	// Restore active tab: URL param wins (post-save redirect), then sessionStorage (#267).
	const urlParams    = new URLSearchParams( window.location.search );
	const savedTab     = urlParams.get( 'swh_tab' );
	const sessionTab   = sessionStorage.getItem( 'swh_active_tab' );
	const restoreTab   = ( savedTab && document.getElementById( savedTab ) ) ? savedTab
		: ( sessionTab && document.getElementById( sessionTab ) ) ? sessionTab : null;
	if ( restoreTab ) {
		activateTab( restoreTab );
	}

	// Settings save toast — triggered by redirect query param (#334).
	if ( urlParams.get( 'swh_notice' ) === 'saved' ) {
		var savedMsg = ( typeof swhAdmin !== 'undefined' && swhAdmin.i18n && swhAdmin.i18n.settingsSaved )
			? swhAdmin.i18n.settingsSaved
			: 'Settings saved.';
		window.swhToast( savedMsg, 'success' );
		var cleanSearch = window.location.search
			.replace( /([?&])swh_notice=saved(&|$)/, function ( _m, pre, suf ) { return suf ? pre : ''; } )
			.replace( /^&/, '?' );
		history.replaceState( null, '', window.location.pathname + cleanSearch + window.location.hash );
	}

	/**
	 * Handles tab click events to switch the active settings panel.
	 *
	 * @param {Event} e - The click event.
	 */
	tabs.forEach( function ( tab ) {
		tab.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			activateTab( tab.dataset.tab );
			tab.focus();
		} );
	} );

	/**
	 * Handles arrow key, Home, and End keyboard navigation within the tablist.
	 *
	 * @param {KeyboardEvent} e - The keydown event.
	 */
	tabs.forEach( function ( tab ) {
		tab.addEventListener( 'keydown', function ( e ) {
			const tabArray = Array.from( tabs );
			const idx      = tabArray.indexOf( e.target );
			let nextIdx    = idx;

			if ( e.key === 'ArrowRight' || e.key === 'ArrowDown' ) {
				nextIdx = ( idx + 1 ) % tabArray.length;
			} else if ( e.key === 'ArrowLeft' || e.key === 'ArrowUp' ) {
				nextIdx = ( idx - 1 + tabArray.length ) % tabArray.length;
			} else if ( e.key === 'Home' ) {
				nextIdx = 0;
			} else if ( e.key === 'End' ) {
				nextIdx = tabArray.length - 1;
			} else {
				return;
			}

			e.preventDefault();
			activateTab( tabArray[ nextIdx ].dataset.tab );
			tabArray[ nextIdx ].focus();
		} );
	} );

	/**
	 * Handles reset-to-default link clicks, restoring the associated field's default value.
	 *
	 * @param {Event} e - The click event.
	 */
	document.querySelectorAll( '.swh-reset-field' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			const fieldName = this.previousElementSibling.previousElementSibling.getAttribute( 'data-field-name' );
			const target    = document.querySelector( '[name="' + fieldName + '"]' );
			if ( target ) { target.value = target.getAttribute( 'data-default' ); }
		} );
	} );

	// Canned Responses — settings page: add and remove items.
	const cannedList = document.getElementById( 'swh-canned-list' );
	const addCanned  = document.getElementById( 'swh-add-canned' );

	/**
	 * Builds a new canned response row element ready to be appended to the list.
	 *
	 * Uses DOM methods exclusively to avoid any XSS risk from dynamic content.
	 *
	 * @return {HTMLElement} A div containing the title input, body textarea, and remove button.
	 */
	function buildCannedRow() {
		const i18n = ( window.swhAdmin && window.swhAdmin.i18n ) ? window.swhAdmin.i18n : {};

		const row = document.createElement( 'div' );
		row.className     = 'swh-canned-item';
		row.style.cssText = 'display:flex; gap:10px; align-items:flex-start; margin-bottom:10px; background:#f9f9f9; padding:10px; border:1px solid #ddd; border-radius:4px;';

		const fieldWrap          = document.createElement( 'div' );
		fieldWrap.style.flex     = '1';

		const titleInput             = document.createElement( 'input' );
		titleInput.type              = 'text';
		titleInput.name              = 'swh_canned_titles[]';
		titleInput.className         = 'regular-text';
		titleInput.placeholder       = i18n.cannedTitlePlaceholder || 'Response title\u2026';
		titleInput.setAttribute( 'aria-label', i18n.cannedTitleAriaLabel || 'Canned response title' );
		titleInput.style.cssText     = 'width:100%; margin-bottom:6px;';

		const bodyArea           = document.createElement( 'textarea' );
		bodyArea.name            = 'swh_canned_bodies[]';
		bodyArea.rows            = 3;
		bodyArea.className       = 'large-text';
		bodyArea.setAttribute( 'aria-label', i18n.cannedBodyAriaLabel || 'Canned response body' );
		bodyArea.style.width     = '100%';

		fieldWrap.appendChild( titleInput );
		fieldWrap.appendChild( bodyArea );

		const btnWrap       = document.createElement( 'div' );
		const removeBtn     = document.createElement( 'button' );
		removeBtn.type      = 'button';
		removeBtn.className = 'button swh-remove-canned';
		removeBtn.textContent = i18n.removeLabel || 'Remove';
		btnWrap.appendChild( removeBtn );

		row.appendChild( fieldWrap );
		row.appendChild( btnWrap );
		return row;
	}

	if ( addCanned && cannedList ) {
		/**
		 * Appends a blank canned response row when the "Add Response" button is clicked.
		 */
		addCanned.addEventListener( 'click', function () {
			cannedList.appendChild( buildCannedRow() );
		} );
	}

	if ( cannedList ) {
		/**
		 * Removes the parent canned response row when a "Remove" button is clicked.
		 *
		 * @param {Event} e - The click event, delegated from the list container.
		 */
		cannedList.addEventListener( 'click', function ( e ) {
			if ( e.target.classList.contains( 'swh-remove-canned' ) ) {
				const item = e.target.closest( '.swh-canned-item' );
				if ( item ) {
					item.remove();
				}
			}
		} );
	}

	// Canned Responses — ticket editor: insert selected response body into the reply textarea.
	const insertBtn    = document.getElementById( 'swh-canned-insert' );
	const cannedSelect = document.getElementById( 'swh-canned-select' );
	const replyArea    = document.getElementById( 'swh-tech-reply-text' );

	if ( insertBtn && cannedSelect && replyArea ) {
		/**
		 * Inserts the selected canned response body into the reply textarea.
		 *
		 * Appends to any existing content, separated by a blank line when the textarea is non-empty.
		 */
		insertBtn.addEventListener( 'click', function () {
			const body = cannedSelect.value;
			if ( ! body ) {
				return;
			}
			replyArea.value    = replyArea.value ? replyArea.value + '\n\n' + body : body;
			cannedSelect.value = '';
			replyArea.focus();
		} );
	}

	// Ticket editor: dedicated Send Reply / Save Note buttons.
	const sendReplyBtn = document.getElementById( 'swh-send-reply-btn' );
	const saveNoteBtn  = document.getElementById( 'swh-save-note-btn' );
	const noteArea     = document.getElementById( 'swh-tech-note-text' );

	if ( sendReplyBtn ) {
		/**
		 * Submits the ticket form as a "Send Reply": clears the note textarea first so
		 * only the public reply is saved, then triggers the WordPress post-update form.
		 */
		sendReplyBtn.addEventListener( 'click', function () {
			if ( noteArea ) {
				noteArea.value = '';
			}
			const form = document.querySelector( '#post' );
			if ( form ) {
				const submitBtn = document.getElementById( 'publish' );
				if ( submitBtn ) {
					submitBtn.click();
				} else {
					form.submit();
				}
			}
		} );
	}

	if ( saveNoteBtn ) {
		/**
		 * Submits the ticket form as a "Save Note": clears the reply textarea first so
		 * only the internal note is saved, then triggers the WordPress post-update form.
		 */
		saveNoteBtn.addEventListener( 'click', function () {
			if ( replyArea ) {
				replyArea.value = '';
			}
			const form = document.querySelector( '#post' );
			if ( form ) {
				const submitBtn = document.getElementById( 'publish' );
				if ( submitBtn ) {
					submitBtn.click();
				} else {
					form.submit();
				}
			}
		} );
	}

	// Ticket editor: merge form expand/collapse (#271).
	const mergeToggle = document.getElementById( 'swh-merge-toggle' );
	const mergeBody   = document.getElementById( 'swh-merge-section' );

	if ( mergeToggle && mergeBody ) {
		mergeToggle.addEventListener( 'click', function () {
			const expanded = mergeToggle.getAttribute( 'aria-expanded' ) === 'true';
			mergeToggle.setAttribute( 'aria-expanded', String( ! expanded ) );
			mergeBody.classList.toggle( 'swh-merge-visible', ! expanded );
			if ( ! expanded ) {
				mergeBody.removeAttribute( 'hidden' );
				mergeBody.setAttribute( 'aria-hidden', 'false' );
			} else {
				mergeBody.setAttribute( 'hidden', '' );
				mergeBody.setAttribute( 'aria-hidden', 'true' );
			}
			mergeToggle.textContent = ( ! expanded ? '\u25BC ' : '\u25BA ' )
				+ mergeToggle.textContent.replace( /^[\u25BC\u25BA\u25B6]\s*/, '' );
		} );
	}

	// Settings: Send Test Email button.
	const testEmailBtn = document.getElementById( 'swh-test-email-btn' );
	const testEmailMsg = document.getElementById( 'swh-test-email-msg' );

	// Settings: Unsaved-changes warning (#257).
	const settingsForm = document.getElementById( 'swh-settings-form' );
	if ( settingsForm ) {
		let swhDirty = false;

		/**
		 * Sets the dirty flag when any form field changes.
		 */
		settingsForm.addEventListener( 'input', function () { swhDirty = true; } );
		settingsForm.addEventListener( 'change', function () { swhDirty = true; } );

		/**
		 * Clears the dirty flag when the form is submitted (user chose to save).
		 */
		settingsForm.addEventListener( 'submit', function () { swhDirty = false; } );

		/**
		 * Warns the user before leaving the page with unsaved changes.
		 *
		 * @param {BeforeUnloadEvent} e - The beforeunload event.
		 */
		window.addEventListener( 'beforeunload', function ( e ) {
			if ( ! swhDirty ) { return; }
			e.preventDefault();
		} );
	}

	if ( testEmailBtn && testEmailMsg ) {
		/**
		 * Sends a test email via AJAX and shows the result inline.
		 */
		testEmailBtn.addEventListener( 'click', function () {
			const i18n   = ( window.swhAdmin && window.swhAdmin.i18n ) ? window.swhAdmin.i18n : {};
			const nonce  = ( window.swhAdmin && window.swhAdmin.testEmailNonce ) ? window.swhAdmin.testEmailNonce : '';
			const ajaxUrl = ( window.swhAdmin && window.swhAdmin.ajaxUrl ) ? window.swhAdmin.ajaxUrl : '';

			testEmailBtn.disabled    = true;
			testEmailMsg.textContent = i18n.testEmailSending || 'Sending\u2026';
			testEmailMsg.style.color = '#666';

			const data = new URLSearchParams();
			data.append( 'action', 'swh_send_test_email' );
			data.append( 'nonce', nonce );

			fetch( ajaxUrl, { method: 'POST', body: data } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( json ) {
					testEmailBtn.disabled    = false;
					testEmailMsg.textContent = json.success
						? ( json.data && json.data.message ? json.data.message : ( i18n.testEmailSuccess || 'Test email sent.' ) )
						: ( json.data && json.data.message ? json.data.message : ( i18n.testEmailError || 'Failed to send.' ) );
					testEmailMsg.style.color = json.success ? 'green' : 'red';
				} )
				.catch( function () {
					testEmailBtn.disabled    = false;
					testEmailMsg.textContent = i18n.testEmailNetworkError || i18n.testEmailError || 'Network error. Please try again.';
					testEmailMsg.style.color = 'red';
				} );
		} );
	}
} );

/* Status dot color update — ticket editor (#322) */
( function () {
	var statusSelect = document.getElementById( 'swh-status' );
	var statusDot    = document.getElementById( 'swh-status-dot' );
	if ( ! statusSelect || ! statusDot ) { return; }

	var colorMap = {
		'open':        '#28a745',
		'in progress': '#d97706',
		'in-progress': '#d97706',
		'resolved':    '#0073aa',
		'closed':      '#767676',
	};

	function updateDot( val ) {
		var key   = val ? val.toLowerCase() : '';
		var color = colorMap[ key ] || '#767676';
		statusDot.style.background = color;
	}

	updateDot( statusSelect.value );
	statusSelect.addEventListener( 'change', function () { updateDot( this.value ); } );
}() );

/**
 * Debounced live-region announcer (#344).
 *
 * Updates a visually-hidden announcer span inside an aria-live container so
 * screen readers receive the change without the helper destroying the
 * container's existing visible markup. Throttles to one announcement per
 * 200 ms per element and skips no-op deltas — short enough that the
 * announcement still fires before focus moves elsewhere, long enough to
 * coalesce paint flushes from a single fetch resolution.
 *
 * @since 3.6.0
 * @param {Element} el    The aria-live container (must already have aria-live).
 * @param {string}  value The new text to announce.
 */
window.swhAnnounce = window.swhAnnounce || ( function () {
	var lastValue = new WeakMap();
	var timers    = new WeakMap();
	function getAnnouncer( el ) {
		var ann = el.querySelector( ':scope > .swh-sr-announce' );
		if ( ! ann ) {
			ann = document.createElement( 'span' );
			ann.className           = 'swh-sr-announce screen-reader-text';
			ann.setAttribute( 'aria-hidden', 'false' );
			el.appendChild( ann );
		}
		return ann;
	}
	return function ( el, value ) {
		if ( ! el ) { return; }
		var str = String( value );
		if ( lastValue.get( el ) === str ) { return; }
		lastValue.set( el, str );
		clearTimeout( timers.get( el ) );
		timers.set( el, setTimeout( function () {
			getAnnouncer( el ).textContent = str;
		}, 200 ) );
	};
}() );

/**
 * Skip-link delegated handler (#341).
 *
 * <a href="#fragment"> Enter activation does not reliably move keyboard
 * focus to the target across browsers; explicitly call .focus() on the
 * referenced #swh-main-content element when our skip link is activated.
 *
 * @since 3.6.0
 */
document.addEventListener( 'click', function ( e ) {
	var target = e.target;
	if ( ! target || ! target.classList || ! target.classList.contains( 'swh-skip-link' ) ) {
		return;
	}
	var dest = document.getElementById( 'swh-main-content' );
	if ( dest ) {
		e.preventDefault();
		dest.focus( { preventScroll: false } );
	}
} );
