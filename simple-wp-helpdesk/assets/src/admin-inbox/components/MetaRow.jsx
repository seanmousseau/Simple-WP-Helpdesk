// components/MetaRow.jsx
import { useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';
import { SWH_DRAWER_STORE } from '../store/drawer';

const STATUS_OPTIONS = [
	{ value: 'open',        label: 'Open' },
	{ value: 'in_progress', label: 'In Progress' },
	{ value: 'resolved',    label: 'Resolved' },
	{ value: 'closed',      label: 'Closed' },
];
const PRIORITY_OPTIONS = [
	{ value: 'low',    label: 'Low' },
	{ value: 'normal', label: 'Normal' },
	{ value: 'high',   label: 'High' },
	{ value: 'urgent', label: 'Urgent' },
];

export const MetaRow = ( { ticket } ) => {
	const { patchMeta } = useDispatch( SWH_DRAWER_STORE );
	return (
		<div className="swh-drawer-meta-row">
			<SelectControl
				hideLabelFromVision
				label="Status"
				value={ ticket.status }
				options={ STATUS_OPTIONS }
				onChange={ ( v ) => patchMeta( { status: v } ) }
				__nextHasNoMarginBottom
			/>
			<SelectControl
				hideLabelFromVision
				label="Priority"
				value={ ticket.priority }
				options={ PRIORITY_OPTIONS }
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
