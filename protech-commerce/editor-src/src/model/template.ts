import type { Attrs, Block, Schema, Template } from '../types';
import { newBlockId } from './ids';

/**
 * Where a block lives: the top level, or a specific column of a specific
 * columns block. Columns nest exactly one level (EmailBlocks::MAX_DEPTH),
 * so a column's own contents are always a plain list, never another
 * address to resolve further.
 */
export interface Address {
	parentId: string | null;
	column: number;
}

export const ROOT: Address = { parentId: null, column: 0 };

function findTopLevel( blocks: Block[], id: string ): Block | undefined {
	return blocks.find( ( b ) => b.id === id );
}

/** The list a block at this address currently lives in (never mutated in place). */
export function listAt( template: Template, address: Address ): Block[] {
	if ( null === address.parentId ) {
		return template.blocks;
	}

	const parent = findTopLevel( template.blocks, address.parentId );
	return parent?.children?.[ address.column ] ?? [];
}

/** Replaces the list at an address, returning a new template. */
function withListAt( template: Template, address: Address, list: Block[] ): Template {
	if ( null === address.parentId ) {
		return { ...template, blocks: list };
	}

	const blocks = template.blocks.map( ( b ) => {
		if ( b.id !== address.parentId || ! b.children ) {
			return b;
		}

		const children = b.children.slice();
		children[ address.column ] = list;
		return { ...b, children };
	} );

	return { ...template, blocks };
}

/** A fresh block of $type with schema defaults and a new id. Columns get one empty list per column. */
export function makeBlock( schema: Schema, type: string ): Block | null {
	const def = schema.types[ type ];

	if ( ! def ) {
		return null;
	}

	const attrs: Attrs = { ...def.defaults };
	let children: Block[][] | undefined;

	if ( 'columns' in schema.types && type === 'columns' ) {
		const count = Number( attrs.count ) || 2;
		children = Array.from( { length: count }, () => [] );
		delete attrs.children;
	}

	return { id: newBlockId(), type, attrs, ...( children ? { children } : {} ) };
}

/**
 * Whether a block of `type` (with id `blockId` when an existing block is being
 * moved, null for a new one) may land at `to`. Columns never go inside a
 * column (one level of nesting only), and nothing is dropped into the very
 * block being moved: removing it first would leave the insert nowhere to go,
 * and the block would silently vanish.
 */
export function canDropAt( type: string, blockId: string | null, to: Address ): boolean {
	if ( null === to.parentId ) {
		return true;
	}

	return 'columns' !== type && to.parentId !== blockId;
}

/** Total block count, nested ones included: the same thing EmailBlocks::MAX_BLOCKS caps, for a friendly warning before the server would clamp it. */
export function countBlocks( blocks: Block[] ): number {
	let n = 0;

	for ( const b of blocks ) {
		n += 1;

		if ( b.children ) {
			for ( const col of b.children ) {
				n += countBlocks( col );
			}
		}
	}

	return n;
}

export function insertBlock( template: Template, address: Address, index: number, block: Block ): Template {
	const list = listAt( template, address ).slice();
	list.splice( Math.max( 0, Math.min( index, list.length ) ), 0, block );
	return withListAt( template, address, list );
}

export function removeBlock( template: Template, address: Address, blockId: string ): Template {
	const list = listAt( template, address ).filter( ( b ) => b.id !== blockId );
	return withListAt( template, address, list );
}

export function moveBlock( template: Template, from: Address, blockId: string, to: Address, toIndex: number ): Template {
	const fromList = listAt( template, from );
	const block = fromList.find( ( b ) => b.id === blockId );

	if ( ! block ) {
		return template;
	}

	let next = removeBlock( template, from, blockId );

	// Moving within the same list: removing the block first shifts every
	// later index down by one, so a target index past the removal point
	// needs the same correction before inserting.
	const sameList = from.parentId === to.parentId && from.column === to.column;
	const fromIndex = fromList.findIndex( ( b ) => b.id === blockId );
	const adjustedIndex = sameList && toIndex > fromIndex ? toIndex - 1 : toIndex;

	next = insertBlock( next, to, adjustedIndex, block );

	return next;
}

export function duplicateBlock( template: Template, address: Address, blockId: string ): Template {
	const list = listAt( template, address );
	const index = list.findIndex( ( b ) => b.id === blockId );

	if ( -1 === index ) {
		return template;
	}

	const copy = reidBlock( list[ index ] );
	return insertBlock( template, address, index + 1, copy );
}

function reidBlock( block: Block ): Block {
	const copy: Block = { ...block, id: newBlockId(), attrs: { ...block.attrs } };

	if ( block.children ) {
		copy.children = block.children.map( ( col ) => col.map( reidBlock ) );
	}

	return copy;
}

export function updateBlockAttrs( template: Template, address: Address, blockId: string, patch: Attrs ): Template {
	const list = listAt( template, address ).map( ( b ) => ( b.id === blockId ? { ...b, attrs: { ...b.attrs, ...patch } } : b ) );
	return withListAt( template, address, list );
}

/** Finds a block anywhere (top level or one column deep) by id, and the address it lives at. */
export function findBlock( template: Template, blockId: string ): { block: Block; address: Address } | null {
	for ( const b of template.blocks ) {
		if ( b.id === blockId ) {
			return { block: b, address: ROOT };
		}

		if ( b.children ) {
			for ( let col = 0; col < b.children.length; col++ ) {
				const found = b.children[ col ].find( ( c ) => c.id === blockId );

				if ( found ) {
					return { block: found, address: { parentId: b.id, column: col } };
				}
			}
		}
	}

	return null;
}
