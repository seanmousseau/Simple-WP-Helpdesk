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
} );
