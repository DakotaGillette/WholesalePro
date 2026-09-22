import { describe, expect, it } from 'vitest';
import { stepFromUrl, stepState, urlFor } from '../src/model/steps';
import { audienceProblems, tidyAudience } from '../src/model/audience';
import { defaultSchedule, isFuture, timeOptions } from '../src/model/schedule';
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

describe( 'schedule', () => {
	it( 'offers every half hour with 12-hour labels', () => {
		const options = timeOptions();
		expect( options ).toHaveLength( 48 );
		expect( options[ 0 ] ).toEqual( { value: '00:00', label: '12:00 am' } );
		expect( options[ 16 ] ).toEqual( { value: '08:00', label: '8:00 am' } );
		expect( options[ 27 ] ).toEqual( { value: '13:30', label: '1:30 pm' } );
	} );

	it( 'suggests tomorrow at 8, across a month end', () => {
		expect( defaultSchedule( '2026-09-22 12:58' ) ).toEqual( { date: '2026-09-23', time: '08:00' } );
		expect( defaultSchedule( '2026-12-31 23:10' ) ).toEqual( { date: '2027-01-01', time: '08:00' } );
	} );

	it( 'compares on the site clock and refuses malformed values', () => {
		expect( isFuture( '2026-09-22', '13:00', '2026-09-22 12:58' ) ).toBe( true );
		expect( isFuture( '2026-09-22', '12:30', '2026-09-22 12:58' ) ).toBe( false );
		expect( isFuture( 'tomorrow', '08:00', '2026-09-22 12:58' ) ).toBe( false );
	} );
} );
