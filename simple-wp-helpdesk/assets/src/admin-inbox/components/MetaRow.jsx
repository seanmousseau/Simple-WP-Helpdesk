// components/MetaRow.jsx
import { useDispatch } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';
import { SWH_DRAWER_STORE } from '../store/drawer';

// v4.1.1 (#537): render the operator-configured priority + status labels
// in the dropdown DISPLAY text, while keeping the option VALUE as the
// tokenized form (`Resolved` → `resolved`, `In Progress` → `in_progress`).
// The admin REST controller's response runs `label_to_key()` on each meta
// value, so the SelectControl's `value` prop is the token form — using
// labels as values would never match on round-trip and the select would
// snap back to the first option. The server's swh_update_ticket_fields()
// canonical resolver accepts the token form on the way back.
const DEFAULT_STATUS_LABELS = [ 'Open', 'In Progress', 'Resolved', 'Closed' ];
const DEFAULT_PRIORITY_LABELS = [ 'Low', 'Medium', 'High' ];

const readLocalizedLabels = ( key, fallback ) => {
	const bag = ( typeof window !== 'undefined' && window.swhInbox ) || {};
	const list = Array.isArray( bag[ key ] ) ? bag[ key ].filter( ( v ) => typeof v === 'string' && v !== '' ) : [];
	return list.length ? list : fallback;
};

const tokenize = ( label ) =>
	String( label || '' ).toLowerCase().replace( /[\s-]+/g, '_' );

const getStatusOptions = () =>
	readLocalizedLabels( 'statusLabels', DEFAULT_STATUS_LABELS ).map( ( label ) => ( {
		value: tokenize( label ),
		label,
	} ) );

const getPriorityOptions = () =>
	readLocalizedLabels( 'priorityLabels', DEFAULT_PRIORITY_LABELS ).map( ( label ) => ( {
		value: tokenize( label ),
		label,
	} ) );

// Module-level cache so opening different tickets in the same session
// reuses one fetch.
let cachedTechnicians = null;

export const MetaRow = ( { ticket } ) => {
	const { patchMeta } = useDispatch( SWH_DRAWER_STORE );
	const [ technicians, setTechnicians ] = useState( cachedTechnicians || [] );

	useEffect( () => {
		if ( cachedTechnicians ) {
			return;
		}
		apiFetch( { path: '/swh/v1/technicians' } ).then( ( res ) => {
			const list = Array.isArray( res?.users ) ? res.users : [];
			cachedTechnicians = list;
			setTechnicians( list );
		} );
	}, [] );

	const assigneeOptions = [
		{ value: '0', label: __( 'Unassigned', 'simple-wp-helpdesk' ) },
		...technicians.map( ( u ) => ( {
			value: String( u.id ),
			label: u.name,
		} ) ),
	];

	return (
		<div className="swh-drawer-meta-row">
			<SelectControl
				hideLabelFromVision
				label={ __( 'Status', 'simple-wp-helpdesk' ) }
				value={ ticket.status }
				options={ getStatusOptions() }
				onChange={ ( v ) => patchMeta( { status: v } ) }
				__nextHasNoMarginBottom
			/>
			<SelectControl
				hideLabelFromVision
				label={ __( 'Priority', 'simple-wp-helpdesk' ) }
				value={ ticket.priority }
				options={ getPriorityOptions() }
				onChange={ ( v ) => patchMeta( { priority: v } ) }
				__nextHasNoMarginBottom
			/>
			<SelectControl
				hideLabelFromVision
				label={ __( 'Assignee', 'simple-wp-helpdesk' ) }
				value={ String( ticket.assignee || 0 ) }
				options={ assigneeOptions }
				onChange={ ( v ) => patchMeta( { assignee: parseInt( v, 10 ) || 0 } ) }
				__nextHasNoMarginBottom
			/>
			<span className="swh-drawer-meta-pill">
				{ ( ticket.meta?.tags || [] ).join( ', ' ) ||
					__( 'no tags', 'simple-wp-helpdesk' ) }
			</span>
		</div>
	);
};
