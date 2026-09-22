import type { ComponentChildren } from 'preact';

/**
 * The few building blocks the editor and the new-email flow share, styled by
 * the `.pc-*` tokens in style.css rather than wp-admin's stock buttons and
 * notices.
 */

export function Notice( { tone, children }: { tone: 'info' | 'warning' | 'error' | 'success'; children: ComponentChildren } ) {
	return (
		<div className={ `pc-notice pc-notice--${ tone }` } role={ 'error' === tone ? 'alert' : 'status' }>
			{ children }
		</div>
	);
}

interface ToggleProps {
	id: string;
	checked: boolean;
	onChange: ( checked: boolean ) => void;
	label: ComponentChildren;
	help?: ComponentChildren;
}

/** An on/off switch: a real checkbox underneath, so it works with the keyboard and screen readers. */
export function Toggle( { id, checked, onChange, label, help }: ToggleProps ) {
	return (
		<div className="pc-toggle-row">
			<label className="pc-toggle" htmlFor={ id }>
				<input id={ id } type="checkbox" role="switch" checked={ checked } onChange={ ( e ) => onChange( ( e.target as HTMLInputElement ).checked ) } />
				<span className="pc-toggle-track" aria-hidden="true">
					<span className="pc-toggle-thumb" />
				</span>
				<span className="pc-toggle-label">{ label }</span>
			</label>
			{ help ? <p className="pc-help">{ help }</p> : null }
		</div>
	);
}

/** A labelled group on a step: a title, an optional one-line explanation, and its fields. */
export function Section( { title, help, children }: { title: string; help?: ComponentChildren; children: ComponentChildren } ) {
	return (
		<section className="pc-section">
			<h3 className="pc-section-title">{ title }</h3>
			{ help ? <p className="pc-help">{ help }</p> : null }
			{ children }
		</section>
	);
}
