import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { SWH_INBOX_STORE } from '../store/inbox';
import { DEFAULT_VIEWS } from '../views/defaults';

export const LeftRail = () => {
	const activeView = useSelect( ( s ) => s( SWH_INBOX_STORE ).getView(), [] );
	const { setView } = useDispatch( SWH_INBOX_STORE );

	return (
		<aside
			className="swh-inbox-leftrail"
			aria-label={ __( 'Saved views', 'simple-wp-helpdesk' ) }
		>
			<h2 className="swh-inbox-leftrail-heading">
				{ __( 'Saved views', 'simple-wp-helpdesk' ) }
			</h2>
			<ul className="swh-inbox-leftrail-list">
				{ DEFAULT_VIEWS.map( ( v ) => (
					<li key={ v.key }>
						<button
							type="button"
							className={ `swh-inbox-view-link${
								activeView === v.key ? ' is-active' : ''
							}` }
							onClick={ () => setView( v.key ) }
						>
							{ v.label }
						</button>
					</li>
				) ) }
			</ul>
			<button
				type="button"
				className="swh-inbox-view-add"
				disabled
				aria-disabled="true"
			>
				{ __( '+ Add view (D)', 'simple-wp-helpdesk' ) }
			</button>
		</aside>
	);
};
