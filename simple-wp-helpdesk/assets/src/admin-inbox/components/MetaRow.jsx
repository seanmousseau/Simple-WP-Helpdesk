// components/MetaRow.jsx
import { useDispatch } from '@wordpress/data';
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

export const MetaRow = ( { ticket } ) => {
	const { patchMeta } = useDispatch( SWH_DRAWER_STORE );
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
			<span className="swh-drawer-meta-pill">
				{ __( 'Assignee:', 'simple-wp-helpdesk' ) }{ ' ' }
				{ ticket.assignee > 0
					? `#${ ticket.assignee }`
					: __( 'unassigned', 'simple-wp-helpdesk' ) }
			</span>
			<span className="swh-drawer-meta-pill">
				{ ( ticket.meta?.tags || [] ).join( ', ' ) ||
					__( 'no tags', 'simple-wp-helpdesk' ) }
			</span>
		</div>
	);
};
