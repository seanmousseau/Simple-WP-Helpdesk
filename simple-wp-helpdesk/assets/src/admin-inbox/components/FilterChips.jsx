import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { SWH_INBOX_STORE } from '../store/inbox';
import { DEFAULT_VIEWS } from '../views/defaults';

export const FilterChips = () => {
	const view = useSelect( ( s ) => s( SWH_INBOX_STORE ).getView(), [] );
	const def = DEFAULT_VIEWS.find( ( v ) => v.key === view );
	if ( ! def ) {
		return null;
	}

	const chips = [];
	if ( def.filters?.status ) {
		chips.push(
			`Status: ${ [].concat( def.filters.status ).join( ', ' ) }`
		);
	}
	if ( def.filters?.priority ) {
		chips.push(
			`Priority: ${ [].concat( def.filters.priority ).join( ', ' ) }`
		);
	}
	if ( def.filters?.assignee ) {
		chips.push( `Assignee: ${ def.filters.assignee }` );
	}

	return (
		<div className="swh-inbox-chips">
			{ chips.map( ( c ) => (
				<span key={ c } className="swh-inbox-chip">
					{ c }
				</span>
			) ) }
			<span className="swh-inbox-chip-sort">
				{ __( 'Sort:', 'simple-wp-helpdesk' ) }{ ' ' }
				{ def.sort.replace( /_/g, ' ' ) }
			</span>
		</div>
	);
};
