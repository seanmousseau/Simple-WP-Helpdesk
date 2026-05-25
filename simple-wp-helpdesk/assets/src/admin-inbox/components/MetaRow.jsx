// components/MetaRow.jsx
import { useDispatch } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';
import { SWH_DRAWER_STORE } from '../store/drawer';

const getStatusOptions = () => [
	{ value: 'open', label: __( 'Open', 'simple-wp-helpdesk' ) },
	{ value: 'in_progress', label: __( 'In Progress', 'simple-wp-helpdesk' ) },
	{ value: 'resolved', label: __( 'Resolved', 'simple-wp-helpdesk' ) },
	{ value: 'closed', label: __( 'Closed', 'simple-wp-helpdesk' ) },
];
const getPriorityOptions = () => [
	{ value: 'low', label: __( 'Low', 'simple-wp-helpdesk' ) },
	{ value: 'normal', label: __( 'Normal', 'simple-wp-helpdesk' ) },
	{ value: 'high', label: __( 'High', 'simple-wp-helpdesk' ) },
	{ value: 'urgent', label: __( 'Urgent', 'simple-wp-helpdesk' ) },
];

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
