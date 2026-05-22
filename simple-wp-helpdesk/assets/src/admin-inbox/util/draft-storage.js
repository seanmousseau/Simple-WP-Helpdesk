// util/draft-storage.js
const KEY_PREFIX = 'swh_draft_';
const TTL_DAYS = 7;

const userKey = ( ticketId ) =>
	`${ KEY_PREFIX }${ window.swhInbox?.currentUserId || 0 }_${ ticketId }`;

export const loadDraft = ( ticketId ) => {
	try {
		const raw = localStorage.getItem( userKey( ticketId ) );
		if ( ! raw ) {
			return null;
		}
		const parsed = JSON.parse( raw );
		if ( ! parsed || ! parsed.savedAt ) {
			return null;
		}
		if ( ( Date.now() - parsed.savedAt ) / 86400000 > TTL_DAYS ) {
			localStorage.removeItem( userKey( ticketId ) );
			return null;
		}
		return parsed.body || null;
	} catch {
		return null;
	}
};

export const saveDraft = ( ticketId, body ) => {
	try {
		if ( ! body ) {
			localStorage.removeItem( userKey( ticketId ) );
			return;
		}
		localStorage.setItem(
			userKey( ticketId ),
			JSON.stringify( { body, savedAt: Date.now() } )
		);
	} catch {}
};

export const clearDraft = ( ticketId ) => {
	try {
		localStorage.removeItem( userKey( ticketId ) );
	} catch {}
};
