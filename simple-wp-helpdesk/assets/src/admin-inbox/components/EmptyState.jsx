import { __ } from '@wordpress/i18n';

export const EmptyState = () => (
	<div className="swh-inbox-empty">
		<p>{ __( 'No tickets match this view.', 'simple-wp-helpdesk' ) }</p>
	</div>
);
