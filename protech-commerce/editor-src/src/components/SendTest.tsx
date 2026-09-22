import { useState } from 'preact/hooks';
import type { Template } from '../types';
import { api } from '../api';

interface Props {
	template: Template;
}

export function SendTest( { template }: Props ) {
	const [ to, setTo ] = useState( '' );
	const [ sending, setSending ] = useState( false );
	const [ result, setResult ] = useState< { ok: boolean; message: string } | null >( null );

	const send = () => {
		setSending( true );
		setResult( null );

		api
			.testSend( template, to )
			.then( ( r ) => setResult( { ok: r.ok, message: r.ok ? `Sent to ${ r.to }.` : r.error || 'Could not send.' } ) )
			.catch( ( e: Error ) => setResult( { ok: false, message: e.message } ) )
			.finally( () => setSending( false ) );
	};

	return (
		<details className="pw-panel">
			<summary>Send a preview</summary>
			<p className="pw-field-help">Sends this template as it stands to any address. Leave blank to send to yourself.</p>
			<div className="pw-field">
				<input type="email" className="pw-input" placeholder="you@example.com" value={ to } onInput={ ( e ) => setTo( ( e.target as HTMLInputElement ).value ) } />
			</div>
			<button type="button" className="pc-btn" onClick={ send } disabled={ sending }>
				{ sending ? 'Sending...' : 'Send preview' }
			</button>
			{ result ? <p className={ result.ok ? 'pw-success' : 'pw-error' }>{ result.message }</p> : null }
		</details>
	);
}
