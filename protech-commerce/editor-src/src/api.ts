import type { AudienceInput, EmailPayload, Estimate, GalleryData, Starter, Template } from './types';

function root(): string {
	return window.protechEditor.restRoot.replace( /\/$/, '' );
}

/** The URL for a path, which may carry its own query string. With plain permalinks the root is `?rest_route=...`, so a second `?` must become `&`. */
export function joinUrl( base: string, path: string ): string {
	return base.includes( '?' ) ? `${ base }${ path.replace( '?', '&' ) }` : `${ base }${ path }`;
}

async function request< T >( method: string, path: string, body?: unknown ): Promise< T > {
	const response = await fetch( joinUrl( root(), path ), {
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

	starters: (): Promise< Starter[] > => request< Starter[] >( 'GET', '/templates/starters' ),

	emails: {
		gallery: (): Promise< GalleryData > => request< GalleryData >( 'GET', '/emails/gallery' ),

		thumbnail: ( source: string ): Promise< { html: string } > => request< { html: string } >( 'GET', `/emails/thumbnail?source=${ encodeURIComponent( source ) }` ),

		create: ( source: 'starter' | 'template' | 'campaign', ref: string, audience?: AudienceInput ): Promise< EmailPayload > =>
			request< EmailPayload >( 'POST', '/emails', { source, key: ref, id: ref, audience } ),

		update: ( id: string, changes: { name?: string; design?: Template; audience?: AudienceInput; service_message?: boolean } ): Promise< EmailPayload > =>
			request< EmailPayload >( 'PUT', `/emails/${ id }`, changes ),

		remove: ( id: string ): Promise< { deleted: boolean } > => request< { deleted: boolean } >( 'DELETE', `/emails/${ id }` ),

		send: ( id: string ): Promise< SendResult > => request< SendResult >( 'POST', `/emails/${ id }/send`, { when: 'now' } ),

		estimate: ( audience: AudienceInput, serviceMessage: boolean ): Promise< Estimate > =>
			request< Estimate >( 'POST', '/audience/estimate', { audience, service_message: serviceMessage } ),
	},

	products: ( search: string ): Promise< ProductHit[] > => request< ProductHit[] >( 'GET', `/products?search=${ encodeURIComponent( search ) }` ),

	product: ( id: number ): Promise< ProductHit[] > => request< ProductHit[] >( 'GET', `/products?include=${ id }` ),
};

export interface SendResult {
	ok: boolean;
	errors?: string[];
	queued?: number;
	log_url?: string;
}

export interface ProductHit {
	id: number;
	name: string;
	sku: string;
}
