// components/BulkBar.jsx
import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Button, SelectControl } from '@wordpress/components';
import { SWH_INBOX_STORE } from '../store/inbox';
import { dispatchBulk } from '../util/bulk-action';

// v4.1.1 (#537): render operator-configured priority + status labels as
// the dropdown DISPLAY text while keeping the token form as the option
// VALUE. The bulk-action dispatcher downstream sends the token form to
// the admin REST controller, which canonicalizes via swh_update_ticket_fields().
const DEFAULT_STATUS_LABELS = [ 'Open', 'In Progress', 'Resolved', 'Closed' ];
const DEFAULT_PRIORITY_LABELS = [ 'Low', 'Medium', 'High' ];

const readLocalizedLabels = ( key, fallback ) => {
	const bag = ( typeof window !== 'undefined' && window.swhInbox ) || {};
	const list = Array.isArray( bag[ key ] ) ? bag[ key ].filter( ( v ) => typeof v === 'string' && v !== '' ) : [];
	return list.length ? list : fallback;
};

const tokenize = ( label ) =>
	String( label || '' ).toLowerCase().replace( /[\s-]+/g, '_' );

const getStatusOptions = () => [
	{ value: '', label: __( 'Status…', 'simple-wp-helpdesk' ) },
	...readLocalizedLabels( 'statusLabels', DEFAULT_STATUS_LABELS ).map( ( label ) => ( { value: tokenize( label ), label } ) ),
];

const getPriorityOptions = () => [
	{ value: '', label: __( 'Priority…', 'simple-wp-helpdesk' ) },
	...readLocalizedLabels( 'priorityLabels', DEFAULT_PRIORITY_LABELS ).map( ( label ) => ( { value: tokenize( label ), label } ) ),
];

export const BulkBar = () => {
	const selectedIds = useSelect(
		( s ) => s( SWH_INBOX_STORE ).getSelectedIds(),
		[]
	);
	const inboxTickets = useSelect(
		( s ) => s( SWH_INBOX_STORE ).getTickets(),
		[]
	);
	const { clearSelected, fetchTickets } = useDispatch( SWH_INBOX_STORE );

	const [ running, setRunning ] = useState( false );

	if ( selectedIds.length === 0 ) {
		return null;
	}

	// Build version map from current inbox tickets.
	const versionMap = {};
	for ( const t of inboxTickets ) {
		if ( selectedIds.includes( t.id ) ) {
			versionMap[ t.id ] = t.version || 1;
		}
	}

	const run = async ( action, params ) => {
		setRunning( true );
		try {
			const result = await dispatchBulk(
				action,
				params,
				selectedIds,
				versionMap
			);
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
			setRunning( false );
		}
	};

	return (
		<div
			className="swh-bulk-bar"
			role="toolbar"
			aria-label={ __( 'Bulk actions', 'simple-wp-helpdesk' ) }
		>
			<span className="swh-bulk-bar-count">
				<strong>{ selectedIds.length }</strong>{ ' ' }
				{ __( 'selected', 'simple-wp-helpdesk' ) }
			</span>
			<SelectControl
				hideLabelFromVision
				label={ __( 'Status', 'simple-wp-helpdesk' ) }
				options={ getStatusOptions() }
				value=""
				onChange={ ( v ) => v && run( 'status', { status: v } ) }
				disabled={ running }
				__nextHasNoMarginBottom
			/>
			<SelectControl
				hideLabelFromVision
				label={ __( 'Priority', 'simple-wp-helpdesk' ) }
				options={ getPriorityOptions() }
				value=""
				onChange={ ( v ) => v && run( 'priority', { priority: v } ) }
				disabled={ running }
				__nextHasNoMarginBottom
			/>
			<Button
				variant="primary"
				onClick={ () => run( 'close', {} ) }
				isBusy={ running }
			>
				{ __( 'Close', 'simple-wp-helpdesk' ) }
			</Button>
			<span className="swh-bulk-bar-hint">
				{ __( 'Esc to deselect · ⌘K for more', 'simple-wp-helpdesk' ) }
			</span>
		</div>
	);
};
