import { __ } from '@wordpress/i18n';

export const TopBar = ( { onOpenPalette } ) => (
	<div className="swh-inbox-topbar">
		<h1 className="swh-inbox-title">
			{ __( 'Tickets', 'simple-wp-helpdesk' ) }
		</h1>
		<button
			type="button"
			className="swh-inbox-palette-hint"
			onClick={ onOpenPalette }
		>
			<kbd>⌘K</kbd>
			<span>{ __( 'Search & commands', 'simple-wp-helpdesk' ) }</span>
		</button>
	</div>
);
