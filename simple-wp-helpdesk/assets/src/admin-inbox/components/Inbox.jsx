import { TopBar } from './TopBar';
import { LeftRail } from './LeftRail';
import { FilterChips } from './FilterChips';
import { TicketList } from './TicketList';

export const Inbox = () => (
	<div className="swh-inbox">
		<TopBar onOpenPalette={ () => {} /* wired in Phase C */ } />
		<div className="swh-inbox-body">
			<LeftRail />
			<div className="swh-inbox-main">
				<FilterChips />
				<TicketList />
			</div>
		</div>
	</div>
);
