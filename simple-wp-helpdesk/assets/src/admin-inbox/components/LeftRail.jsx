import { useEffect, useRef } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { SWH_INBOX_STORE } from '../store/inbox';
import { SWH_VIEWS_STORE } from '../store/views';
import { DEFAULT_VIEWS } from '../views/defaults';
// v4.0.0: AddViewButton hidden — popover lacks filter pickers, only captures
// the current view state which is useless. Tracked for v4.1.
import { ViewRowMenu } from './ViewManager';

export const LeftRail = () => {
	const activeView = useSelect( ( s ) => s( SWH_INBOX_STORE ).getView(), [] );
	const syntheticView = useSelect(
		( s ) => s( SWH_INBOX_STORE ).getSyntheticView(),
		[]
	);
	const userViews = useSelect( ( s ) => s( SWH_VIEWS_STORE ).getViews(), [] );
	const defaultView = useSelect(
		( s ) => s( SWH_VIEWS_STORE ).getDefaultView(),
		[]
	);
	const defaultLoaded = useSelect(
		( s ) => s( SWH_VIEWS_STORE ).getDefaultLoaded(),
		[]
	);
	const { setView, clearSyntheticView } = useDispatch( SWH_INBOX_STORE );
	const { fetchViews, setDefaultView } = useDispatch( SWH_VIEWS_STORE );

	useEffect( () => {
		fetchViews();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Apply the user's saved default view exactly once on initial load (#437).
	// We don't reapply when defaultView later changes because the user may
	// have intentionally clicked away — that's a navigation, not a stale state.
	const appliedDefaultRef = useRef( false );
	useEffect( () => {
		if ( appliedDefaultRef.current ) {
			return;
		}
		if ( ! defaultLoaded ) {
			return;
		}
		appliedDefaultRef.current = true;
		if ( defaultView && defaultView !== activeView ) {
			setView( defaultView );
		}
	}, [ defaultLoaded, defaultView, activeView, setView ] );

	const personalViews = userViews.filter( ( v ) => v.scope === 'personal' );
	const globalViews = userViews.filter( ( v ) => v.scope === 'global' );

	const renderView = ( v, isDefault = false ) => {
		const key = isDefault ? v.key : v.id;
		const label = v.label || v.name;
		const isActive = activeView === key;
		const isDefault_ = defaultView === key;
		return (
			<li key={ key } className="swh-inbox-leftrail-item">
				<button
					type="button"
					className={ `swh-inbox-view-link${
						isActive ? ' is-active' : ''
					}` }
					aria-current={ isActive ? 'page' : undefined }
					onClick={ () => setView( key ) }
				>
					{ label }
				</button>
				<button
					type="button"
					className={ `swh-inbox-view-default-toggle${
						isDefault_ ? ' is-default' : ''
					}` }
					onClick={ ( e ) => {
						e.stopPropagation();
						setDefaultView( isDefault_ ? null : key );
					} }
					aria-pressed={ isDefault_ }
					aria-label={
						isDefault_
							? __( 'Clear default view', 'simple-wp-helpdesk' )
							: __( 'Set as default view', 'simple-wp-helpdesk' )
					}
					title={
						isDefault_
							? __( 'Clear default view', 'simple-wp-helpdesk' )
							: __( 'Set as default view', 'simple-wp-helpdesk' )
					}
				>
					{ isDefault_ ? '★' : '☆' }
				</button>
				{ ! isDefault && <ViewRowMenu view={ v } /> }
			</li>
		);
	};

	const handleClearSynthetic = () => {
		clearSyntheticView();
		// Remove hash so a page refresh doesn't re-apply the filter.
		window.history.replaceState(
			null,
			'',
			window.location.pathname + window.location.search
		);
	};

	return (
		<aside
			className="swh-inbox-leftrail"
			aria-label={ __( 'Saved views', 'simple-wp-helpdesk' ) }
		>
			{ syntheticView && (
				<div className="swh-inbox-synthetic-view">
					<span className="swh-inbox-synthetic-label">
						{ syntheticView.label }
					</span>
					<button
						type="button"
						className="swh-inbox-synthetic-clear"
						onClick={ handleClearSynthetic }
						aria-label={ __(
							'Clear filter',
							'simple-wp-helpdesk'
						) }
					>
						{ '×' }
					</button>
				</div>
			) }
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
			{ /* AddViewButton hidden v4.0.0 — see import comment. */ }
		</aside>
	);
};
