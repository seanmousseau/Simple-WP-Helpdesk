import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

const DEFAULT_STATE = {
	view: 'unassigned',
	sort: 'last_reply_desc',
	page: 1,
	perPage: 50,
	search: '',
	tickets: [],
	total: 0,
	loading: false,
	error: null,
	selectedIds: [],
	syntheticView: null, // { key, label, rangeStart, rangeEnd } | null — v4.0.0 #359
};

const actions = {
	setView: ( view ) => ( { type: 'SET_VIEW', view } ),
	setSort: ( sort ) => ( { type: 'SET_SORT', sort } ),
	setPage: ( page ) => ( { type: 'SET_PAGE', page } ),
	setSearch: ( search ) => ( { type: 'SET_SEARCH', search } ),
	toggleSelected: ( id ) => ( { type: 'TOGGLE_SELECTED', id } ),
	clearSelected: () => ( { type: 'CLEAR_SELECTED' } ),
	/**
	 * Set a synthetic (URL-hash-driven) view. v4.0.0 #359.
	 *
	 * @param {{ key: string, label: string, rangeStart: string|null, rangeEnd: string|null }} view Synthetic view descriptor.
	 */
	setSyntheticView: ( view ) => ( { type: 'SET_SYNTHETIC_VIEW', view } ),
	clearSyntheticView: () => ( { type: 'CLEAR_SYNTHETIC_VIEW' } ),
	fetchTickets:
		() =>
		async ( { select, dispatch } ) => {
			const synth = select.getSyntheticView();
			const { sort, page, perPage, search } = select.getInboxParams();
			const view = synth ? synth.key : select.getView();
			const params = new URLSearchParams( {
				view,
				sort,
				page: String( page ),
				per_page: String( perPage ),
				search,
			} );
			if ( synth && synth.rangeStart ) {
				params.set( 'range_start', synth.rangeStart );
			}
			if ( synth && synth.rangeEnd ) {
				params.set( 'range_end', synth.rangeEnd );
			}
			dispatch( { type: 'FETCH_START' } );
			try {
				const data = await apiFetch( {
					path: `/swh/v1/tickets?${ params.toString() }`,
				} );
				dispatch( {
					type: 'FETCH_SUCCESS',
					tickets: data.tickets,
					total: data.total,
				} );
			} catch ( err ) {
				dispatch( {
					type: 'FETCH_ERROR',
					error: err.message || 'Unknown error',
				} );
			}
		},
};

const reducer = ( state = DEFAULT_STATE, action ) => {
	switch ( action.type ) {
		case 'SET_VIEW':
			return { ...state, view: action.view, page: 1, selectedIds: [] };
		case 'SET_SORT':
			return { ...state, sort: action.sort, page: 1 };
		case 'SET_PAGE':
			return { ...state, page: action.page };
		case 'SET_SEARCH':
			return { ...state, search: action.search, page: 1 };
		case 'FETCH_START':
			return { ...state, loading: true, error: null };
		case 'FETCH_SUCCESS':
			return {
				...state,
				loading: false,
				tickets: action.tickets,
				total: action.total,
			};
		case 'FETCH_ERROR':
			return { ...state, loading: false, error: action.error };
		case 'TOGGLE_SELECTED': {
			const isSelected = state.selectedIds.includes( action.id );
			const next = isSelected
				? state.selectedIds.filter( ( i ) => i !== action.id )
				: [ ...state.selectedIds, action.id ];
			if ( next.length > 200 ) {
				return state; // Hard cap — drop the toggle.
			}
			return { ...state, selectedIds: next };
		}
		case 'CLEAR_SELECTED':
			return { ...state, selectedIds: [] };
		case 'SET_SYNTHETIC_VIEW':
			return { ...state, syntheticView: action.view, selectedIds: [] };
		case 'CLEAR_SYNTHETIC_VIEW':
			return { ...state, syntheticView: null };
		default:
			return state;
	}
};

const selectors = {
	getTickets: ( state ) => state.tickets,
	getTotal: ( state ) => state.total,
	getLoading: ( state ) => state.loading,
	getError: ( state ) => state.error,
	getView: ( state ) => state.view,
	getSort: ( state ) => state.sort,
	getPage: ( state ) => state.page,
	getSearch: ( state ) => state.search,
	getSelectedIds: ( state ) => state.selectedIds,
	getInboxParams: ( state ) => ( {
		view: state.view,
		sort: state.sort,
		page: state.page,
		perPage: state.perPage,
		search: state.search,
	} ),
	/**
	 * Returns the active synthetic view, or null. v4.0.0 #359.
	 *
	 * @param {Object} state Redux state.
	 * @return {{ key: string, label: string, rangeStart: string|null, rangeEnd: string|null }|null} Active synthetic view.
	 */
	getSyntheticView: ( state ) => state.syntheticView,
};

export const SWH_INBOX_STORE = 'swh/inbox';

export const registerInboxStore = () => {
	register(
		createReduxStore( SWH_INBOX_STORE, { reducer, actions, selectors } )
	);
};
