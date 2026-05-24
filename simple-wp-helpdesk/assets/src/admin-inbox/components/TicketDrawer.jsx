// components/TicketDrawer.jsx
import { useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { SWH_DRAWER_STORE } from '../store/drawer';
import { DrawerHeader } from './DrawerHeader';
import { MetaRow } from './MetaRow';
import { Thread } from './Thread';
import { Composer } from './Composer';
import { ConflictBanner } from './ConflictBanner';

export const TicketDrawer = () => {
	const { isOpen, ticket, loading, error, conflict, draftBody } = useSelect(
		( s ) => ( {
			isOpen: s( SWH_DRAWER_STORE ).isOpen(),
			ticket: s( SWH_DRAWER_STORE ).getTicket(),
			loading: s( SWH_DRAWER_STORE ).getLoading(),
			error: s( SWH_DRAWER_STORE ).getError(),
			conflict: s( SWH_DRAWER_STORE ).getConflict(),
			draftBody: s( SWH_DRAWER_STORE ).getDraftBody(),
		} ),
		[]
	);
	const { fetchTicket, close } = useDispatch( SWH_DRAWER_STORE );

	useEffect( () => {
		// OPEN reducer presets loading:true, so a `!loading` guard would
		// skip the fetch and leave the drawer stuck on "Loading ticket…".
		// Guard against ticket already present (re-open) and error state.
		if ( isOpen && ! ticket && ! error ) {
			fetchTicket();
		}
	}, [ isOpen ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		const onKey = ( e ) => {
			if ( e.key === 'Escape' && isOpen ) {
				if ( draftBody ) {
					// eslint-disable-next-line no-alert
					const confirmed = window.confirm(
						__( 'Discard draft?', 'simple-wp-helpdesk' )
					);
					if ( ! confirmed ) {
						return;
					}
				}
				close();
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ isOpen, draftBody ] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( ! isOpen ) {
		return null;
	}

	// Shared close handler: applies the draft-body guard before closing so that
	// clicking the backdrop uses the same confirmation flow as pressing Escape.
	const requestClose = () => {
		if ( draftBody ) {
			// eslint-disable-next-line no-alert
			const confirmed = window.confirm(
				__( 'Discard draft?', 'simple-wp-helpdesk' )
			);
			if ( ! confirmed ) {
				return;
			}
		}
		close();
	};

	return (
		// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
		<div
			className="swh-drawer-backdrop"
			onClick={ ( e ) => {
				if ( e.target === e.currentTarget ) {
					requestClose();
				}
			} }
		>
			<aside
				className="swh-drawer"
				role="dialog"
				aria-modal="true"
				aria-label={
					ticket
						? `${ __(
								'Ticket',
								'simple-wp-helpdesk'
						  ) } #${ ticket.id }`
						: __( 'Ticket drawer', 'simple-wp-helpdesk' )
				}
			>
				{ loading && ! ticket && (
					<div className="swh-drawer-loading">
						{ __( 'Loading ticket…', 'simple-wp-helpdesk' ) }
					</div>
				) }
				{ error && <div className="swh-drawer-error">{ error }</div> }
				{ ticket && (
					<>
						<DrawerHeader ticket={ ticket } />
						{ conflict && <ConflictBanner /> }
						<MetaRow ticket={ ticket } />
						<Thread ticket={ ticket } />
						<Composer />
					</>
				) }
			</aside>
		</div>
	);
};
