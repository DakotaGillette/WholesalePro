import type { EditorBootstrap } from '../../types';
import { Icon } from '../icons';

/** Step 1: what kind of message. An email continues to Template; the other two leave the flow. */
export function TypeStep( { boot, onEmail }: { boot: EditorBootstrap; onEmail: () => void } ) {
	const smsReady = !! boot.smsReady && '0' !== boot.smsReady;

	return (
		<div className="pc-page">
			<h2 className="pc-page-title">What would you like to send?</h2>

			<div className="pc-type-grid">
				<button type="button" className="pc-type-card" onClick={ onEmail }>
					<span className="pc-type-icon">
						<Icon name="mail" size={ 28 } />
					</span>
					<span className="pc-type-name">Email</span>
					<span className="pc-type-help">Pick a template, design it, then choose who gets it and send it now.</span>
					<span className="pc-btn pc-btn--primary pc-type-cta">Create</span>
				</button>

				<a className={ `pc-type-card${ smsReady ? '' : ' is-muted' }` } href={ boot.urls.textForm }>
					<span className="pc-type-icon">
						<Icon name="sms" size={ 28 } />
					</span>
					<span className="pc-type-name">Text message</span>
					<span className="pc-type-help">
						{ smsReady ? 'A short text to customers who opted in to texts.' : 'Connect Brevo in Settings first: texts need a text provider.' }
					</span>
					<span className="pc-btn pc-type-cta">Create</span>
				</a>

				<a className="pc-type-card" href={ boot.urls.automatic }>
					<span className="pc-type-icon">
						<Icon name="auto" size={ 28 } />
					</span>
					<span className="pc-type-name">Automatic email</span>
					<span className="pc-type-help">Welcome, application and order emails the shop sends on its own.</span>
					<span className="pc-btn pc-type-cta">Set up</span>
				</a>
			</div>
		</div>
	);
}
