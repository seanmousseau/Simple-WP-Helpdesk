// store/drawer.js
import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { saveDraft, clearDraft, loadDraft } from '../util/draft-storage';

const DEFAULT_STATE = {
	ticketId: null,
	ticket: null,
	loading: false,
	error: null,
	draftBody: '',
	draftInternal: false,
	draftMarkResolved: false,
	saving: false,
	conflict: null,
};

const actions = {
	open: ( ticketId ) => ( { type: 'OPEN', ticketId } ),
	close: () => ( { type: 'CLOSE' } ),
	setDraft: ( body ) => ( { type: 'SET_DRAFT', body } ),
	setDraftInternal: ( v ) => ( { type: 'SET_DRAFT_INTERNAL', value: !! v } ),
	setDraftMarkResolved: ( v ) => ( {
		type: 'SET_DRAFT_MARK_RESOLVED',
		value: !! v,
	} ),
	dismissConflict: () => ( { type: 'DISMISS_CONFLICT' } ),

	fetchTicket:
		() =>
		async ( { select, dispatch } ) => {
			const id = select.getTicketId();
			if ( ! id ) {
				return;
			}
			dispatch( { type: 'FETCH_START' } );
			try {
				const data = await apiFetch( {
					path: `/swh/v1/tickets/${ id }`,
				} );
				dispatch( { type: 'FETCH_SUCCESS', ticket: data } );
				const draft = loadDraft( id );
				if ( draft ) {
					dispatch( { type: 'SET_DRAFT', body: draft } );
				}
			} catch ( err ) {
				dispatch( {
					type: 'FETCH_ERROR',
					error: err.message || 'Failed to load ticket',
				} );
			}
		},

	patchMeta:
		( patch ) =>
		async ( { select, dispatch } ) => {
			const ticket = select.getTicket();
			if ( ! ticket ) {
				return;
			}
			dispatch( { type: 'SAVE_START' } );
			try {
				const data = await apiFetch( {
					path: `/swh/v1/tickets/${ ticket.id }`,
					method: 'PATCH',
					data: { expected_version: ticket.version, patch },
				} );
				dispatch( { type: 'SAVE_SUCCESS', ticket: data } );
			} catch ( err ) {
				if (
					err.code === 'version_conflict' ||
					err.data?.status === 409
				) {
					dispatch( {
						type: 'CONFLICT',
						current: err.data?.current || err.current,
					} );
				} else {
					dispatch( { type: 'FETCH_ERROR', error: err.message } );
				}
			}
		},

	sendReply:
		() =>
		async ( { select, dispatch } ) => {
			const ticket = select.getTicket();
			const body = select.getDraftBody();
			if ( ! ticket || ! body ) {
				return;
			}
			const internal = select.getDraftInternal();
			const resolved = select.getDraftMarkResolved();
			dispatch( { type: 'SAVE_START' } );
			try {
				const data = await apiFetch( {
					path: `/swh/v1/tickets/${ ticket.id }/reply`,
					method: 'POST',
					data: {
						expected_version: ticket.version,
						body,
						internal,
						mark_resolved: resolved,
					},
				} );
				dispatch( { type: 'SAVE_SUCCESS', ticket: data } );
				dispatch( { type: 'CLEAR_DRAFT' } );
				clearDraft( ticket.id );
			} catch ( err ) {
				if (
					err.code === 'version_conflict' ||
					err.data?.status === 409
				) {
					dispatch( {
						type: 'CONFLICT',
						current: err.data?.current || err.current,
					} );
				} else {
					dispatch( { type: 'FETCH_ERROR', error: err.message } );
				}
			}
		},
};

const reducer = ( state = DEFAULT_STATE, action ) => {
	switch ( action.type ) {
		case 'OPEN':
			return {
				...DEFAULT_STATE,
				ticketId: action.ticketId,
				loading: true,
			};
		case 'CLOSE':
			return DEFAULT_STATE;
		case 'FETCH_START':
			return { ...state, loading: true, error: null };
		case 'FETCH_SUCCESS':
			return { ...state, loading: false, ticket: action.ticket };
		case 'FETCH_ERROR':
			return { ...state, loading: false, error: action.error };
		case 'SAVE_START':
			return { ...state, saving: true, conflict: null };
		case 'SAVE_SUCCESS':
			return { ...state, saving: false, ticket: action.ticket };
		case 'CONFLICT':
			return {
				...state,
				saving: false,
				conflict: action.current,
				ticket: action.current,
			};
		case 'DISMISS_CONFLICT':
			return { ...state, conflict: null };
		case 'SET_DRAFT': {
			if ( state.ticketId ) {
				saveDraft( state.ticketId, action.body );
			}
			return { ...state, draftBody: action.body };
		}
		case 'CLEAR_DRAFT':
			return {
				...state,
				draftBody: '',
				draftInternal: false,
				draftMarkResolved: false,
			};
		case 'SET_DRAFT_INTERNAL':
			return { ...state, draftInternal: action.value };
		case 'SET_DRAFT_MARK_RESOLVED':
			return { ...state, draftMarkResolved: action.value };
		default:
			return state;
	}
};

const selectors = {
	getTicketId: ( state ) => state.ticketId,
	getTicket: ( state ) => state.ticket,
	getLoading: ( state ) => state.loading,
	getError: ( state ) => state.error,
	getDraftBody: ( state ) => state.draftBody,
	getDraftInternal: ( state ) => state.draftInternal,
	getDraftMarkResolved: ( state ) => state.draftMarkResolved,
	getSaving: ( state ) => state.saving,
	getConflict: ( state ) => state.conflict,
	isOpen: ( state ) => state.ticketId !== null,
};

export const SWH_DRAWER_STORE = 'swh/drawer';

export const registerDrawerStore = () => {
	register(
		createReduxStore( SWH_DRAWER_STORE, { reducer, actions, selectors } )
	);
};
