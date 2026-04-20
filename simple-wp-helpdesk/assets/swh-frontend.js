/**
 * Simple WP Helpdesk — frontend ticket form interactions.
 *
 * Handles file type/size validation, inline error display, the
 * ticket lookup form toggle, CSAT widget, template selector,
 * drag-and-drop file upload, and upload progress.
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
	 * Returns an SVG DOM element for a file icon matching the given extension.
	 *
	 * Uses hardcoded SVG path data (no user input); built via DOM to avoid innerHTML.
	 *
	 * @param {string} ext - Lowercase file extension.
	 * @return {SVGElement} SVG element with aria-hidden.
	 */
	function swhFileIconEl( ext ) {
		const imgExts = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg' ];
		const docExts = [ 'doc', 'docx', 'txt', 'rtf', 'odt' ];
		let pathD;
		if ( imgExts.indexOf( ext ) !== -1 ) {
			pathD = 'M21 15l-5-5L5 21M3 3h18v18H3z M8.5 10a1.5 1.5 0 100-3 1.5 1.5 0 000 3z';
		} else if ( [ 'pdf' ].indexOf( ext ) !== -1 ) {
			pathD = 'M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6zm-1 1v5h5M8 13h8M8 17h8M8 9h2';
		} else if ( docExts.indexOf( ext ) !== -1 ) {
			pathD = 'M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6zm-1 1v5h5M8 13h8M8 17h5';
		} else {
			pathD = 'M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6zm-1 1v5h5';
		}
		const ns  = 'http://www.w3.org/2000/svg';
		const svg = document.createElementNS( ns, 'svg' );
		svg.setAttribute( 'class', 'swh-file-icon' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'fill', 'none' );
		svg.setAttribute( 'stroke', 'currentColor' );
		svg.setAttribute( 'stroke-width', '2' );
		svg.setAttribute( 'stroke-linecap', 'round' );
		svg.setAttribute( 'stroke-linejoin', 'round' );
		svg.setAttribute( 'aria-hidden', 'true' );
		const path = document.createElementNS( ns, 'path' );
		path.setAttribute( 'd', pathD );
		svg.appendChild( path );
		return svg;
	}

	/**
	 * Formats a byte count as a human-readable string.
	 *
	 * @param {number} bytes - File size in bytes.
	 * @return {string} E.g. "1.2 MB" or "340 KB".
	 */
	function swhFormatSize( bytes ) {
		if ( bytes >= 1048576 ) { return ( bytes / 1048576 ).toFixed( 1 ) + ' MB'; }
		if ( bytes >= 1024 )    { return Math.round( bytes / 1024 ) + ' KB'; }
		return bytes + ' B';
	}

	/**
	 * Validates the given FileList; calls swhShowFileError on failure.
	 *
	 * @param {HTMLInputElement} input - The file input.
	 * @param {FileList}         files - Files to validate.
	 * @return {boolean} True if all files pass validation.
	 */
	function swhValidateFiles( input, files ) {
		swhShowFileError( input, '' );
		if ( swhConfig.maxFiles > 0 && files.length > swhConfig.maxFiles ) {
			swhShowFileError( input, swhConfig.i18n.maxFilesError.replace( '%d', swhConfig.maxFiles ) );
			return false;
		}
		let errorMsg = '';
		for ( let i = 0; i < files.length; i++ ) {
			const file = files[ i ];
			const ext  = file.name.split( '.' ).pop().toLowerCase();
			if ( swhConfig.allowedExts.indexOf( ext ) === -1 ) {
				errorMsg += swhConfig.i18n.invalidType.replace( '%s', file.name ) + '\n';
			}
			if ( file.size > maxBytes ) {
				errorMsg += swhConfig.i18n.sizeExceeded.replace( '%s', file.name ).replace( '%d', swhConfig.maxMb ) + '\n';
			}
		}
		if ( errorMsg !== '' ) {
			swhShowFileError( input, errorMsg );
			return false;
		}
		return true;
	}

	/**
	 * Builds a file-summary line showing icon + name + size using safe DOM methods.
	 *
	 * @param {File} file - A File object from a FileList.
	 * @return {HTMLElement} A span containing the icon, name, and size.
	 */
	function swhBuildFileSummary( file ) {
		const ext  = file.name.split( '.' ).pop().toLowerCase();
		const span = document.createElement( 'span' );
		span.className = 'swh-attachment-item';
		span.appendChild( swhFileIconEl( ext ) );
		const name      = document.createElement( 'span' );
		name.textContent = file.name;
		const size      = document.createElement( 'span' );
		size.className  = 'swh-file-size';
		size.textContent = '(' + swhFormatSize( file.size ) + ')';
		span.appendChild( name );
		span.appendChild( size );
		return span;
	}

	/**
	 * Upgrades all .swh-file-input elements: drag-and-drop (#273),
	 * file summary with icons and sizes (#274), and validation.
	 */
	document.querySelectorAll( '.swh-file-input' ).forEach( function ( input ) {
		const wrap = input.parentNode;

		if ( wrap.classList.contains( 'swh-drop-zone' ) ) {
			return; // Already upgraded.
		}

		const zone      = document.createElement( 'div' );
		zone.className  = 'swh-drop-zone';

		const lbl       = document.createElement( 'div' );
		lbl.className   = 'swh-drop-zone-label';
		const strong    = document.createElement( 'strong' );
		strong.textContent = ( swhConfig.i18n && swhConfig.i18n.dropLabel ) || 'Choose files or drag & drop here';
		lbl.appendChild( strong );

		const selected      = document.createElement( 'div' );
		selected.className  = 'swh-drop-zone-selected';

		wrap.insertBefore( zone, input );
		zone.appendChild( input );
		zone.appendChild( lbl );
		zone.appendChild( selected );

		function updateSelected( files ) {
			while ( selected.firstChild ) { selected.removeChild( selected.firstChild ); }
			if ( ! files || files.length === 0 ) { return; }
			Array.from( files ).forEach( function ( f ) {
				selected.appendChild( swhBuildFileSummary( f ) );
			} );
		}

		input.addEventListener( 'change', function () {
			if ( swhValidateFiles( input, input.files ) ) {
				updateSelected( input.files );
			} else {
				input.value = '';
				updateSelected( null );
			}
		} );

		// Drag-and-drop events.
		zone.addEventListener( 'dragover', function ( e ) {
			e.preventDefault();
			zone.classList.add( 'swh-drop-zone--dragover' );
		} );

		zone.addEventListener( 'dragleave', function ( e ) {
			if ( ! zone.contains( e.relatedTarget ) ) {
				zone.classList.remove( 'swh-drop-zone--dragover' );
			}
		} );

		zone.addEventListener( 'drop', function ( e ) {
			e.preventDefault();
			zone.classList.remove( 'swh-drop-zone--dragover' );
			const dt = e.dataTransfer;
			if ( ! dt || ! dt.files || dt.files.length === 0 ) { return; }
			let attached = false;
			try {
				// Assign dropped files to the input (modern browsers support DataTransfer constructor).
				const transfer = new DataTransfer();
				Array.from( dt.files ).forEach( function ( f ) { transfer.items.add( f ); } );
				input.files = transfer.files;
				attached = input.files && input.files.length > 0;
			} catch ( err ) {
				// DataTransfer constructor not supported — files cannot be attached.
			}
			if ( attached && swhValidateFiles( input, input.files ) ) {
				updateSelected( input.files );
			} else {
				updateSelected( null );
			}
		} );
	} );

	/**
	 * Replaces .swh-timestamp text with a human-relative string ("3 hours ago", etc.).
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
	 * Lookup form toggle with CSS slide/fade transition (#272).
	 * The form starts hidden via CSS (max-height: 0); toggling the
	 * .swh-lookup-visible class triggers the transition.
	 */
	const toggleLink = document.getElementById( 'swh-toggle-lookup' );
	if ( toggleLink ) {
		const lookupForm = document.getElementById( 'swh-lookup-form' );
		toggleLink.setAttribute( 'aria-expanded', 'false' );

		toggleLink.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			const expanded = lookupForm.classList.contains( 'swh-lookup-visible' );
			lookupForm.classList.toggle( 'swh-lookup-visible', ! expanded );
			toggleLink.setAttribute( 'aria-expanded', String( ! expanded ) );
		} );
	}

	/**
	 * CSAT star widget (#262 keyboard, #275 auto-dismiss).
	 *
	 * Radiogroup semantics: arrow keys cycle focus; Enter/Space selects.
	 * Auto-dismisses after 60 s if the client ignores the widget.
	 */
	const csatBox = document.getElementById( 'swh-csat' );
	if ( csatBox ) {
		const stars      = Array.from( csatBox.querySelectorAll( '.swh-csat-star' ) );
		const skipLink   = document.getElementById( 'swh-csat-skip' );
		const thanksBox  = document.getElementById( 'swh-csat-thanks' );
		const successBox = document.getElementById( 'swh-close-success' );

		// Apply radiogroup semantics to the star container (#262).
		const starsContainer = csatBox.querySelector( '.swh-csat-stars' );
		if ( starsContainer ) {
			starsContainer.setAttribute( 'role', 'radiogroup' );
			starsContainer.setAttribute( 'aria-label',
				( swhConfig.i18n && swhConfig.i18n.csatGroupLabel ) || 'Rate your support experience' );
		}
		stars.forEach( function ( btn, idx ) {
			btn.setAttribute( 'role', 'radio' );
			btn.setAttribute( 'aria-checked', 'false' );
			btn.setAttribute( 'tabindex', idx === 0 ? '0' : '-1' );
		} );

		function swhHighlightUpTo( upTo ) {
			stars.forEach( function ( s, i ) {
				s.classList.toggle( 'swh-csat-star--active', i <= upTo );
			} );
		}

		// Hover.
		stars.forEach( function ( btn, idx ) {
			btn.addEventListener( 'mouseenter', function () { swhHighlightUpTo( idx ); } );
		} );
		csatBox.addEventListener( 'mouseleave', function () {
			stars.forEach( function ( s ) { s.classList.remove( 'swh-csat-star--active' ); } );
		} );

		// Arrow-key navigation (#262).
		stars.forEach( function ( btn, idx ) {
			btn.addEventListener( 'keydown', function ( e ) {
				let next = idx;
				if ( e.key === 'ArrowRight' || e.key === 'ArrowUp' ) {
					next = Math.min( idx + 1, stars.length - 1 );
				} else if ( e.key === 'ArrowLeft' || e.key === 'ArrowDown' ) {
					next = Math.max( idx - 1, 0 );
				} else if ( e.key === 'Enter' || e.key === ' ' ) {
					e.preventDefault();
					btn.click();
					return;
				} else {
					return;
				}
				e.preventDefault();
				stars.forEach( function ( s, i ) { s.setAttribute( 'tabindex', i === next ? '0' : '-1' ); } );
				stars[ next ].focus();
				swhHighlightUpTo( next );
			} );
		} );

		function swhSubmitCsat( rating ) {
			const ticketId = csatBox.getAttribute( 'data-ticket' );
			const nonce    = csatBox.getAttribute( 'data-nonce' );
			const ajaxUrl  = csatBox.getAttribute( 'data-ajaxurl' );
			const body     = 'action=swh_submit_csat&ticket_id=' + encodeURIComponent( ticketId ) +
				'&rating=' + encodeURIComponent( rating ) + '&nonce=' + encodeURIComponent( nonce );
			const xhr = new XMLHttpRequest();
			xhr.open( 'POST', ajaxUrl );
			xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
			xhr.onload = function () {
				try {
					const json = JSON.parse( xhr.responseText );
					if ( json.success ) {
						csatBox.style.display = 'none';
						if ( thanksBox ) { thanksBox.style.display = ''; }
					}
				} catch ( err ) {
					// Malformed response — leave widget visible so the client can retry.
				}
			};
			xhr.send( body );
		}

		stars.forEach( function ( btn, idx ) {
			btn.addEventListener( 'click', function () {
				stars.forEach( function ( s ) { s.setAttribute( 'aria-checked', 'false' ); } );
				btn.setAttribute( 'aria-checked', 'true' );
				swhHighlightUpTo( idx );
				swhSubmitCsat( btn.getAttribute( 'data-rating' ) );
			} );
		} );

		if ( skipLink ) {
			skipLink.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				csatBox.style.display = 'none';
				if ( successBox ) { successBox.style.display = ''; }
			} );
		}

		// #275: Auto-dismiss after 60 s if the client ignores the widget.
		setTimeout( function () {
			if ( csatBox.style.display !== 'none' ) {
				csatBox.style.display = 'none';
				if ( successBox ) { successBox.style.display = ''; }
			}
		}, 60000 );
	}

	/**
	 * Ticket template selector — pre-fills the description textarea.
	 */
	const requestTypeSelect = document.getElementById( 'swh-request-type' );
	if ( requestTypeSelect && swhConfig.templates && swhConfig.templates.length > 0 ) {
		const descField = document.getElementById( 'swh-desc' );
		requestTypeSelect.addEventListener( 'change', function () {
			if ( ! descField ) { return; }
			const selected = this.value;
			if ( ! selected ) { return; }
			const tmpl = swhConfig.templates.find( function ( t ) { return t.label === selected; } );
			if ( tmpl ) {
				if ( descField.value === '' || swhConfig.templates.some( function ( t ) { return t.body === descField.value; } ) ) {
					descField.value = tmpl.body;
				}
			}
		} );
	}

	/**
	 * Upload progress indicator — shows indeterminate bar on file submission.
	 */
	const ticketForm = document.getElementById( 'swh-ticket-form' );
	if ( ticketForm ) {
		ticketForm.addEventListener( 'submit', function () {
			const fileInput = ticketForm.querySelector( '.swh-file-input' );
			if ( ! fileInput || fileInput.files.length === 0 ) { return; }

			const submitBtn = ticketForm.querySelector( '[type="submit"]' );
			if ( submitBtn ) {
				submitBtn.value = 'Uploading\u2026';
				setTimeout( function () { submitBtn.disabled = true; }, 0 );
			}

			const wrap     = document.createElement( 'div' );
			wrap.className = 'swh-progress-bar swh-progress-bar--indeterminate';
			const fill     = document.createElement( 'div' );
			fill.className = 'swh-progress-fill';
			wrap.appendChild( fill );
			ticketForm.appendChild( wrap );
		} );
	}
} );
