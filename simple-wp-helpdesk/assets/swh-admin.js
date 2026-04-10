/**
 * Simple WP Helpdesk — admin settings page interactions.
 *
 * Handles ARIA tab switching, keyboard navigation, and reset-to-default
 * links on the Helpdesk Settings page.
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
	}

	// Restore active tab from URL param (set after redirect on save).
	const urlParams = new URLSearchParams( window.location.search );
	const savedTab  = urlParams.get( 'swh_tab' );
	if ( savedTab && document.getElementById( savedTab ) ) {
		activateTab( savedTab );
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
} );
