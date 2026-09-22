import { useEffect, useState } from 'preact/hooks';
import type { EditorBootstrap, EmailPayload } from '../../types';
import { Step, STEPS, stepFromUrl, stepState, urlFor } from '../../model/steps';
import { Icon } from '../icons';
import { Notice } from '../ui';
import { TypeStep } from './TypeStep';
import { TemplateStep } from './TemplateStep';
import { DesignStep } from './DesignStep';
import { SendStep } from './SendStep';

/**
 * The new-email flow (3.9.0), MailPoet's shape: Type, Template, Design, Send,
 * each its own screen under one header and step bar, with the step kept in
 * the address so a reload or the back button lands where you were.
 */
export function EmailFlow( { boot }: { boot: EditorBootstrap } ) {
	const [ payload, setPayload ] = useState< EmailPayload | null >( boot.emailData ?? null );
	const [ step, setStep ] = useState< Step >( () => stepFromUrl( window.location.search, !! boot.emailData ) );

	useEffect( () => {
		const onPop = () => setStep( stepFromUrl( window.location.search, !! payload ) );

		window.addEventListener( 'popstate', onPop );
		return () => window.removeEventListener( 'popstate', onPop );
	}, [ payload ] );

	const goTo = ( next: Step, email: EmailPayload | null = payload ) => {
		window.history.pushState( {}, '', urlFor( window.location.href, next, email?.email.id ?? '' ) );
		setStep( next );
		window.scrollTo( 0, 0 );
	};

	const exitUrl = payload ? boot.urls.drafts ?? boot.urls.emails ?? '' : boot.urls.emails ?? '';

	let body;

	if ( boot.missingEmail ) {
		body = (
			<div className="pc-page pc-page--narrow">
				<Notice tone="warning">
					That email no longer exists. <a href={ boot.urls.emails }>Back to your emails</a>
				</Notice>
			</div>
		);
	} else if ( payload && 'draft' !== payload.email.status ) {
		body = (
			<div className="pc-page pc-page--narrow">
				<Notice tone="info">
					This email has already been sent, so it cannot be changed. To send it again, use Duplicate on <a href={ boot.urls.emails }>your emails</a>.
				</Notice>
			</div>
		);
	} else if ( 'type' === step || ( ! payload && 'template' !== step ) ) {
		body = <TypeStep boot={ boot } onEmail={ () => goTo( 'template', null ) } />;
	} else if ( ! payload ) {
		body = (
			<TemplateStep
				boot={ boot }
				onCreated={ ( created ) => {
					setPayload( created );
					goTo( 'design', created );
				} }
			/>
		);
	} else if ( 'send' === step ) {
		body = <SendStep boot={ boot } payload={ payload } onChange={ setPayload } onBack={ () => goTo( 'design' ) } />;
	} else {
		body = <DesignStep boot={ boot } payload={ payload } onChange={ setPayload } onNext={ () => goTo( 'send' ) } />;
	}

	return (
		<div className="pc-app pc-flow">
			<header className="pc-flow-header">
				<div className="pc-flow-brand">
					<Icon name="mail" size={ 22 } />
					<span>New email</span>
				</div>

				<ol className="pc-stepper" aria-label="Steps">
					{ STEPS.map( ( s, i ) => {
						const state = stepState( step, s.key );
						// Once the email exists, you can move between Design and Send from the bar.
						const canJump = !! payload && ( 'design' === s.key || 'send' === s.key ) && 'current' !== state;

						return (
							<li key={ s.key } className={ `pc-step is-${ state }` } aria-current={ 'current' === state ? 'step' : undefined }>
								{ canJump ? (
									<button type="button" className="pc-step-button" onClick={ () => goTo( s.key ) }>
										<StepMark state={ state } n={ i + 1 } />
										<span className="pc-step-label">{ s.label }</span>
									</button>
								) : (
									<span className="pc-step-button">
										<StepMark state={ state } n={ i + 1 } />
										<span className="pc-step-label">{ s.label }</span>
									</span>
								) }
							</li>
						);
					} ) }
				</ol>

				<a className="pc-btn pc-btn--ghost pc-flow-close" href={ exitUrl }>
					<Icon name="close" size={ 18 } />
					<span>{ payload ? 'Close' : 'Cancel' }</span>
				</a>
			</header>

			{ body }
		</div>
	);
}

function StepMark( { state, n }: { state: 'done' | 'current' | 'upcoming'; n: number } ) {
	return <span className="pc-step-mark">{ 'done' === state ? <Icon name="check" size={ 14 } /> : n }</span>;
}
