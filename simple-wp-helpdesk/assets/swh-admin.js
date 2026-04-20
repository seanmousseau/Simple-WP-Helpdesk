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
				+ mergeToggle.textContent.replace( /^[\u25BC\u25BA]\s*/, '' );
		} );
	}

	// Settings: Send Test Email button.
	const testEmailBtn = document.getElementById( 'swh-test-email-btn' );
	const testEmailMsg = document.getElementById( 'swh-test-email-msg' );

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
					testEmailMsg.style.color = json.success ? 'green' : '#d63638';
				} )
				.catch( function () {
					testEmailBtn.disabled    = false;
					testEmailMsg.textContent = i18n.testEmailError || 'Failed to send.';
					testEmailMsg.style.color = '#d63638';
				} );
		} );
	}
} );
