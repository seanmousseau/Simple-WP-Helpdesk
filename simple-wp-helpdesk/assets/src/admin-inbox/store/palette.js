// store/palette.js
import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

const DEFAULT_STATE = {
	isOpen: false,
	query: '',
	tickets: [], // from REST /palette/suggest
	loading: false,
	error: null,
	recents: [], // [{ type: 'ticket', id, label }, ...] — last 8
};

const RECENTS_LIMIT = 8;

const actions = {
	open: () => ( { type: 'OPEN' } ),
	close: () => ( { type: 'CLOSE' } ),
	setQuery: ( q ) => ( { type: 'SET_QUERY', q } ),
	addRecent: ( item ) => ( { type: 'ADD_RECENT', item } ),

	fetchSuggestions:
		() =>
		async ( { select, dispatch } ) => {
			const q = select.getQuery();
			dispatch( { type: 'FETCH_START' } );
			try {
				const data = await apiFetch( {
					path: `/swh/v1/palette/suggest?q=${ encodeURIComponent(
						q
					) }`,
				} );
				dispatch( { type: 'FETCH_SUCCESS', tickets: data.tickets } );
			} catch ( err ) {
				dispatch( { type: 'FETCH_ERROR', error: err.message } );
			}
		},
};

const reducer = ( state = DEFAULT_STATE, action ) => {
	switch ( action.type ) {
		case 'OPEN':
			return { ...state, isOpen: true, query: '' };
		case 'CLOSE':
			return {
				...state,
				isOpen: false,
				query: '',
				tickets: [],
				error: null,
			};
		case 'SET_QUERY':
			return { ...state, query: action.q };
		case 'FETCH_START':
			return { ...state, loading: true, error: null };
		case 'FETCH_SUCCESS':
			return { ...state, loading: false, tickets: action.tickets };
		case 'FETCH_ERROR':
			return { ...state, loading: false, error: action.error };
		case 'ADD_RECENT': {
			const filtered = state.recents.filter(
				( r ) =>
					! ( r.type === action.item.type && r.id === action.item.id )
			);
			return {
				...state,
				recents: [ action.item, ...filtered ].slice( 0, RECENTS_LIMIT ),
			};
		}
		default:
			return state;
	}
};

const selectors = {
	isOpen: ( state ) => state.isOpen,
	getQuery: ( state ) => state.query,
	getTickets: ( state ) => state.tickets,
	getLoading: ( state ) => state.loading,
	getError: ( state ) => state.error,
	getRecents: ( state ) => state.recents,
};

export const SWH_PALETTE_STORE = 'swh/palette';

export const registerPaletteStore = () => {
	register(
		createReduxStore( SWH_PALETTE_STORE, { reducer, actions, selectors } )
	);
};
