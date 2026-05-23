// components/CommandPalette.jsx
import { useEffect, useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Modal, TextControl } from '@wordpress/components';
import { SWH_PALETTE_STORE } from '../store/palette';
import { SWH_INBOX_STORE } from '../store/inbox';
import { SWH_DRAWER_STORE } from '../store/drawer';
import { DEFAULT_VIEWS } from '../views/defaults';
import { fuzzyMatch } from '../util/fuzzy';
import { dispatchBulk } from '../util/bulk-action';

export const CommandPalette = () => {
	const { isOpen, query, tickets, loading } = useSelect(
		( s ) => ( {
			isOpen: s( SWH_PALETTE_STORE ).isOpen(),
			query: s( SWH_PALETTE_STORE ).getQuery(),
			tickets: s( SWH_PALETTE_STORE ).getTickets(),
			loading: s( SWH_PALETTE_STORE ).getLoading(),
		} ),
		[]
	);

	const selectedIds = useSelect(
		( s ) => s( SWH_INBOX_STORE ).getSelectedIds(),
		[]
	);
	const inboxTickets = useSelect(
		( s ) => s( SWH_INBOX_STORE ).getTickets(),
		[]
	);

	const { close, setQuery, fetchSuggestions } =
		useDispatch( SWH_PALETTE_STORE );
	const { setView, clearSelected, fetchTickets } =
		useDispatch( SWH_INBOX_STORE );
	const { open: openDrawer } = useDispatch( SWH_DRAWER_STORE );

	// Debounced refetch on query change.
	useEffect( () => {
		if ( ! isOpen ) {
			return;
		}
		const t = setTimeout( () => {
			fetchSuggestions();
		}, 150 );
		return () => clearTimeout( t );
	}, [ query, isOpen ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Prevent double-clicks from triggering duplicate bulk submissions.
	const [ bulkRunning, setBulkRunning ] = useState( false );

	if ( ! isOpen ) {
		return null;
	}

	const matchedViews = fuzzyMatch( query, DEFAULT_VIEWS, ( v ) => v.label );

	// Build version map for selected tickets so palette bulk commands can run.
	const versionMap = {};
	for ( const t of inboxTickets ) {
		if ( selectedIds.includes( t.id ) ) {
			versionMap[ t.id ] = t.version || 1;
		}
	}

	const runBulk = async ( action, params ) => {
		if ( bulkRunning ) {
			return;
		}
		setBulkRunning( true );
		try {
			const result = await dispatchBulk(
				action,
				params,
				selectedIds,
				versionMap
			);
			close();
			clearSelected();
			fetchTickets();
			if ( result.conflicts?.length ) {
				// eslint-disable-next-line no-alert
				window.alert(
					__(
						'Some tickets had concurrent updates and were skipped.',
						'simple-wp-helpdesk'
					) +
						'\n' +
						result.conflicts
							.map( ( c ) => `#${ c.id }` )
							.join( ', ' )
				);
			}
		} catch ( err ) {
			// eslint-disable-next-line no-alert
			window.alert( err.message );
		} finally {
			setBulkRunning( false );
		}
	};

	return (
		<Modal
			title={ __( 'Command palette', 'simple-wp-helpdesk' ) }
			onRequestClose={ close }
			shouldCloseOnEsc
			shouldCloseOnClickOutside
			className="swh-palette-modal"
			__experimentalHideHeader
		>
			<TextControl
				value={ query }
				onChange={ setQuery }
				placeholder={ __(
					'Type a ticket, view, or command…',
					'simple-wp-helpdesk'
				) }
				// eslint-disable-next-line jsx-a11y/no-autofocus
				autoFocus
				__nextHasNoMarginBottom
			/>

			<div className="swh-palette-results">
				{ selectedIds.length > 0 && (
					<div className="swh-palette-group">
						<div className="swh-palette-group-label">
							{ __( 'Bulk commands', 'simple-wp-helpdesk' ) } (
							{ selectedIds.length })
						</div>
						<button
							type="button"
							className="swh-palette-item swh-palette-item-primary"
							onClick={ () => runBulk( 'close', {} ) }
							disabled={ bulkRunning }
						>
							<span>&#9889;</span>{ ' ' }
							<strong>
								{ __( 'Close', 'simple-wp-helpdesk' ) }
							</strong>{ ' ' }
							{ __( 'selected tickets', 'simple-wp-helpdesk' ) }
						</button>
						<button
							type="button"
							className="swh-palette-item"
							onClick={ () =>
								runBulk( 'status', { status: 'resolved' } )
							}
							disabled={ bulkRunning }
						>
							<span>&#9889;</span>{ ' ' }
							{ __(
								'Mark selected as Resolved',
								'simple-wp-helpdesk'
							) }
						</button>
						<button
							type="button"
							className="swh-palette-item"
							onClick={ () =>
								runBulk( 'status', { status: 'in_progress' } )
							}
							disabled={ bulkRunning }
						>
							<span>&#9889;</span>{ ' ' }
							{ __(
								'Mark selected as In Progress',
								'simple-wp-helpdesk'
							) }
						</button>
					</div>
				) }

				<div className="swh-palette-group">
					<div className="swh-palette-group-label">
						{ __( 'Tickets', 'simple-wp-helpdesk' ) }
					</div>
					{ loading && (
						<div className="swh-palette-loading">
							{ __( 'Loading…', 'simple-wp-helpdesk' ) }
						</div>
					) }
					{ tickets.map( ( t ) => (
						<button
							key={ t.id }
							type="button"
							className="swh-palette-item"
							onClick={ () => {
								openDrawer( t.id );
								close();
							} }
						>
							<span>&#128233;</span>
							<span className="swh-palette-id">#{ t.id }</span>
							<span className="swh-palette-title">
								{ t.title }
							</span>
							<span className="swh-palette-meta">
								{ t.status }
							</span>
						</button>
					) ) }
				</div>

				{ matchedViews.length > 0 && (
					<div className="swh-palette-group">
						<div className="swh-palette-group-label">
							{ __( 'Saved views', 'simple-wp-helpdesk' ) }
						</div>
						{ matchedViews.map( ( v ) => (
							<button
								key={ v.key }
								type="button"
								className="swh-palette-item"
								onClick={ () => {
									setView( v.key );
									close();
								} }
							>
								<span>&#128193;</span>{ ' ' }
								{ __(
									'Switch to view:',
									'simple-wp-helpdesk'
								) }{ ' ' }
								<strong>{ v.label }</strong>
							</button>
						) ) }
					</div>
				) }
			</div>

			<div className="swh-palette-footer">
				<span>
					{ __(
						'↑↓ navigate · ↵ select · esc close',
						'simple-wp-helpdesk'
					) }
				</span>
				{ selectedIds.length > 0 && (
					<span>
						{ selectedIds.length }{ ' ' }
						{ __( 'tickets selected', 'simple-wp-helpdesk' ) }
					</span>
				) }
			</div>
		</Modal>
	);
};
