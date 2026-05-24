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
	draftFiles: [], // File[] — not persisted to localStorage
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
	setDraftFiles: ( files ) => ( { type: 'SET_DRAFT_FILES', files } ),
	dismissConflict: () => ( { type: 'DISMISS_CONFLICT' } ),

	fetchTicket:
		() =>
		async ( { select, dispatch } ) => {
			const id = select.getTicketId();
			if ( ! id ) {
				return;
			}
			// Capture the ticket id at fetch start so we can discard stale responses
			// if the user has switched to a different ticket before this resolves.
			const fetchedForId = id;
			dispatch( { type: 'FETCH_START' } );
			try {
				const data = await apiFetch( {
					path: `/swh/v1/tickets/${ id }`,
				} );
				// Discard if the drawer has moved to a different ticket.
				if ( select.getTicketId() !== fetchedForId ) {
					return;
				}
				dispatch( { type: 'FETCH_SUCCESS', ticket: data } );
				const draft = loadDraft( id );
				if ( draft ) {
					dispatch( { type: 'SET_DRAFT', body: draft } );
				}
			} catch ( err ) {
				if ( select.getTicketId() !== fetchedForId ) {
					return;
				}
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
			const patchedForId = ticket.id;
			dispatch( { type: 'SAVE_START' } );
			try {
				const data = await apiFetch( {
					path: `/swh/v1/tickets/${ ticket.id }`,
					method: 'PATCH',
					data: { expected_version: ticket.version, patch },
				} );
				if ( select.getTicketId() !== patchedForId ) {
					return;
				}
				dispatch( { type: 'SAVE_SUCCESS', ticket: data } );
			} catch ( err ) {
				if ( select.getTicketId() !== patchedForId ) {
					return;
				}
				if (
					err.code === 'version_conflict' ||
					err.data?.status === 409
				) {
					dispatch( {
						type: 'CONFLICT',
						current: err.data?.current || err.current,
					} );
				} else {
					// Use SAVE_ERROR to clear `saving: true` reliably; FETCH_ERROR
					// does not clear `saving` state.
					dispatch( { type: 'SAVE_ERROR', error: err.message } );
				}
			}
		},

	sendReply:
		() =>
		async ( { select, dispatch } ) => {
			const ticket = select.getTicket();
			const body = select.getDraftBody();
			const files = select.getDraftFiles();
			if ( ! ticket || ( ! body && files.length === 0 ) ) {
				return;
			}
			const repliedForId = ticket.id;
			const internal = select.getDraftInternal();
			const resolved = select.getDraftMarkResolved();
			dispatch( { type: 'SAVE_START' } );
			try {
				let data;
				if ( files.length > 0 ) {
					// Multipart send for attachments — apiFetch defaults to JSON,
					// so we hand-roll the FormData POST and use its prepared headers
					// for nonce auth via the rest-nonce middleware-equivalent.
					const fd = new FormData();
					fd.append( 'expected_version', String( ticket.version ) );
					fd.append( 'body', body );
					fd.append( 'internal', internal ? '1' : '0' );
					fd.append( 'mark_resolved', resolved ? '1' : '0' );
					files.forEach( ( f ) => fd.append( 'attachments[]', f ) );
					data = await apiFetch( {
						path: `/swh/v1/tickets/${ ticket.id }/reply`,
						method: 'POST',
						body: fd,
					} );
				} else {
					data = await apiFetch( {
						path: `/swh/v1/tickets/${ ticket.id }/reply`,
						method: 'POST',
						data: {
							expected_version: ticket.version,
							body,
							internal,
							mark_resolved: resolved,
						},
					} );
				}
				if ( select.getTicketId() !== repliedForId ) {
					return;
				}
				dispatch( { type: 'SAVE_SUCCESS', ticket: data } );
				dispatch( { type: 'CLEAR_DRAFT' } );
				clearDraft( ticket.id );
			} catch ( err ) {
				if ( select.getTicketId() !== repliedForId ) {
					return;
				}
				if (
					err.code === 'version_conflict' ||
					err.data?.status === 409
				) {
					dispatch( {
						type: 'CONFLICT',
						current: err.data?.current || err.current,
					} );
				} else {
					// Use SAVE_ERROR to clear `saving: true` reliably.
					dispatch( { type: 'SAVE_ERROR', error: err.message } );
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
		// SAVE_ERROR clears `saving` so the Composer isn't stuck in a saving state
		// after a failed patchMeta or sendReply call. FETCH_ERROR intentionally does
		// NOT clear saving (fetch and save are orthogonal operations).
		case 'SAVE_ERROR':
			return { ...state, saving: false, error: action.error };
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
				draftFiles: [],
			};
		case 'SET_DRAFT_INTERNAL':
			return { ...state, draftInternal: action.value };
		case 'SET_DRAFT_MARK_RESOLVED':
			return { ...state, draftMarkResolved: action.value };
		case 'SET_DRAFT_FILES':
			return { ...state, draftFiles: Array.isArray( action.files ) ? action.files : [] };
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
	getDraftFiles: ( state ) => state.draftFiles,
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
