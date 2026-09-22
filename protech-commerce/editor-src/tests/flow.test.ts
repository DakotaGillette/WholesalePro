import { describe, expect, it } from 'vitest';
import { stepFromUrl, stepState, urlFor } from '../src/model/steps';
import { audienceProblems, tidyAudience } from '../src/model/audience';
import { joinUrl } from '../src/api';

describe( 'stepFromUrl', () => {
	it( 'offers only Type and Template before an email exists', () => {
		expect( stepFromUrl( '', false ) ).toBe( 'type' );
		expect( stepFromUrl( '?step=template', false ) ).toBe( 'template' );
		expect( stepFromUrl( '?step=send', false ) ).toBe( 'type' );
	} );

	it( 'offers only Design and Send once it exists', () => {
		expect( stepFromUrl( '?email=c_1_abcdef', true ) ).toBe( 'design' );
		expect( stepFromUrl( '?email=c_1_abcdef&step=send', true ) ).toBe( 'send' );
		expect( stepFromUrl( '?email=c_1_abcdef&step=type', true ) ).toBe( 'design' );
	} );
} );

describe( 'stepState', () => {
	it( 'marks earlier steps done and later ones upcoming', () => {
		expect( stepState( 'design', 'type' ) ).toBe( 'done' );
		expect( stepState( 'design', 'design' ) ).toBe( 'current' );
		expect( stepState( 'design', 'send' ) ).toBe( 'upcoming' );
	} );
} );

describe( 'urlFor', () => {
	it( 'sets the step and email and keeps other arguments', () => {
		const url = new URL( urlFor( 'https://shop.test/wp-admin/admin.php?page=protech-messaging-compose&ids=7', 'design', 'c_1_abcdef' ) );

		expect( url.searchParams.get( 'step' ) ).toBe( 'design' );
		expect( url.searchParams.get( 'email' ) ).toBe( 'c_1_abcdef' );
		expect( url.searchParams.get( 'ids' ) ).toBe( '7' );
		expect( new URL( urlFor( url.toString(), 'template', '' ) ).searchParams.has( 'email' ) ).toBe( false );
	} );
} );

describe( 'tidyAudience', () => {
	it( 'keeps only what the chosen type reads, with defaults', () => {
		expect( tidyAudience( { type: 'recent', user_ids: [ 1 ] } ) ).toEqual( { type: 'recent', scope: 'wholesale', days: 30 } );
		expect( tidyAudience( { type: 'inactive', days: -4 } ) ).toEqual( { type: 'inactive', scope: 'wholesale', days: 60 } );
		expect( tidyAudience( { type: 'tier', scope: 'retail', tiers: [ 'gold' ], days: 9 } ) ).toEqual( { type: 'tier', scope: 'retail', tiers: [ 'gold' ] } );
		expect( tidyAudience( { type: '' } ) ).toEqual( { type: 'all', scope: 'wholesale' } );
	} );
} );

describe( 'audienceProblems', () => {
	it( 'asks for a product or picked customers when those types have none', () => {
		expect( audienceProblems( { type: 'bought_product' } ) ).toHaveLength( 1 );
		expect( audienceProblems( { type: 'bought_product', product_id: 12 } ) ).toEqual( [] );
		expect( audienceProblems( { type: 'selected', user_ids: [] } ) ).toHaveLength( 1 );
		expect( audienceProblems( { type: 'all' } ) ).toEqual( [] );
	} );
} );

describe( 'joinUrl', () => {
	it( 'joins a query string onto a plain-permalink REST root with &', () => {
		expect( joinUrl( 'https://shop.test/wp-json/protech/v1', '/emails/thumbnail?source=a' ) ).toBe( 'https://shop.test/wp-json/protech/v1/emails/thumbnail?source=a' );
		expect( joinUrl( 'https://shop.test/?rest_route=/protech/v1', '/emails/thumbnail?source=a' ) ).toBe( 'https://shop.test/?rest_route=/protech/v1/emails/thumbnail&source=a' );
	} );
} );
