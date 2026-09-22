import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import type { Template } from '../src/types';
import { countBlocks, duplicateBlock, findBlock, removeBlock, ROOT } from '../src/model/template';

// Shared with tests/test-email-templates.php on the PHP side: that test
// asserts EmailTemplates::validate() is the identity on this file, this
// one asserts the TS model is too. If either language starts reshaping
// the fixture, only one of the two tests needs to fail for that to show up.
const fixturePath = fileURLToPath( new URL( '../../tests/fixtures/template.json', import.meta.url ) );
const fixtureRaw = readFileSync( fixturePath, 'utf8' );

function load(): Template {
	return JSON.parse( fixtureRaw ) as Template;
}

describe( 'fixture round-trip', () => {
	it( 'parses into the shape the model expects', () => {
		const t = load();
		expect( t.blocks ).toHaveLength( 4 );
		expect( t.blocks.map( ( b ) => b.type ) ).toEqual( [ 'heading', 'text', 'columns', 'spacer' ] );
	} );

	it( 'keeps a multi-paragraph text block as raw blank-line-separated text, not <p> tags', () => {
		const t = load();
		const text = t.blocks[ 1 ];
		expect( text.attrs.html ).toBe( 'Thanks for shopping with us.\n\nHere is a quick update on your order.' );
	} );

	it( 'nests the button and divider inside the two columns exactly as stored', () => {
		const t = load();
		const columns = t.blocks[ 2 ];
		expect( columns.children ).toHaveLength( 2 );
		expect( columns.children![ 0 ][ 0 ].type ).toBe( 'button' );
		expect( columns.children![ 1 ][ 0 ].type ).toBe( 'divider' );
	} );

	it( 'loading then serializing straight back changes nothing', () => {
		const t = load();
		expect( JSON.parse( JSON.stringify( t ) ) ).toEqual( JSON.parse( fixtureRaw ) );
	} );

	it( 'finds every block in the fixture at its expected address', () => {
		const t = load();
		expect( findBlock( t, 'b_00000001' )?.address ).toEqual( ROOT );
		expect( findBlock( t, 'b_00000004' )?.address ).toEqual( { parentId: 'b_00000003', column: 0 } );
		expect( findBlock( t, 'b_00000005' )?.address ).toEqual( { parentId: 'b_00000003', column: 1 } );
	} );

	it( 'counts the nested column blocks toward the total', () => {
		const t = load();
		expect( countBlocks( t.blocks ) ).toBe( 6 );
	} );

	it( 'duplicating then removing the copy restores the original template', () => {
		const t = load();
		const withCopy = duplicateBlock( t, ROOT, 'b_00000006' );
		const copyId = withCopy.blocks[ withCopy.blocks.length - 1 ].id;

		const restored = removeBlock( withCopy, ROOT, copyId );

		expect( restored ).toEqual( t );
	} );
} );
