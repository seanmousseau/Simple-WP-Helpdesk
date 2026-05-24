import { useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { SWH_INBOX_STORE } from '../store/inbox';
import { TicketRow } from './TicketRow';
import { BulkBar } from './BulkBar';
import { EmptyState } from './EmptyState';

export const TicketList = () => {
	const { tickets, loading, error, view, sort, page, search } = useSelect(
		( s ) => ( {
			tickets: s( SWH_INBOX_STORE ).getTickets(),
			loading: s( SWH_INBOX_STORE ).getLoading(),
			error: s( SWH_INBOX_STORE ).getError(),
			view: s( SWH_INBOX_STORE ).getView(),
			sort: s( SWH_INBOX_STORE ).getSort(),
			page: s( SWH_INBOX_STORE ).getPage(),
			search: s( SWH_INBOX_STORE ).getSearch(),
		} ),
		[]
	);
	const { fetchTickets } = useDispatch( SWH_INBOX_STORE );

	useEffect( () => {
		fetchTickets();
	}, [ view, sort, page, search ] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( loading ) {
		return (
			<div className="swh-inbox-loading">
				{ __( 'Loading…', 'simple-wp-helpdesk' ) }
			</div>
		);
	}
	if ( error ) {
		return <div className="swh-inbox-error">{ error }</div>;
	}
	if ( ! tickets.length ) {
		return (
			<>
				<BulkBar />
				<EmptyState />
			</>
		);
	}

	return (
		<>
			<BulkBar />
			<div className="swh-inbox-list" role="grid">
				{ tickets.map( ( t ) => (
					<TicketRow key={ t.id } ticket={ t } />
				) ) }
			</div>
		</>
	);
};
