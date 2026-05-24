// components/Thread.jsx
import { __ } from '@wordpress/i18n';

const fmtAgo = ( gmt ) => {
	if ( ! gmt ) {
		return '';
	}
	const diff = Math.max(
		0,
		Math.floor( ( Date.now() - new Date( gmt + 'Z' ).getTime() ) / 1000 )
	);
	if ( diff < 60 ) {
		return `${ diff }s ago`;
	}
	if ( diff < 3600 ) {
		return `${ Math.floor( diff / 60 ) }m ago`;
	}
	if ( diff < 86400 ) {
		return `${ Math.floor( diff / 3600 ) }h ago`;
	}
	return `${ Math.floor( diff / 86400 ) }d ago`;
};

const messageVariant = ( m ) => {
	if ( m.is_internal ) {
		return 'internal';
	}
	if ( m.is_staff ) {
		return 'staff';
	}
	return 'customer';
};

// Strip HTML tags client-side defense-in-depth (server already cleaned via
// wp_kses_post). Splits paragraphs on double-newlines, line breaks on single.
const renderBody = ( html ) => {
	const text = ( html || '' ).replace( /<[^>]+>/g, '' );
	return text.split( /\n{2,}/ ).map( ( para, i ) => (
		<p key={ i }>
			{ para.split( '\n' ).map( ( line, j, arr ) => (
				<span key={ j }>
					{ line }
					{ j < arr.length - 1 && <br /> }
				</span>
			) ) }
		</p>
	) );
};

export const Thread = ( { ticket } ) => {
	const messages = ticket.thread || [];
	const original = {
		id: 'original',
		author_name: ticket.email,
		is_staff: false,
		is_internal: false,
		date_gmt: ticket.created,
		content: ticket.body,
		attachments: ticket.attachments || [],
	};
	const all = [ original, ...messages ];

	return (
		<div className="swh-drawer-thread">
			{ all.map( ( m ) => (
				<div
					key={ m.id }
					className={ `swh-message swh-message-${ messageVariant(
						m
					) }` }
				>
					<div className="swh-message-meta">
						{ m.is_internal && (
							<span
								aria-label={ __(
									'Internal note',
									'simple-wp-helpdesk'
								) }
							>
								🔒
							</span>
						) }
						<strong>{ m.author_name }</strong>
						<span> · { fmtAgo( m.date_gmt ) }</span>
						{ m.is_staff && ! m.is_internal && (
							<span className="swh-message-tag">
								{ __( 'staff reply', 'simple-wp-helpdesk' ) }
							</span>
						) }
						{ m.is_internal && (
							<span className="swh-message-tag swh-message-tag-internal">
								{ __( 'internal', 'simple-wp-helpdesk' ) }
							</span>
						) }
					</div>
					<div className="swh-message-body">
						{ renderBody( m.content ) }
					</div>
					{ m.attachments && m.attachments.length > 0 && (
						<ul className="swh-message-attachments">
							{ m.attachments.map( ( a, i ) => (
								<li key={ i }>
									<a
										href={ a.url }
										target="_blank"
										rel="noopener noreferrer"
									>
										📎 { a.name }
									</a>
								</li>
							) ) }
						</ul>
					) }
				</div>
			) ) }
		</div>
	);
};
