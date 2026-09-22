import { useEffect, useRef, useState } from 'preact/hooks';
import type { AudienceInput, EditorBootstrap, EmailPayload, Estimate } from '../../types';
import { api } from '../../api';
import { AUDIENCE_TYPES, SCOPES, audienceProblems, tidyAudience } from '../../model/audience';
import { Icon } from '../icons';
import { Notice, Section, Toggle } from '../ui';
import { ConfirmModal } from './ConfirmModal';
import { ProductSearch } from './ProductSearch';

interface Props {
	boot: EditorBootstrap;
	payload: EmailPayload;
	onChange: ( payload: EmailPayload ) => void;
	onBack: () => void;
}

/**
 * Step 4: who gets it, and send. None of this shows while designing, as in
 * MailPoet. Changes save to the draft as they are made; the estimate is
 * the same consent check a real send applies, counted without sending.
 */
export function SendStep( { boot, payload, onChange, onBack }: Props ) {
	const email = payload.email;
	const design = payload.design;
	const [ name, setName ] = useState( email.name );
	const [ audience, setAudience ] = useState< AudienceInput >( () => tidyAudience( email.audience ) );
	const [ service, setService ] = useState( email.service_message );
	const [ estimate, setEstimate ] = useState< Estimate | null >( null );
	const [ counting, setCounting ] = useState( false );
	const [ errors, setErrors ] = useState< string[] >( [] );
	const [ confirming, setConfirming ] = useState( false );
	const [ sending, setSending ] = useState( false );
	const [ leaving, setLeaving ] = useState( false );
	const saveTimer = useRef< number | undefined >();
	const countTimer = useRef< number | undefined >();

	const tidy = tidyAudience( audience );
	const problems = audienceProblems( tidy );
	const tiers = boot.tiers ?? {};

	const persist = (): Promise< EmailPayload > =>
		api.emails.update( email.id, { name, audience: tidy, service_message: service } ).then( ( result ) => {
			onChange( { ...result, design: result.design ?? design } );
			return result;
		} );

	// Keep the draft up to date a moment after each change, and recount who it reaches.
	const key = JSON.stringify( { name, tidy, service } );
	useEffect( () => {
		window.clearTimeout( saveTimer.current );
		saveTimer.current = window.setTimeout( () => {
			persist().catch( ( e: Error ) => setErrors( [ e.message ] ) );
		}, 800 );

		window.clearTimeout( countTimer.current );

		if ( problems.length > 0 ) {
			setEstimate( null );
			return () => window.clearTimeout( saveTimer.current );
		}

		setCounting( true );
		countTimer.current = window.setTimeout( () => {
			api.emails
				.estimate( tidy, service )
				.then( setEstimate )
				.catch( () => setEstimate( null ) )
				.finally( () => setCounting( false ) );
		}, 500 );

		return () => {
			window.clearTimeout( saveTimer.current );
			window.clearTimeout( countTimer.current );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ key ] );

	const set = ( patch: Partial< AudienceInput > ) => setAudience( ( a ) => ( { ...a, ...patch } ) );

	const saveAndClose = () => {
		setLeaving( true );
		window.clearTimeout( saveTimer.current );
		persist()
			.then( () => {
				window.location.href = boot.urls.drafts ?? boot.urls.emails ?? '';
			} )
			.catch( ( e: Error ) => {
				setErrors( [ e.message ] );
				setLeaving( false );
			} );
	};

	const openConfirm = () => {
		const local = [ ...problems ];

		if ( ! design?.subject?.trim() ) {
			local.push( 'Give the email a subject. It is at the top of the Design step.' );
		}

		if ( estimate && 0 === estimate.sent_to ) {
			local.push( 'Nobody in this audience can get the email right now. Choose another audience.' );
		}

		setErrors( local );

		if ( 0 === local.length ) {
			setConfirming( true );
		}
	};

	const send = () => {
		setSending( true );
		window.clearTimeout( saveTimer.current );

		persist()
			.then( () => api.emails.send( email.id ) )
			.then( ( result ) => {
				if ( result.ok && result.log_url ) {
					window.location.href = result.log_url;
					return;
				}

				setErrors( result.errors ?? [ 'The email could not be sent.' ] );
				setConfirming( false );
				setSending( false );
			} )
			.catch( ( e: Error ) => {
				setErrors( [ e.message ] );
				setConfirming( false );
				setSending( false );
			} );
	};

	return (
		<div className="pc-page pc-send">
			<div className="pc-send-head">
				<div>
					<h2 className="pc-page-title">Send</h2>
					<p className="pc-help">
						Subject: <strong>{ design?.subject || 'No subject yet' }</strong>{ ' ' }
						<button type="button" className="pc-link" onClick={ onBack }>
							Edit the design
						</button>
					</p>
				</div>
			</div>

			{ errors.length > 0 ? (
				<Notice tone="error">
					<ul className="pc-list">
						{ errors.map( ( e, i ) => (
							<li key={ i }>{ e }</li>
						) ) }
					</ul>
				</Notice>
			) : null }

			<div className="pc-send-grid">
				<div className="pc-card">
					<Section title="Send to">
						<div className="pc-field">
							<label className="pc-label" htmlFor="pc-scope">
								Customers
							</label>
							<select id="pc-scope" className="pc-input pc-input--auto" value={ tidy.scope } onChange={ ( e ) => set( { scope: ( e.target as HTMLSelectElement ).value } ) }>
								{ SCOPES.map( ( s ) => (
									<option key={ s.key } value={ s.key }>
										{ s.label }
									</option>
								) ) }
							</select>
						</div>

						<fieldset className="pc-radios">
							<legend className="pc-label">Who</legend>
							{ AUDIENCE_TYPES.filter( ( t ) => 'selected' !== t.key || 'selected' === tidy.type ).map( ( t ) => (
								<div key={ t.key } className={ `pc-radio-row${ tidy.type === t.key ? ' is-checked' : '' }` }>
									<label className="pc-radio">
										<input type="radio" name="pc-audience" value={ t.key } checked={ tidy.type === t.key } onChange={ () => set( { type: t.key } ) } />
										<span>{ labelFor( t.key, t.label, tidy ) }</span>
									</label>

									{ tidy.type === t.key && ( 'recent' === t.key || 'inactive' === t.key ) ? (
										<span className="pc-inline">
											<input
												type="number"
												min={ 1 }
												className="pc-input pc-input--num"
												aria-label="Number of days"
												value={ tidy.days }
												onInput={ ( e ) => set( { days: Number( ( e.target as HTMLInputElement ).value ) } ) }
											/>
											days
										</span>
									) : null }

									{ tidy.type === t.key && 'bought_product' === t.key ? (
										<div className="pc-nested">
											<ProductSearch id="pc-product" value={ tidy.product_id ?? 0 } onChange={ ( product_id ) => set( { product_id } ) } />
										</div>
									) : null }

									{ tidy.type === t.key && 'tier' === t.key ? (
										<div className="pc-nested pc-checks">
											{ Object.entries( tiers ).map( ( [ slug, label ] ) => (
												<label key={ slug } className="pc-check">
													<input
														type="checkbox"
														checked={ ( tidy.tiers ?? [] ).includes( slug ) }
														onChange={ ( e ) => {
															const on = ( e.target as HTMLInputElement ).checked;
															const current = tidy.tiers ?? [];
															set( { tiers: on ? [ ...current, slug ] : current.filter( ( x ) => x !== slug ) } );
														} }
													/>
													{ label }
												</label>
											) ) }
											<p className="pc-help">None ticked means every tier.</p>
										</div>
									) : null }
								</div>
							) ) }
						</fieldset>
					</Section>

					<div className="pc-estimate" aria-live="polite">
						{ problems.length > 0 ? (
							<span className="pc-muted">{ problems[ 0 ] }</span>
						) : counting && ! estimate ? (
							<span className="pc-muted">Counting...</span>
						) : estimate ? (
							<>
								<div className="pc-estimate-number">
									<strong>{ estimate.sent_to.toLocaleString() }</strong> will get this email
									{ counting ? <span className="pc-muted"> (updating)</span> : null }
								</div>
								<div className="pc-help">
									{ estimate.label }: { estimate.total.toLocaleString() } in total.
									{ estimate.reasons.length > 0 ? ' Left out: ' + estimate.reasons.map( ( r ) => `${ r.count } ${ r.label }` ).join( ', ' ) + '.' : '' }
								</div>
							</>
						) : null }
					</div>
				</div>

				<div className="pc-card">
					<Section title="Name">
						<div className="pc-field">
							<label className="pc-label" htmlFor="pc-name">
								Email name
							</label>
							<input id="pc-name" type="text" className="pc-input" value={ name } onInput={ ( e ) => setName( ( e.target as HTMLInputElement ).value ) } />
							<p className="pc-help">Only you see this, in your list of emails and the Log.</p>
						</div>
					</Section>

					<Section title="Kind of email">
						<Toggle
							id="pc-service"
							checked={ service }
							onChange={ setService }
							label="This is a service email, not marketing"
							help="A service email has no unsubscribe link and reaches people who unsubscribed from marketing. Use it only for account or order matters, never offers."
						/>
					</Section>

					<Section title="Sender">
						<p className="pc-sender">
							<strong>{ boot.sender?.name || 'Your store' }</strong> &lt;{ boot.sender?.email || 'not set' }&gt;
						</p>
						<p className="pc-help">
							Replies go to { boot.sender?.reply_to || boot.sender?.email || 'the sender' }. <a href={ boot.urls.settings }>Change in Settings</a>
						</p>
					</Section>
				</div>
			</div>

			<div className="pc-send-actions">
				<button type="button" className="pc-btn pc-btn--ghost" onClick={ onBack }>
					<Icon name="arrowLeft" size={ 16 } />
					Back to design
				</button>
				<span className="pc-spacer" />
				<button type="button" className="pc-btn" onClick={ saveAndClose } disabled={ leaving || sending }>
					{ leaving ? 'Saving...' : 'Save as draft and close' }
				</button>
				<button type="button" className="pc-btn pc-btn--primary" onClick={ openConfirm } disabled={ sending || leaving }>
					Send
				</button>
			</div>

			{ confirming ? (
				<ConfirmModal title="Send this email now?" confirmLabel="Send now" busy={ sending } onConfirm={ send } onCancel={ () => setConfirming( false ) }>
					<p>
						<strong>{ name }</strong> goes to <strong>{ ( estimate?.sent_to ?? 0 ).toLocaleString() }</strong> { 1 === estimate?.sent_to ? 'person' : 'people' } ({ estimate?.label ?? 'your audience' }). It cannot be recalled once it starts
						sending.
					</p>
				</ConfirmModal>
			) : null }
		</div>
	);
}

/** "Customers you picked (3)" rather than a bare type name. */
function labelFor( key: string, label: string, audience: AudienceInput ): string {
	if ( 'selected' === key ) {
		return `Customers you picked (${ ( audience.user_ids ?? [] ).length })`;
	}

	if ( 'recent' === key ) {
		return 'Ordered in the last';
	}

	if ( 'inactive' === key ) {
		return 'No order in the last';
	}

	return label;
}
