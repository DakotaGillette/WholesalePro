import { useEffect, useRef, useState } from 'preact/hooks';
import type { Template } from '../types';
import { api } from '../api';
import { Icon } from './icons';

/**
 * "Send preview" on the top bar (3.10.0, where MailPoet keeps it, rather
 * than a sidebar panel): a button that opens a small box to send the design
 * as it stands to any address, or to you when left blank.
 */
export function SendPreview( { template }: { template: Template } ) {
	const [ open, setOpen ] = useState( false );
	const [ to, setTo ] = useState( '' );
	const [ sending, setSending ] = useState( false );
	const [ result, setResult ] = useState< { ok: boolean; message: string } | null >( null );
	const wrap = useRef< HTMLDivElement | null >( null );
	const input = useRef< HTMLInputElement | null >( null );

	// Close on Escape or a click anywhere else.
	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}

		input.current?.focus();

		const onKey = ( e: KeyboardEvent ) => 'Escape' === e.key && setOpen( false );
		const onClick = ( e: MouseEvent ) => {
			if ( wrap.current && ! wrap.current.contains( e.target as Node ) ) {
				setOpen( false );
			}
		};

		document.addEventListener( 'keydown', onKey );
		document.addEventListener( 'mousedown', onClick );

		return () => {
			document.removeEventListener( 'keydown', onKey );
			document.removeEventListener( 'mousedown', onClick );
		};
	}, [ open ] );

	const send = ( e: Event ) => {
		e.preventDefault();
		setSending( true );
		setResult( null );

		api
			.testSend( template, to )
			.then( ( r ) => setResult( { ok: r.ok, message: r.ok ? `Sent to ${ r.to }.` : r.error || 'Could not send.' } ) )
			.catch( ( err: Error ) => setResult( { ok: false, message: err.message } ) )
			.finally( () => setSending( false ) );
	};

	return (
		<div className="pc-popover-wrap" ref={ wrap }>
			<button type="button" className="pc-btn" aria-expanded={ open } aria-haspopup="dialog" onClick={ () => setOpen( ! open ) }>
				<Icon name="mail" size={ 16 } />
				Send preview
			</button>

			{ open ? (
				<div className="pc-popover" role="dialog" aria-label="Send a preview">
					<form onSubmit={ send }>
						<label className="pc-label" htmlFor="pc-preview-to">
							Send this design to
						</label>
						<div className="pc-popover-row">
							<input
								id="pc-preview-to"
								ref={ input }
								type="email"
								className="pc-input"
								placeholder="Leave blank to send it to yourself"
								value={ to }
								onInput={ ( e ) => setTo( ( e.target as HTMLInputElement ).value ) }
							/>
							<button type="submit" className="pc-btn pc-btn--primary" disabled={ sending }>
								{ sending ? 'Sending...' : 'Send' }
							</button>
						</div>
						<p className="pc-help">Personal details fill in from your own account. Nothing goes to customers.</p>
						{ result ? (
							<p className={ `pc-popover-result ${ result.ok ? 'is-ok' : 'is-error' }` } role="status">
								{ result.message }
							</p>
						) : null }
					</form>
				</div>
			) : null }
		</div>
	);
}
