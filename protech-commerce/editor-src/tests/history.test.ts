import { describe, expect, it } from 'vitest';
import { canRedo, canUndo, initHistory, push, redo, replace, undo } from '../src/model/history';

describe( 'history', () => {
	it( 'starts with nothing to undo or redo', () => {
		const h = initHistory( 'a' );
		expect( canUndo( h ) ).toBe( false );
		expect( canRedo( h ) ).toBe( false );
		expect( h.present ).toBe( 'a' );
	} );

	it( 'pushing moves the old present into past and clears future', () => {
		let h = initHistory( 'a' );
		h = push( h, 'b' );
		expect( h.present ).toBe( 'b' );
		expect( h.past ).toEqual( [ 'a' ] );
		expect( h.future ).toEqual( [] );
	} );

	it( 'a push identical to the present is a no-op', () => {
		const h = initHistory( 'a' );
		const same = push( h, 'a' );
		expect( same ).toBe( h );
	} );

	it( 'undo then redo restores the same states', () => {
		let h = initHistory( 'a' );
		h = push( h, 'b' );
		h = push( h, 'c' );

		h = undo( h );
		expect( h.present ).toBe( 'b' );
		expect( canRedo( h ) ).toBe( true );

		h = undo( h );
		expect( h.present ).toBe( 'a' );
		expect( canUndo( h ) ).toBe( false );

		h = redo( h );
		expect( h.present ).toBe( 'b' );

		h = redo( h );
		expect( h.present ).toBe( 'c' );
		expect( canRedo( h ) ).toBe( false );
	} );

	it( 'undo on an empty past is a no-op', () => {
		const h = initHistory( 'a' );
		expect( undo( h ) ).toBe( h );
	} );

	it( 'redo on an empty future is a no-op', () => {
		const h = initHistory( 'a' );
		expect( redo( h ) ).toBe( h );
	} );

	it( 'a new push after an undo drops the redo branch', () => {
		let h = initHistory( 'a' );
		h = push( h, 'b' );
		h = undo( h );
		h = push( h, 'c' );

		expect( h.present ).toBe( 'c' );
		expect( canRedo( h ) ).toBe( false );
	} );

	it( 'replace swaps the present without creating an undo step', () => {
		let h = initHistory( 'a' );
		h = push( h, 'b' );
		h = replace( h, 'b-cleaned' );

		expect( h.present ).toBe( 'b-cleaned' );
		expect( h.past ).toEqual( [ 'a' ] );

		h = undo( h );
		expect( h.present ).toBe( 'a' );
	} );

	it( 'caps the past at 100 entries', () => {
		let h = initHistory( 0 );
		for ( let i = 1; i <= 150; i++ ) {
			h = push( h, i );
		}
		expect( h.past.length ).toBe( 100 );
		expect( h.past[ 0 ] ).toBe( 50 );
		expect( h.present ).toBe( 150 );
	} );
} );
