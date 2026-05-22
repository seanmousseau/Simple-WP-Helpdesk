import { useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { SWH_INBOX_STORE } from '../store/inbox';
import { SWH_VIEWS_STORE } from '../store/views';
import { DEFAULT_VIEWS } from '../views/defaults';
import { AddViewButton, ViewRowMenu } from './ViewManager';

export const LeftRail = () => {
	const activeView = useSelect( ( s ) => s( SWH_INBOX_STORE ).getView(), [] );
	const userViews = useSelect( ( s ) => s( SWH_VIEWS_STORE ).getViews(), [] );
	const { setView } = useDispatch( SWH_INBOX_STORE );
	const { fetchViews } = useDispatch( SWH_VIEWS_STORE );

	useEffect( () => {
		fetchViews();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const personalViews = userViews.filter( ( v ) => v.scope === 'personal' );
	const globalViews = userViews.filter( ( v ) => v.scope === 'global' );

	const renderView = ( v, isDefault = false ) => {
		const key = isDefault ? v.key : v.id;
		const label = v.label || v.name;
		return (
			<li key={ key } className="swh-inbox-leftrail-item">
				<button
					type="button"
					className={ `swh-inbox-view-link${
						activeView === key ? ' is-active' : ''
					}` }
					onClick={ () => setView( key ) }
				>
					{ label }
				</button>
				{ ! isDefault && <ViewRowMenu view={ v } /> }
			</li>
		);
	};

	return (
		<aside
			className="swh-inbox-leftrail"
			aria-label={ __( 'Saved views', 'simple-wp-helpdesk' ) }
		>
			<h2 className="swh-inbox-leftrail-heading">
				{ __( 'Saved views', 'simple-wp-helpdesk' ) }
			</h2>
			<ul className="swh-inbox-leftrail-list">
				{ DEFAULT_VIEWS.map( ( v ) => renderView( v, true ) ) }
				{ globalViews.map( ( v ) => renderView( v ) ) }
			</ul>
			{ personalViews.length > 0 && (
				<>
					<h2 className="swh-inbox-leftrail-heading">
						{ __( 'My views', 'simple-wp-helpdesk' ) }
					</h2>
					<ul className="swh-inbox-leftrail-list">
						{ personalViews.map( ( v ) => renderView( v ) ) }
					</ul>
				</>
			) }
			<AddViewButton />
		</aside>
	);
};
