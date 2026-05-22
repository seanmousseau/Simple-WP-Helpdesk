import { useEffect } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { TopBar } from './TopBar';
import { LeftRail } from './LeftRail';
import { FilterChips } from './FilterChips';
import { TicketList } from './TicketList';
import { TicketDrawer } from './TicketDrawer';
import { CommandPalette } from './CommandPalette';
import { SWH_PALETTE_STORE } from '../store/palette';
import { SWH_INBOX_STORE } from '../store/inbox';

export const Inbox = () => {
	const { open: openPalette } = useDispatch( SWH_PALETTE_STORE );
	const { clearSelected } = useDispatch( SWH_INBOX_STORE );

	useEffect( () => {
		const onKey = ( e ) => {
			if ( ( e.metaKey || e.ctrlKey ) && e.key.toLowerCase() === 'k' ) {
				e.preventDefault();
				openPalette();
			}
			if ( e.key === 'Escape' ) {
				clearSelected();
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	return (
		<div className="swh-inbox">
			<TopBar onOpenPalette={ openPalette } />
			<div className="swh-inbox-body">
				<LeftRail />
				<div className="swh-inbox-main">
					<FilterChips />
					<TicketList />
				</div>
			</div>
			<TicketDrawer />
			<CommandPalette />
		</div>
	);
};
