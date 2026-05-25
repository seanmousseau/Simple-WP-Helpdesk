// components/DrawerHeader.jsx
import { useDispatch } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { SWH_DRAWER_STORE } from '../store/drawer';

const fmtDate = ( gmt ) => {
	if ( ! gmt ) {
		return '';
	}
	return new Date( gmt + 'Z' ).toLocaleString();
};

// Delay before auto-clearing the unread indicator. Long enough to be visible
// when the operator opens the drawer for a freshly-replied ticket, short
// enough to feel responsive. Operator sees the badge ↘ then it fades.
const MARK_READ_DELAY_MS = 1500;

export const DrawerHeader = ( { ticket } ) => {
	const { close, patchMeta } = useDispatch( SWH_DRAWER_STORE );

	// Auto-clear the unread flag once the drawer has been open long enough
	// for the operator to register the indicator (#436). Tied to the ticket
	// id so re-opening the same ticket twice doesn't re-fire when nothing
	// changed; tied to `ticket.unread` so we don't dispatch when there's
	// nothing to clear.
	useEffect( () => {
		if ( ! ticket.unread ) {
			return undefined;
		}
		const timer = setTimeout( () => {
			patchMeta( { mark_read: true } );
		}, MARK_READ_DELAY_MS );
		return () => clearTimeout( timer );
	}, [ ticket.id, ticket.unread, patchMeta ] );

	return (
		<div className="swh-drawer-header">
			<div className="swh-drawer-titles">
				<h2 className="swh-drawer-title">
					{ ticket.unread && (
						<span
							className="swh-drawer-unread-dot"
							role="status"
							aria-label={ __(
								'Unread reply',
								'simple-wp-helpdesk'
							) }
						/>
					) }
					#{ ticket.id } · { ticket.title }
				</h2>
				<div className="swh-drawer-meta">
					{ ticket.email } · { __( 'opened', 'simple-wp-helpdesk' ) }{ ' ' }
					{ fmtDate( ticket.created ) }
				</div>
			</div>
			<div className="swh-drawer-actions">
				<a
					href={ `post.php?post=${ ticket.id }&action=edit` }
					className="swh-drawer-open-full"
					title={ __(
						'Open full ticket page',
						'simple-wp-helpdesk'
					) }
					aria-label={ __(
						'Open full ticket page',
						'simple-wp-helpdesk'
					) }
				>
					⤢
				</a>
				<button
					type="button"
					className="swh-drawer-close"
					onClick={ close }
					aria-label={ __( 'Close drawer', 'simple-wp-helpdesk' ) }
				>
					×
				</button>
			</div>
		</div>
	);
};
