import { describe, expect, it } from 'vitest';
import type { Schema, Template } from '../src/types';
import { countBlocks, duplicateBlock, findBlock, insertBlock, makeBlock, moveBlock, removeBlock, ROOT, updateBlockAttrs } from '../src/model/template';

const schema: Schema = {
	types: {
		heading: { label: 'Heading', help: '', defaults: { pt: 8, pb: 8, bg: '', align: 'left', text: 'Hi', size: 26, color: '' }, fields: [], hidden: [] },
		columns: { label: 'Columns', help: '', defaults: { pt: 8, pb: 8, bg: '', align: 'left', count: 2, gap: 16, valign: 'top' }, fields: [], hidden: [ 'align' ] },
		spacer: { label: 'Space', help: '', defaults: { pt: 0, pb: 0, bg: '', align: 'left', height: 24 }, fields: [], hidden: [ 'align' ] },
	},
	common_fields: [],
	limits: { max_blocks: 60, max_depth: 1 },
	fonts: { helvetica: 'Helvetica' },
};

function baseTemplate(): Template {
	return {
		id: '',
		name: 'Test',
		kind: 'marketing',
		slot: '',
		category: '',
		subject: '',
		preheader: '',
		blocks: [],
		style: { width: 0, page_bg: '#fff', canvas: '#fff', brand: '', text: '#000', muted: '#666', font: 'helvetica', heading_font: '', link_color: '', mobile_padding: 0 },
		header: { show_logo: true, logo_id: 0 },
		footer: { text: '', show_address: true },
		created_at: 0,
		updated_at: 0,
		created_by: 0,
		seeded: '',
	};
}

describe( 'makeBlock', () => {
	it( 'builds a block from schema defaults with a fresh id', () => {
		const block = makeBlock( schema, 'heading' )!;
		expect( block.id ).toMatch( /^b_[0-9a-f]{8}$/ );
		expect( block.attrs.text ).toBe( 'Hi' );
	} );

	it( 'gives a columns block one empty list per column', () => {
		const block = makeBlock( schema, 'columns' )!;
		expect( block.children ).toEqual( [ [], [] ] );
	} );

	it( 'returns null for an unknown type', () => {
		expect( makeBlock( schema, 'nonsense' ) ).toBeNull();
	} );
} );

describe( 'insertBlock / removeBlock', () => {
	it( 'inserts at the given index and removes by id', () => {
		let t = baseTemplate();
		const a = makeBlock( schema, 'heading' )!;
		const b = makeBlock( schema, 'spacer' )!;

		t = insertBlock( t, ROOT, 0, a );
		t = insertBlock( t, ROOT, 1, b );
		expect( t.blocks.map( ( x ) => x.id ) ).toEqual( [ a.id, b.id ] );

		t = removeBlock( t, ROOT, a.id );
		expect( t.blocks.map( ( x ) => x.id ) ).toEqual( [ b.id ] );
	} );

	it( 'inserts into a specific column of a columns block', () => {
		let t = baseTemplate();
		const cols = makeBlock( schema, 'columns' )!;
		t = insertBlock( t, ROOT, 0, cols );

		const heading = makeBlock( schema, 'heading' )!;
		t = insertBlock( t, { parentId: cols.id, column: 1 }, 0, heading );

		expect( t.blocks[ 0 ].children![ 0 ] ).toEqual( [] );
		expect( t.blocks[ 0 ].children![ 1 ][ 0 ].id ).toBe( heading.id );
	} );
} );

describe( 'moveBlock', () => {
	it( 'reorders within the same list', () => {
		let t = baseTemplate();
		const a = makeBlock( schema, 'heading' )!;
		const b = makeBlock( schema, 'spacer' )!;
		const c = makeBlock( schema, 'heading' )!;
		t = insertBlock( t, ROOT, 0, a );
		t = insertBlock( t, ROOT, 1, b );
		t = insertBlock( t, ROOT, 2, c );

		// Move c (index 2) up: swap with b.
		t = moveBlock( t, ROOT, c.id, ROOT, 1 );
		expect( t.blocks.map( ( x ) => x.id ) ).toEqual( [ a.id, c.id, b.id ] );
	} );

	it( 'moves a block down (swap with next) using idx+2', () => {
		let t = baseTemplate();
		const a = makeBlock( schema, 'heading' )!;
		const b = makeBlock( schema, 'spacer' )!;
		const c = makeBlock( schema, 'heading' )!;
		t = insertBlock( t, ROOT, 0, a );
		t = insertBlock( t, ROOT, 1, b );
		t = insertBlock( t, ROOT, 2, c );

		// Move a (index 0) down: swap with b.
		t = moveBlock( t, ROOT, a.id, ROOT, 2 );
		expect( t.blocks.map( ( x ) => x.id ) ).toEqual( [ b.id, a.id, c.id ] );
	} );

	it( 'moves a block from the root into a column', () => {
		let t = baseTemplate();
		const cols = makeBlock( schema, 'columns' )!;
		const heading = makeBlock( schema, 'heading' )!;
		t = insertBlock( t, ROOT, 0, cols );
		t = insertBlock( t, ROOT, 1, heading );

		t = moveBlock( t, ROOT, heading.id, { parentId: cols.id, column: 0 }, 0 );

		expect( t.blocks.length ).toBe( 1 );
		expect( t.blocks[ 0 ].children![ 0 ][ 0 ].id ).toBe( heading.id );
	} );
} );

describe( 'duplicateBlock', () => {
	it( 'copies a block with a fresh id, right after the original', () => {
		let t = baseTemplate();
		const a = makeBlock( schema, 'heading' )!;
		t = insertBlock( t, ROOT, 0, a );

		t = duplicateBlock( t, ROOT, a.id );

		expect( t.blocks.length ).toBe( 2 );
		expect( t.blocks[ 1 ].id ).not.toBe( a.id );
		expect( t.blocks[ 1 ].attrs.text ).toBe( a.attrs.text );
	} );

	it( 'gives every nested child a fresh id too', () => {
		let t = baseTemplate();
		const cols = makeBlock( schema, 'columns' )!;
		const heading = makeBlock( schema, 'heading' )!;
		t = insertBlock( t, ROOT, 0, cols );
		t = insertBlock( t, { parentId: cols.id, column: 0 }, 0, heading );

		t = duplicateBlock( t, ROOT, cols.id );

		const copyId = t.blocks[ 1 ].children![ 0 ][ 0 ].id;
		expect( copyId ).not.toBe( heading.id );
	} );
} );

describe( 'updateBlockAttrs', () => {
	it( 'merges a patch into the existing attrs without touching others', () => {
		let t = baseTemplate();
		const a = makeBlock( schema, 'heading' )!;
		t = insertBlock( t, ROOT, 0, a );

		t = updateBlockAttrs( t, ROOT, a.id, { text: 'Changed' } );

		expect( t.blocks[ 0 ].attrs.text ).toBe( 'Changed' );
		expect( t.blocks[ 0 ].attrs.size ).toBe( 26 );
	} );
} );

describe( 'findBlock', () => {
	it( 'finds a top-level block', () => {
		let t = baseTemplate();
		const a = makeBlock( schema, 'heading' )!;
		t = insertBlock( t, ROOT, 0, a );

		const found = findBlock( t, a.id );
		expect( found?.address ).toEqual( ROOT );
	} );

	it( 'finds a block nested one column deep', () => {
		let t = baseTemplate();
		const cols = makeBlock( schema, 'columns' )!;
		const heading = makeBlock( schema, 'heading' )!;
		t = insertBlock( t, ROOT, 0, cols );
		t = insertBlock( t, { parentId: cols.id, column: 1 }, 0, heading );

		const found = findBlock( t, heading.id );
		expect( found?.address ).toEqual( { parentId: cols.id, column: 1 } );
	} );

	it( 'returns null for a missing id', () => {
		expect( findBlock( baseTemplate(), 'b_missing' ) ).toBeNull();
	} );
} );

describe( 'countBlocks', () => {
	it( 'counts nested blocks too', () => {
		let t = baseTemplate();
		const cols = makeBlock( schema, 'columns' )!;
		const heading = makeBlock( schema, 'heading' )!;
		t = insertBlock( t, ROOT, 0, cols );
		t = insertBlock( t, { parentId: cols.id, column: 0 }, 0, heading );

		expect( countBlocks( t.blocks ) ).toBe( 2 );
	} );
} );
