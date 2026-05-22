// store/views.js
import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

const DEFAULT_STATE = {
	views: [], // [{ id, name, scope, filters, sort, columns }]
	loading: false,
	error: null,
};

const actions = {
	fetchViews:
		() =>
		async ( { dispatch } ) => {
			dispatch( { type: 'FETCH_START' } );
			try {
				const data = await apiFetch( { path: '/swh/v1/views' } );
				dispatch( { type: 'FETCH_SUCCESS', views: data.views || [] } );
			} catch ( err ) {
				dispatch( { type: 'FETCH_ERROR', error: err.message } );
			}
		},

	createView:
		( view ) =>
		async ( { dispatch } ) => {
			try {
				const data = await apiFetch( {
					path: '/swh/v1/views',
					method: 'POST',
					data: view,
				} );
				dispatch( { type: 'VIEW_CREATED', view: data.view } );
				return data.view;
			} catch ( err ) {
				dispatch( { type: 'FETCH_ERROR', error: err.message } );
				return null;
			}
		},

	updateView:
		( id, scope, patch ) =>
		async ( { dispatch } ) => {
			try {
				const data = await apiFetch( {
					path: `/swh/v1/views/${ id }`,
					method: 'PATCH',
					data: { scope, patch },
				} );
				dispatch( { type: 'VIEW_UPDATED', view: data.view } );
				return data.view;
			} catch ( err ) {
				dispatch( { type: 'FETCH_ERROR', error: err.message } );
				return null;
			}
		},

	deleteView:
		( id, scope ) =>
		async ( { dispatch } ) => {
			try {
				await apiFetch( {
					path: `/swh/v1/views/${ id }?scope=${ scope }`,
					method: 'DELETE',
				} );
				dispatch( { type: 'VIEW_DELETED', id } );
			} catch ( err ) {
				dispatch( { type: 'FETCH_ERROR', error: err.message } );
			}
		},
};

const reducer = ( state = DEFAULT_STATE, action ) => {
	switch ( action.type ) {
		case 'FETCH_START':
			return { ...state, loading: true, error: null };
		case 'FETCH_SUCCESS':
			return { ...state, loading: false, views: action.views };
		case 'FETCH_ERROR':
			return { ...state, loading: false, error: action.error };
		case 'VIEW_CREATED':
			return { ...state, views: [ ...state.views, action.view ] };
		case 'VIEW_UPDATED':
			return {
				...state,
				views: state.views.map( ( v ) =>
					v.id === action.view.id ? action.view : v
				),
			};
		case 'VIEW_DELETED':
			return {
				...state,
				views: state.views.filter( ( v ) => v.id !== action.id ),
			};
		default:
			return state;
	}
};

const selectors = {
	getViews: ( state ) => state.views,
	getLoading: ( state ) => state.loading,
	getError: ( state ) => state.error,
};

export const SWH_VIEWS_STORE = 'swh/views';

export const registerViewsStore = () => {
	register(
		createReduxStore( SWH_VIEWS_STORE, { reducer, actions, selectors } )
	);
};
