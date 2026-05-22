import { useDispatch, useSelect } from '@wordpress/data';
import { SWH_INBOX_STORE } from '../store/inbox';
import { SWH_DRAWER_STORE } from '../store/drawer';

const fmtAge = ( ts ) => {
	if ( ! ts ) {
		return '—';
	}
	const diff = Math.max( 0, Math.floor( Date.now() / 1000 ) - ts );
	if ( diff < 60 ) {
		return `${ diff }s`;
	}
	if ( diff < 3600 ) {
		return `${ Math.floor( diff / 60 ) }m`;
	}
	if ( diff < 86400 ) {
		return `${ Math.floor( diff / 3600 ) }h`;
	}
	return `${ Math.floor( diff / 86400 ) }d`;
};

export const TicketRow = ( { ticket } ) => {
	const selected = useSelect(
		( s ) => s( SWH_INBOX_STORE ).getSelectedIds().includes( ticket.id ),
		[ ticket.id ]
	);
	const { toggleSelected } = useDispatch( SWH_INBOX_STORE );
	const { open: openDrawer } = useDispatch( SWH_DRAWER_STORE );

	const openEditor = () => {
		openDrawer( ticket.id );
	};

	const handleRowKeyDown = ( e ) => {
		if ( e.key === 'Enter' || e.key === ' ' ) {
			e.preventDefault();
			openEditor();
		}
	};

	const handleCheckKeyDown = ( e ) => {
		if ( e.key === 'Enter' || e.key === ' ' ) {
			e.preventDefault();
			e.stopPropagation();
			toggleSelected( ticket.id );
		}
	};

	return (
		<div
			className={ `swh-inbox-row${ selected ? ' is-selected' : '' }` }
			onClick={ openEditor }
			onKeyDown={ handleRowKeyDown }
			role="row"
			tabIndex={ 0 }
		>
			<button
				type="button"
				className="swh-inbox-row-check"
				onClick={ ( e ) => {
					e.stopPropagation();
					toggleSelected( ticket.id );
				} }
				onKeyDown={ handleCheckKeyDown }
				aria-label={ `Select ticket #${ ticket.id }` }
				aria-pressed={ selected }
			>
				<input
					type="checkbox"
					checked={ selected }
					readOnly
					tabIndex={ -1 }
					aria-hidden="true"
				/>
			</button>
			<span className="swh-inbox-row-id" role="cell">
				#{ ticket.id }
			</span>
			<span className="swh-inbox-row-title" role="cell">
				{ ticket.title }
			</span>
			<span className="swh-inbox-row-email" role="cell">
				{ ticket.email }
			</span>
			<span className="swh-inbox-row-age" role="cell">
				{ fmtAge( ticket.last_activity ) }
			</span>
		</div>
	);
};
