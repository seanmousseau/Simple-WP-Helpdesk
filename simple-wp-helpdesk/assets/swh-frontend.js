/**
 * Simple WP Helpdesk — frontend ticket form interactions.
 *
 * Handles file type/size validation, inline error display, and the
 * ticket lookup form toggle on the [submit_ticket] and [helpdesk_portal]
 * shortcode pages.
 *
 * @since 2.0.0
 * @package Simple_WP_Helpdesk
 */

document.addEventListener( 'DOMContentLoaded', function () {
	if ( typeof swhConfig === 'undefined' ) {
		return;
	}

	/**
	 * Displays or clears a file validation error message below the given input.
	 *
	 * @param {HTMLInputElement} input   - The file input element.
	 * @param {string}           message - The error text to display, or empty string to clear.
	 */
	function swhShowFileError( input, message ) {
		const existing = input.parentNode.querySelector( '.swh-file-error' );
		if ( existing ) { existing.remove(); }
		if ( message ) {
			const div       = document.createElement( 'div' );
			div.className   = 'swh-file-error';
			div.textContent = message;
			div.setAttribute( 'role', 'alert' );
			input.setAttribute( 'aria-describedby', 'swh-file-error-' + input.name );
			div.id = 'swh-file-error-' + input.name;
			input.parentNode.insertBefore( div, input.nextSibling );
		} else {
			input.removeAttribute( 'aria-describedby' );
		}
	}

	const maxBytes = swhConfig.maxMb * 1024 * 1024;

	/**
	 * Validates file type and size when the user selects files, showing inline errors.
	 */
	document.querySelectorAll( '.swh-file-input' ).forEach( function ( input ) {
		input.addEventListener( 'change', function () {
			swhShowFileError( this, '' );
			if ( swhConfig.maxFiles > 0 && this.files.length > swhConfig.maxFiles ) {
				swhShowFileError( this, swhConfig.i18n.maxFilesError.replace( '%d', swhConfig.maxFiles ) );
				this.value = '';
				return;
			}
			let errorMsg = '';
			for ( let i = 0; i < this.files.length; i++ ) {
				const file = this.files[ i ];
				const ext  = file.name.split( '.' ).pop().toLowerCase();
				if ( swhConfig.allowedExts.indexOf( ext ) === -1 ) {
					errorMsg += swhConfig.i18n.invalidType.replace( '%s', file.name ) + '\n';
				}
				if ( file.size > maxBytes ) {
					errorMsg += swhConfig.i18n.sizeExceeded.replace( '%s', file.name ).replace( '%d', swhConfig.maxMb ) + '\n';
				}
			}
			if ( errorMsg !== '' ) {
				swhShowFileError( this, errorMsg );
				this.value = '';
			}
		} );
	} );

	/**
	 * Replaces .swh-timestamp text content with a human-relative string ("3 hours ago", etc.).
	 * The ISO8601 datetime attribute preserves machine-readable time; absolute date stays in title.
	 */
	document.querySelectorAll( '.swh-timestamp' ).forEach( function ( el ) {
		const iso  = el.getAttribute( 'datetime' );
		if ( ! iso ) { return; }
		const then = new Date( iso ).getTime();
		const now  = Date.now();
		const diff = Math.floor( ( now - then ) / 1000 );
		let label;
		if ( diff < 60 ) {
			label = 'just now';
		} else if ( diff < 3600 ) {
			const m = Math.floor( diff / 60 );
			label = m + ' minute' + ( m === 1 ? '' : 's' ) + ' ago';
		} else if ( diff < 86400 ) {
			const h = Math.floor( diff / 3600 );
			label = h + ' hour' + ( h === 1 ? '' : 's' ) + ' ago';
		} else if ( diff < 172800 ) {
			label = 'Yesterday';
		} else {
			const d = Math.floor( diff / 86400 );
			label = d + ' days ago';
		}
		el.textContent = label;
	} );

	/**
	 * Toggles the ticket lookup form visibility and updates aria-expanded on the trigger link.
	 */
	const toggleLink = document.getElementById( 'swh-toggle-lookup' );
	if ( toggleLink ) {
		toggleLink.setAttribute( 'aria-expanded', 'false' );
		toggleLink.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			const form     = document.getElementById( 'swh-lookup-form' );
			const expanded = form.style.display !== 'none';
			form.style.display = expanded ? 'none' : 'block';
			toggleLink.setAttribute( 'aria-expanded', String( ! expanded ) );
		} );
	}

	/**
	 * CSAT star widget — shown after a client closes a ticket.
	 *
	 * Reads ticket_id, nonce, and ajaxurl from data attributes on #swh-csat.
	 * On star click: submits rating via AJAX, shows #swh-csat-thanks.
	 * On skip: hides widget, shows #swh-close-success.
	 */
	const csatBox = document.getElementById( 'swh-csat' );
	if ( csatBox ) {
		const stars      = csatBox.querySelectorAll( '.swh-csat-star' );
		const skipLink   = document.getElementById( 'swh-csat-skip' );
		const thanksBox  = document.getElementById( 'swh-csat-thanks' );
		const successBox = document.getElementById( 'swh-close-success' );

		stars.forEach( function ( btn ) {
			btn.addEventListener( 'mouseenter', function () {
				const hovered = parseInt( btn.getAttribute( 'data-rating' ), 10 );
				stars.forEach( function ( s ) {
					s.classList.toggle( 'swh-csat-star--active', parseInt( s.getAttribute( 'data-rating' ), 10 ) <= hovered );
				} );
			} );
		} );

		csatBox.addEventListener( 'mouseleave', function () {
			stars.forEach( function ( s ) { s.classList.remove( 'swh-csat-star--active' ); } );
		} );

		stars.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				const rating   = btn.getAttribute( 'data-rating' );
				const ticketId = csatBox.getAttribute( 'data-ticket' );
				const nonce    = csatBox.getAttribute( 'data-nonce' );
				const ajaxUrl  = csatBox.getAttribute( 'data-ajaxurl' );
				const body     = 'action=swh_submit_csat&ticket_id=' + encodeURIComponent( ticketId ) +
					'&rating=' + encodeURIComponent( rating ) + '&nonce=' + encodeURIComponent( nonce );
				const xhr2 = new XMLHttpRequest();
				xhr2.open( 'POST', ajaxUrl );
				xhr2.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
				xhr2.send( body );
				csatBox.style.display = 'none';
				if ( thanksBox ) { thanksBox.style.display = ''; }
			} );
		} );

		if ( skipLink ) {
			skipLink.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				csatBox.style.display = 'none';
				if ( successBox ) { successBox.style.display = ''; }
			} );
		}
	}

	/**
	 * Ticket template selector — pre-fills the description textarea when a
	 * request type is selected. Only runs if swhConfig.templates is populated.
	 */
	const requestTypeSelect = document.getElementById( 'swh-request-type' );
	if ( requestTypeSelect && swhConfig.templates && swhConfig.templates.length > 0 ) {
		const descField = document.getElementById( 'swh-desc' );
		requestTypeSelect.addEventListener( 'change', function () {
			if ( ! descField ) { return; }
			const selected = this.value;
			if ( ! selected ) {
				return; // "— Select a request type —" chosen; leave textarea as-is.
			}
			const tmpl = swhConfig.templates.find( function ( t ) { return t.label === selected; } );
			if ( tmpl ) {
				// Only pre-fill if the textarea is blank or already contains a previous template body.
				if ( descField.value === '' || swhConfig.templates.some( function ( t ) { return t.body === descField.value; } ) ) {
					descField.value = tmpl.body;
				}
			}
		} );
	}

	/**
	 * Upload progress indicator for the ticket submission form.
	 *
	 * When the user submits a form with files selected, shows an animated
	 * (indeterminate) progress bar and disables the submit button to prevent
	 * double-submissions. The native form POST proceeds normally — no XHR
	 * interception — so Cloudflare/CDN proxies handle the request unchanged.
	 */
	const ticketForm = document.getElementById( 'swh-ticket-form' );
	if ( ticketForm ) {
		ticketForm.addEventListener( 'submit', function () {
			const fileInput = ticketForm.querySelector( '.swh-file-input' );
			if ( ! fileInput || fileInput.files.length === 0 ) {
				return; // No files — nothing extra to show.
			}

			const submitBtn = ticketForm.querySelector( '[type="submit"]' );
			if ( submitBtn ) {
				submitBtn.value = 'Uploading\u2026';
				// Defer disabled so the browser doesn't cancel the in-flight form POST.
				setTimeout( function () { submitBtn.disabled = true; }, 0 );
			}

			const wrap     = document.createElement( 'div' );
			wrap.className = 'swh-progress-bar swh-progress-bar--indeterminate';
			const fill     = document.createElement( 'div' );
			fill.className = 'swh-progress-fill';
			wrap.appendChild( fill );
			ticketForm.appendChild( wrap );
			// Native form submit continues; browser navigates on completion.
		} );
	}
} );
