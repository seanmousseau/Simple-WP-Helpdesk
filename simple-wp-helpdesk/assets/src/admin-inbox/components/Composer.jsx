// components/Composer.jsx
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Button, CheckboxControl, TextareaControl } from '@wordpress/components';
import { SWH_DRAWER_STORE } from '../store/drawer';

export const Composer = () => {
	const { body, internal, markResolved, saving } = useSelect(
		( s ) => ( {
			body:         s( SWH_DRAWER_STORE ).getDraftBody(),
			internal:     s( SWH_DRAWER_STORE ).getDraftInternal(),
			markResolved: s( SWH_DRAWER_STORE ).getDraftMarkResolved(),
			saving:       s( SWH_DRAWER_STORE ).getSaving(),
		} ),
		[]
	);
	const { setDraft, setDraftInternal, setDraftMarkResolved, sendReply } =
		useDispatch( SWH_DRAWER_STORE );

	const onKey = ( e ) => {
		if ( e.key === 'Enter' && ( e.metaKey || e.ctrlKey ) ) {
			e.preventDefault();
			if ( body && ! saving ) sendReply();
		}
	};

	return (
		<div className="swh-drawer-composer">
			<TextareaControl
				value={ body }
				placeholder={ __(
					'Type your reply… (cmd+Enter to send)',
					'simple-wp-helpdesk'
				) }
				onChange={ setDraft }
				onKeyDown={ onKey }
				__nextHasNoMarginBottom
			/>
			<div className="swh-drawer-composer-toolbar">
				<CheckboxControl
					label={ __( 'Internal note', 'simple-wp-helpdesk' ) }
					checked={ internal }
					onChange={ setDraftInternal }
					__nextHasNoMarginBottom
				/>
				<CheckboxControl
					label={ __( 'Mark resolved on send', 'simple-wp-helpdesk' ) }
					checked={ markResolved }
					onChange={ setDraftMarkResolved }
					__nextHasNoMarginBottom
				/>
				<div className="swh-drawer-composer-buttons">
					<Button
						variant="primary"
						disabled={ ! body || saving }
						onClick={ sendReply }
					>
						{ saving
							? __( 'Sending…', 'simple-wp-helpdesk' )
							: __( 'Send reply', 'simple-wp-helpdesk' ) }
					</Button>
				</div>
			</div>
		</div>
	);
};
