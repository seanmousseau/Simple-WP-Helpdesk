document.addEventListener( 'DOMContentLoaded', function () {
	const tabs         = document.querySelectorAll( '.nav-tab' );
	const contents     = document.querySelectorAll( '.swh-tab-content' );
	const saveBtn      = document.getElementById( 'save-btn-container' );
	const activeTabInput = document.getElementById( 'swh_active_tab' );

	/**
	 * Activates a settings tab by ID, hiding all others.
	 *
	 * @param {string} tabId - The ID of the tab panel element to activate.
	 */
	function activateTab( tabId ) {
		tabs.forEach( function ( t ) { t.classList.remove( 'nav-tab-active' ); } );
		contents.forEach( function ( c ) { c.style.display = 'none'; } );
		const tabEl = document.getElementById( tabId );
		if ( tabEl ) { tabEl.style.display = 'block'; }
		tabs.forEach( function ( t ) {
			if ( t.dataset.tab === tabId ) {
				t.classList.add( 'nav-tab-active' );
			}
		} );
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
			const fieldName = this.previousElementSibling.getAttribute( 'data-field-name' );
			const target    = document.querySelector( '[name="' + fieldName + '"]' );
			if ( target ) { target.value = target.getAttribute( 'data-default' ); }
		} );
	} );
} );
