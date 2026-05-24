// components/DrawerHeader.jsx
import { useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { SWH_DRAWER_STORE } from '../store/drawer';

const fmtDate = ( gmt ) => {
	if ( ! gmt ) {
		return '';
	}
	return new Date( gmt + 'Z' ).toLocaleString();
};

export const DrawerHeader = ( { ticket } ) => {
	const { close } = useDispatch( SWH_DRAWER_STORE );
	return (
		<div className="swh-drawer-header">
			<div className="swh-drawer-titles">
				<div className="swh-drawer-title">
					#{ ticket.id } · { ticket.title }
				</div>
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
