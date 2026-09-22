/**
 * The new-email flow's four steps (MailPoet's Newsletter, Template, Design,
 * Send), and how the page address maps to one. Before an email exists only
 * Type and Template make sense; once it exists, only Design and Send do.
 */

export type Step = 'type' | 'template' | 'design' | 'send';

export const STEPS: Array< { key: Step; label: string } > = [
	{ key: 'type', label: 'Type' },
	{ key: 'template', label: 'Template' },
	{ key: 'design', label: 'Design' },
	{ key: 'send', label: 'Send' },
];

/** Which step the address asks for, corrected to one that exists for the email (or its absence). */
export function stepFromUrl( search: string, hasEmail: boolean ): Step {
	const asked = new URLSearchParams( search ).get( 'step' ) ?? '';

	if ( hasEmail ) {
		return 'send' === asked ? 'send' : 'design';
	}

	return 'template' === asked ? 'template' : 'type';
}

/** How a step shows in the step bar while `current` is open. */
export function stepState( current: Step, step: Step ): 'done' | 'current' | 'upcoming' {
	const order = STEPS.map( ( s ) => s.key );
	const at = order.indexOf( current );
	const index = order.indexOf( step );

	if ( index === at ) {
		return 'current';
	}

	return index < at ? 'done' : 'upcoming';
}

/** The address for a step, keeping every other argument (such as a Customers-tab preset) in place. */
export function urlFor( href: string, step: Step, emailId: string ): string {
	const url = new URL( href );

	url.searchParams.set( 'step', step );

	if ( emailId ) {
		url.searchParams.set( 'email', emailId );
	} else {
		url.searchParams.delete( 'email' );
	}

	return url.toString();
}
