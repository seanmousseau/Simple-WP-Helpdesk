// components/Composer.jsx
import { useState, useRef } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	CheckboxControl,
	TextareaControl,
} from '@wordpress/components';
import { SWH_DRAWER_STORE } from '../store/drawer';

const ACCEPTED_EXT = [ 'jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt' ];
const MAX_MB = 5;
const MAX_COUNT = 5;

export const Composer = () => {
	const { body, internal, markResolved, saving, files } = useSelect(
		( s ) => ( {
			body: s( SWH_DRAWER_STORE ).getDraftBody(),
			internal: s( SWH_DRAWER_STORE ).getDraftInternal(),
			markResolved: s( SWH_DRAWER_STORE ).getDraftMarkResolved(),
			saving: s( SWH_DRAWER_STORE ).getSaving(),
			files: s( SWH_DRAWER_STORE ).getDraftFiles(),
		} ),
		[]
	);
	const {
		setDraft,
		setDraftInternal,
		setDraftMarkResolved,
		setDraftFiles,
		sendReply,
	} = useDispatch( SWH_DRAWER_STORE );

	const [ fileError, setFileError ] = useState( '' );
	const fileInputRef = useRef( null );

	const onKey = ( e ) => {
		if ( e.key === 'Enter' && ( e.metaKey || e.ctrlKey ) ) {
			e.preventDefault();
			if ( ( body || files.length > 0 ) && ! saving ) {
				sendReply();
			}
		}
	};

	const onFileChange = ( e ) => {
		setFileError( '' );
		const incoming = Array.from( e.target.files || [] );
		const errors = [];
		const accepted = [];
		for ( const f of incoming ) {
			const ext = ( f.name.split( '.' ).pop() || '' ).toLowerCase();
			if ( ! ACCEPTED_EXT.includes( ext ) ) {
				errors.push( `${ f.name }: file type not allowed` );
				continue;
			}
			if ( f.size > MAX_MB * 1024 * 1024 ) {
				errors.push(
					sprintf(
						/* translators: 1: filename, 2: max MB */
						__( '%1$s: exceeds %2$dMB limit', 'simple-wp-helpdesk' ),
						f.name,
						MAX_MB
					)
				);
				continue;
			}
			accepted.push( f );
		}
		const merged = [ ...files, ...accepted ];
		const dedup = new Map();
		merged.forEach( ( f ) => dedup.set( `${ f.name }:${ f.size }`, f ) );
		let next = Array.from( dedup.values() );
		if ( next.length > MAX_COUNT ) {
			errors.push(
				sprintf(
					/* translators: %d: max file count */
					__( 'Too many files — max %d. Extras were dropped.', 'simple-wp-helpdesk' ),
					MAX_COUNT
				)
			);
			next = next.slice( 0, MAX_COUNT );
		}
		setDraftFiles( next );
		if ( errors.length ) setFileError( errors.join( ' · ' ) );
		// Reset input so re-selecting the same file triggers change.
		if ( fileInputRef.current ) fileInputRef.current.value = '';
	};

	const removeFile = ( idx ) => {
		const next = [ ...files ];
		next.splice( idx, 1 );
		setDraftFiles( next );
		setFileError( '' );
	};

	return (
		<div className="swh-drawer-composer">
			<TextareaControl
				value={ body }
				placeholder={ __(
					'Type your reply… (cmd+Enter to send)',
					'simple-wp-helpdesk'
				) }
				onChange={ setDraft }
				onKeyDown={ onKey }
				__nextHasNoMarginBottom
			/>
			{ files.length > 0 && (
				<ul className="swh-drawer-composer-files">
					{ files.map( ( f, idx ) => (
						<li key={ `${ f.name }:${ f.size }` }>
							<span>
								📎 { f.name } ({ Math.round( f.size / 1024 ) }KB)
							</span>
							<button
								type="button"
								className="swh-drawer-composer-file-remove"
								onClick={ () => removeFile( idx ) }
								aria-label={ sprintf(
									/* translators: %s: filename */
									__( 'Remove %s', 'simple-wp-helpdesk' ),
									f.name
								) }
							>
								×
							</button>
						</li>
					) ) }
				</ul>
			) }
			{ fileError && (
				<div className="swh-drawer-composer-error" role="alert">
					{ fileError }
				</div>
			) }
			<div className="swh-drawer-composer-toolbar">
				<CheckboxControl
					label={ __( 'Internal note', 'simple-wp-helpdesk' ) }
					checked={ internal }
					onChange={ setDraftInternal }
					__nextHasNoMarginBottom
				/>
				<CheckboxControl
					label={ __(
						'Mark resolved on send',
						'simple-wp-helpdesk'
					) }
					checked={ markResolved }
					onChange={ setDraftMarkResolved }
					__nextHasNoMarginBottom
				/>
				<label
					className="swh-drawer-composer-attach"
					title={ sprintf(
						/* translators: 1: max MB, 2: max count */
						__( 'Attach files: JPG, PNG, GIF, PDF, DOC, DOCX, TXT — up to %1$dMB each, max %2$d files', 'simple-wp-helpdesk' ),
						MAX_MB,
						MAX_COUNT
					) }
				>
					📎 { __( 'Attach', 'simple-wp-helpdesk' ) }
					<input
						ref={ fileInputRef }
						type="file"
						multiple
						hidden
						accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt,image/jpeg,image/png,image/gif,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain"
						onChange={ onFileChange }
					/>
				</label>
				<div className="swh-drawer-composer-buttons">
					<Button
						variant="primary"
						disabled={ ( ! body && files.length === 0 ) || saving }
						onClick={ sendReply }
					>
						{ saving
							? __( 'Sending…', 'simple-wp-helpdesk' )
							: __( 'Send reply', 'simple-wp-helpdesk' ) }
					</Button>
				</div>
			</div>
		</div>
	);
};
