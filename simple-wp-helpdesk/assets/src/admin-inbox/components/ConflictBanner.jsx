// components/ConflictBanner.jsx
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';
import { SWH_DRAWER_STORE } from '../store/drawer';

export const ConflictBanner = () => {
	const conflict = useSelect(
		( s ) => s( SWH_DRAWER_STORE ).getConflict(),
		[]
	);
	const { dismissConflict } = useDispatch( SWH_DRAWER_STORE );
	if ( ! conflict ) return null;
	return (
		<Notice
			status="warning"
			isDismissible
			onRemove={ dismissConflict }
			className="swh-drawer-conflict"
		>
			{ __(
				'This ticket was updated by someone else while you were viewing it. The drawer has been refreshed with the latest state. Your draft reply has been preserved.',
				'simple-wp-helpdesk'
			) }
		</Notice>
	);
};
