// components/BulkBar.jsx
import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Button, SelectControl } from '@wordpress/components';
import { SWH_INBOX_STORE } from '../store/inbox';
import { dispatchBulk } from '../util/bulk-action';

const STATUS_OPTIONS = [
	{ value: '',            label: __( 'Status…', 'simple-wp-helpdesk' ) },
	{ value: 'open',        label: 'Open' },
	{ value: 'in_progress', label: 'In Progress' },
	{ value: 'resolved',    label: 'Resolved' },
	{ value: 'closed',      label: 'Closed' },
];

const PRIORITY_OPTIONS = [
	{ value: '',       label: __( 'Priority…', 'simple-wp-helpdesk' ) },
	{ value: 'low',    label: 'Low' },
	{ value: 'normal', label: 'Normal' },
	{ value: 'high',   label: 'High' },
	{ value: 'urgent', label: 'Urgent' },
];

export const BulkBar = () => {
	const selectedIds = useSelect( ( s ) => s( SWH_INBOX_STORE ).getSelectedIds(), [] );
	const inboxTickets = useSelect( ( s ) => s( SWH_INBOX_STORE ).getTickets(), [] );
	const { clearSelected, fetchTickets } = useDispatch( SWH_INBOX_STORE );

	const [ running, setRunning ] = useState( false );

	if ( selectedIds.length === 0 ) return null;

	// Build version map from current inbox tickets.
	const versionMap = {};
	for ( const t of inboxTickets ) {
		if ( selectedIds.includes( t.id ) ) versionMap[ t.id ] = t.version || 1;
	}

	const run = async ( action, params ) => {
		setRunning( true );
		try {
			const result = await dispatchBulk( action, params, selectedIds, versionMap );
			clearSelected();
			fetchTickets();
			if ( result.conflicts?.length ) {
				window.alert(
					__( 'Some tickets had concurrent updates and were skipped.', 'simple-wp-helpdesk' )
					+ '\n' + result.conflicts.map( ( c ) => `#${ c.id }` ).join( ', ' )
				);
			}
		} catch ( err ) {
			window.alert( err.message );
		} finally {
			setRunning( false );
		}
	};

	return (
		<div className="swh-bulk-bar">
			<span className="swh-bulk-bar-count">
				<strong>{ selectedIds.length }</strong> { __( 'selected', 'simple-wp-helpdesk' ) }
			</span>
			<SelectControl
				hideLabelFromVision label="Status"
				options={ STATUS_OPTIONS }
				value=""
				onChange={ ( v ) => v && run( 'status', { status: v } ) }
				disabled={ running }
				__nextHasNoMarginBottom
			/>
			<SelectControl
				hideLabelFromVision label="Priority"
				options={ PRIORITY_OPTIONS }
				value=""
				onChange={ ( v ) => v && run( 'priority', { priority: v } ) }
				disabled={ running }
				__nextHasNoMarginBottom
			/>
			<Button variant="primary" onClick={ () => run( 'close', {} ) } isBusy={ running }>
				{ __( 'Close', 'simple-wp-helpdesk' ) }
			</Button>
			<span className="swh-bulk-bar-hint">
				{ __( 'Esc to deselect · ⌘K for more', 'simple-wp-helpdesk' ) }
			</span>
		</div>
	);
};
