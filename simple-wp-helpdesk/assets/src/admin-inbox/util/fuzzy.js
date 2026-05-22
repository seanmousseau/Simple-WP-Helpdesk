// util/fuzzy.js
// Lightweight client-side fuzzy match for view names + settings labels.
// Server already does the heavy ticket-title matching; this is only for
// the local result groups (saved views, settings pages).
export const fuzzyMatch = ( query, candidates, getKey = ( c ) => c ) => {
	const q = ( query || '' ).toLowerCase().trim();
	if ( ! q ) return candidates;
	return candidates
		.map( ( c ) => {
			const k = getKey( c ).toLowerCase();
			let i = 0;
			for ( const ch of q ) {
				const found = k.indexOf( ch, i );
				if ( found < 0 ) return null;
				i = found + 1;
			}
			return c;
		} )
		.filter( Boolean );
};
