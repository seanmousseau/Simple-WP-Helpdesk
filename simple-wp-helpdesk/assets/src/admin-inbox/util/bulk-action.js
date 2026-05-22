// util/bulk-action.js
import apiFetch from '@wordpress/api-fetch';

/**
 * Dispatch a bulk action across selected tickets. Server applies optimistic
 * concurrency per ticket via TicketVersion::check(); response separates
 * succeeded ids from conflicts.
 *
 * @param {string} action  One of: status, assign, priority, tag, close.
 * @param {object} params  Action params (e.g. { status: 'resolved' }).
 * @param {number[]} ticketIds  Selected ticket ids (max 200).
 * @param {object} versionMap  { [id]: version } — caller-supplied current versions.
 * @return {Promise<{ succeeded: number[], conflicts: Array<{id, version_now}> }>}
 */
export const dispatchBulk = async ( action, params, ticketIds, versionMap ) => {
	if ( ! ticketIds.length ) return { succeeded: [], conflicts: [] };
	if ( ticketIds.length > 200 ) {
		throw new Error( `Bulk capped at 200 tickets (got ${ ticketIds.length })` );
	}
	return apiFetch( {
		path: '/swh/v1/bulk-action',
		method: 'POST',
		data: { action, params, ticket_ids: ticketIds, version_map: versionMap },
	} );
};
