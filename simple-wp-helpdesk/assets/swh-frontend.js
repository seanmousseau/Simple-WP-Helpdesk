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
	 * XHR upload progress indicator for the ticket submission form.
	 *
	 * When the user submits a form with files selected, intercepts the submit,
	 * shows a progress bar, and navigates to the server's redirect URL on completion.
	 */
	const ticketForm = document.getElementById( 'swh-ticket-form' );
	if ( ticketForm ) {
		ticketForm.addEventListener( 'submit', function ( e ) {
			const fileInput = ticketForm.querySelector( '.swh-file-input' );
			if ( ! fileInput || fileInput.files.length === 0 ) {
				return; // No files — let the browser handle the submit normally.
			}
			e.preventDefault();

			const submitBtn = ticketForm.querySelector( '[type="submit"]' );
			const origText  = submitBtn ? submitBtn.value : '';
			if ( submitBtn ) {
				submitBtn.disabled = true;
				submitBtn.value    = 'Uploading\u2026';
			}

			const wrap      = document.createElement( 'div' );
			wrap.className  = 'swh-progress-bar';
			const fill      = document.createElement( 'div' );
			fill.className  = 'swh-progress-fill';
			fill.style.width = '0%';
			wrap.appendChild( fill );
			ticketForm.appendChild( wrap );

			const xhr = new XMLHttpRequest();
			xhr.open( 'POST', ticketForm.action || window.location.href );

			xhr.upload.addEventListener( 'progress', function ( ev ) {
				if ( ev.lengthComputable ) {
					fill.style.width = Math.round( ( ev.loaded / ev.total ) * 100 ) + '%';
				}
			} );

			xhr.onload = function () {
				fill.style.width = '100%';
				window.location.replace( xhr.responseURL || window.location.href );
			};

			xhr.onerror = function () {
				wrap.remove();
				if ( submitBtn ) {
					submitBtn.disabled = false;
					submitBtn.value    = origText;
				}
			};

			xhr.send( new FormData( ticketForm ) );
		} );
	}
} );
