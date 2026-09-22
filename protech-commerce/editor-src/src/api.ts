import type { Template } from './types';

function root(): string {
	return window.protechEditor.restRoot.replace( /\/$/, '' );
}

async function request< T >( method: string, path: string, body?: unknown ): Promise< T > {
	const response = await fetch( `${ root() }${ path }`, {
		method,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': window.protechEditor.nonce,
		},
		credentials: 'same-origin',
		body: undefined === body ? undefined : JSON.stringify( body ),
	} );

	const data = await response.json().catch( () => ( {} ) );

	if ( ! response.ok && ! ( 'errors' in ( data as object ) ) ) {
		const message = ( data as { message?: string } ).message || `Request failed (${ response.status }).`;
		throw new Error( message );
	}

	return data as T;
}

export interface SaveResult {
	template: Template;
	errors: string[];
}

export interface PreviewResult {
	subject: string;
	html: string;
	text: string;
	errors: string[];
}

export interface TestSendResult {
	ok: boolean;
	to: string;
	error: string;
}

export const api = {
	save: ( template: Template ): Promise< SaveResult > =>
		template.id
			? request< SaveResult >( 'PUT', `/templates/${ template.id }`, template )
			: request< SaveResult >( 'POST', '/templates', template ),

	preview: ( template: Template ): Promise< PreviewResult > => request< PreviewResult >( 'POST', '/templates/preview', template ),

	testSend: ( template: Template, to: string ): Promise< TestSendResult > =>
		request< TestSendResult >( 'POST', '/templates/test-send', { ...template, to } ),
};
