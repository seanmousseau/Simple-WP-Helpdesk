// simple-wp-helpdesk/assets/src/views-settings/ViewsSettingsPage.jsx
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Button, TextControl } from '@wordpress/components';

export const ViewsSettingsPage = () => {
	const [ views, setViews ] = useState( [] );
	const [ newName, setNewName ] = useState( '' );
	const [ loading, setLoading ] = useState( true );

	const refresh = async () => {
		setLoading( true );
		try {
			const data = await apiFetch( { path: '/swh/v1/views' } );
			setViews( ( data.views || [] ).filter( ( v ) => v.scope === 'global' ) );
		} catch ( e ) {
			// eslint-disable-next-line no-alert
			window.alert( e.message );
		} finally {
			setLoading( false );
		}
	};

	useEffect( () => {
		refresh();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const onCreate = async () => {
		if ( ! newName.trim() ) return;
		await apiFetch( {
			path: '/swh/v1/views',
			method: 'POST',
			data: {
				name: newName.trim(),
				scope: 'global',
				filters: {},
				sort: 'last_reply_desc',
				columns: [],
			},
		} );
		setNewName( '' );
		refresh();
	};

	const onDelete = async ( id ) => {
		// eslint-disable-next-line no-alert
		if (
			! window.confirm(
				__(
					'Delete this global view for all users?',
					'simple-wp-helpdesk'
				)
			)
		)
			return;
		await apiFetch( {
			path: `/swh/v1/views/${ id }?scope=global`,
			method: 'DELETE',
		} );
		refresh();
	};

	return (
		<div className="swh-views-settings">
			<h1>{ __( 'Saved Views', 'simple-wp-helpdesk' ) }</h1>
			<p>
				{ __(
					'Global views are visible to every technician. Personal views are managed inside the inbox left rail.',
					'simple-wp-helpdesk'
				) }
			</p>

			<div className="swh-views-settings-create">
				<TextControl
					label={ __( 'New global view name', 'simple-wp-helpdesk' ) }
					value={ newName }
					onChange={ setNewName }
					__nextHasNoMarginBottom
				/>
				<Button
					variant="primary"
					onClick={ onCreate }
					disabled={ ! newName.trim() }
				>
					{ __( 'Create global view', 'simple-wp-helpdesk' ) }
				</Button>
			</div>

			{ loading && <p>{ __( 'Loading…', 'simple-wp-helpdesk' ) }</p> }

			<table className="widefat striped">
				<thead>
					<tr>
						<th>{ __( 'Name', 'simple-wp-helpdesk' ) }</th>
						<th>{ __( 'Sort', 'simple-wp-helpdesk' ) }</th>
						<th>{ __( 'Actions', 'simple-wp-helpdesk' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ views.map( ( v ) => (
						<tr key={ v.id }>
							<td>{ v.name }</td>
							<td>{ v.sort || '—' }</td>
							<td>
								<Button
									variant="link"
									isDestructive
									onClick={ () => onDelete( v.id ) }
								>
									{ __( 'Delete', 'simple-wp-helpdesk' ) }
								</Button>
							</td>
						</tr>
					) ) }
					{ ! views.length && ! loading && (
						<tr>
							<td colSpan="3">
								{ __(
									'No global views yet.',
									'simple-wp-helpdesk'
								) }
							</td>
						</tr>
					) }
				</tbody>
			</table>
		</div>
	);
};
