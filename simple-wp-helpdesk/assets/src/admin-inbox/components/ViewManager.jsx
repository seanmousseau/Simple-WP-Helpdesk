// components/ViewManager.jsx
import { useState } from '@wordpress/element';
import { sprintf } from '@wordpress/i18n';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Button, Popover, TextControl } from '@wordpress/components';
import { SWH_INBOX_STORE } from '../store/inbox';
import { SWH_VIEWS_STORE } from '../store/views';

/**
 * Renders the "+ Add view" button + popover for saving the current filter
 * state as a personal view.
 */
export const AddViewButton = () => {
	const [ isOpen, setOpen ] = useState( false );
	const [ name, setName ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ saveError, setSaveError ] = useState( '' );
	const { view, sort } = useSelect(
		( s ) => ( {
			view: s( SWH_INBOX_STORE ).getView(),
			sort: s( SWH_INBOX_STORE ).getSort(),
		} ),
		[]
	);
	const { createView } = useDispatch( SWH_VIEWS_STORE );

	const onSave = async () => {
		if ( ! name.trim() ) {
			return;
		}
		setSaving( true );
		setSaveError( '' );
		try {
			await createView( {
				name: name.trim(),
				scope: 'personal',
				filters: { view }, // capture current default-view selection as a starting filter
				sort,
				columns: arrayDefaultColumns(),
			} );
			setName( '' );
			setOpen( false );
		} catch ( err ) {
			setSaveError( err.message || __( 'Failed to save view.', 'simple-wp-helpdesk' ) );
		} finally {
			setSaving( false );
		}
	};

	return (
		<>
			<button
				type="button"
				className="swh-inbox-view-add"
				onClick={ () => setOpen( ! isOpen ) }
			>
				{ __( '+ Add view', 'simple-wp-helpdesk' ) }
			</button>
			{ isOpen && (
				<Popover
					placement="right-start"
					onClose={ () => setOpen( false ) }
				>
					<div className="swh-view-popover">
						<TextControl
							label={ __( 'View name', 'simple-wp-helpdesk' ) }
							value={ name }
							onChange={ setName }
							// eslint-disable-next-line jsx-a11y/no-autofocus
							autoFocus
							__nextHasNoMarginBottom
						/>
						<p className="swh-view-popover-hint">
							{ __(
								'Captures current view + sort + columns.',
								'simple-wp-helpdesk'
							) }
						</p>
						{ saveError && (
							<p className="swh-view-popover-error" role="alert">
								{ saveError }
							</p>
						) }
						<div className="swh-view-popover-actions">
							<Button onClick={ () => setOpen( false ) }>
								{ __( 'Cancel', 'simple-wp-helpdesk' ) }
							</Button>
							<Button
								variant="primary"
								onClick={ onSave }
								disabled={ ! name.trim() || saving }
								isBusy={ saving }
							>
								{ __( 'Save', 'simple-wp-helpdesk' ) }
							</Button>
						</div>
					</div>
				</Popover>
			) }
		</>
	);
};

const arrayDefaultColumns = () => [
	'id',
	'subject',
	'customer',
	'activity',
	'age',
];

/**
 * `⋯` menu per personal view: rename, delete.
 * Global views are read-only here (admins manage them in Settings).
 *
 * @param {{ view: { scope: string, id: string, name: string } }} props
 */
export const ViewRowMenu = ( { view } ) => {
	const [ isOpen, setOpen ] = useState( false );
	const { updateView, deleteView } = useDispatch( SWH_VIEWS_STORE );

	if ( view.scope === 'global' ) {
		return null;
	}

	const onRename = () => {
		// eslint-disable-next-line no-alert
		const newName = window.prompt(
			__( 'Rename view to:', 'simple-wp-helpdesk' ),
			view.name
		);
		if ( newName && newName.trim() ) {
			updateView( view.id, 'personal', { name: newName.trim() } );
		}
		setOpen( false );
	};

	const onDelete = () => {
		if (
			window.confirm( __( 'Delete this view?', 'simple-wp-helpdesk' ) ) // eslint-disable-line no-alert
		) {
			deleteView( view.id, 'personal' );
		}
		setOpen( false );
	};

	return (
		<span className="swh-view-row-menu">
			<button
				type="button"
				className="swh-view-row-menu-trigger"
				onClick={ ( e ) => {
					e.stopPropagation();
					setOpen( ! isOpen );
				} }
				aria-label={ __( 'View actions', 'simple-wp-helpdesk' ) }
			>
				{ '⋯' /* ⋯ */ }
			</button>
			{ isOpen && (
				<Popover
					position="bottom right"
					onClose={ () => setOpen( false ) }
				>
					<button type="button" className="swh-view-menu-item" onClick={ onRename }>
						{ __( 'Rename', 'simple-wp-helpdesk' ) }
					</button>
					<button
						type="button"
						className="swh-view-menu-item swh-view-menu-item-danger"
						onClick={ onDelete }
					>
						{ __( 'Delete', 'simple-wp-helpdesk' ) }
					</button>
				</Popover>
			) }
		</span>
	);
};
