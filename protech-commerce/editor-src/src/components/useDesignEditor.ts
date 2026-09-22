import { useEffect, useMemo, useRef, useState } from 'preact/hooks';
import type { Schema, Template } from '../types';
import { canRedo, canUndo, History, initHistory, push, redo, replace, undo } from '../model/history';
import { Address, countBlocks, duplicateBlock, findBlock, listAt, makeBlock, moveBlock as moveBlockModel, removeBlock, insertBlock, updateBlockAttrs } from '../model/template';

/**
 * Everything the canvas, palette and block settings do to a design: the
 * undo history, the selected block, the block operations, and the keyboard
 * shortcuts. Shared by the template editor and the new-email flow's Design
 * step, so the two can never behave differently.
 */
export function useDesignEditor( initial: Template, schema: Schema, onSave: () => void, keyboard = true ) {
	const [ history, setHistory ] = useState< History< Template > >( () => initHistory( initial ) );
	const [ selectedId, setSelectedId ] = useState< string | null >( null );
	const template = history.present;

	// The latest save handler, so the keyboard listener never calls a stale one.
	const saveRef = useRef( onSave );
	saveRef.current = onSave;

	const selected = useMemo( () => ( selectedId ? findBlock( template, selectedId ) : null ), [ template, selectedId ] );

	function apply( next: Template ): void {
		setHistory( ( h ) => push( h, next ) );
	}

	function insert( address: Address, index: number, type: string ): void {
		if ( countBlocks( template.blocks ) >= schema.limits.max_blocks ) {
			window.alert( `A design can hold at most ${ schema.limits.max_blocks } blocks.` );
			return;
		}

		const block = makeBlock( schema, type );

		if ( ! block ) {
			return;
		}

		apply( insertBlock( template, address, index, block ) );
		setSelectedId( block.id );
	}

	function move( fromParentId: string | null, fromColumn: number, blockId: string, toAddress: Address, toIndex: number ): void {
		apply( moveBlockModel( template, { parentId: fromParentId, column: fromColumn }, blockId, toAddress, toIndex ) );
	}

	function changeAttrs( patch: Record< string, unknown > ): void {
		if ( ! selected ) {
			return;
		}

		apply( updateBlockAttrs( template, selected.address, selected.block.id, patch ) );
	}

	function remove( id: string ): void {
		const found = findBlock( template, id );

		if ( ! found ) {
			return;
		}

		apply( removeBlock( template, found.address, id ) );
		setSelectedId( null );
	}

	function duplicate( id: string ): void {
		const found = findBlock( template, id );

		if ( ! found ) {
			return;
		}

		apply( duplicateBlock( template, found.address, id ) );
	}

	function reorder( id: string, direction: -1 | 1 ): void {
		const found = findBlock( template, id );

		if ( ! found ) {
			return;
		}

		const list = listAt( template, found.address );
		const idx = list.findIndex( ( b ) => b.id === id );

		if ( -1 === idx || ( -1 === direction && idx <= 0 ) || ( 1 === direction && idx >= list.length - 1 ) ) {
			return;
		}

		const toIndex = -1 === direction ? idx - 1 : idx + 2;
		apply( moveBlockModel( template, found.address, id, found.address, toIndex ) );
	}

	function change( patch: Partial< Template > ): void {
		apply( { ...template, ...patch } );
	}

	// Keyboard: Ctrl/Cmd+Z undo, Shift+Ctrl/Cmd+Z redo, Ctrl/Cmd+S save,
	// Delete/Backspace removes the selected block -- but never while a
	// field has focus, where those keys mean what they always mean.
	useEffect( () => {
		if ( ! keyboard ) {
			return undefined;
		}

		const onKeyDown = ( e: KeyboardEvent ) => {
			const mod = e.ctrlKey || e.metaKey;
			const target = e.target as HTMLElement | null;
			const inField = !! target && [ 'INPUT', 'TEXTAREA', 'SELECT' ].includes( target.tagName );

			if ( mod && 'z' === e.key.toLowerCase() && ! e.shiftKey ) {
				e.preventDefault();
				setHistory( ( h ) => undo( h ) );
			} else if ( mod && ( ( 'z' === e.key.toLowerCase() && e.shiftKey ) || 'y' === e.key.toLowerCase() ) ) {
				e.preventDefault();
				setHistory( ( h ) => redo( h ) );
			} else if ( mod && 's' === e.key.toLowerCase() ) {
				e.preventDefault();
				saveRef.current();
			} else if ( ( 'Delete' === e.key || 'Backspace' === e.key ) && selectedId && ! inField ) {
				e.preventDefault();
				remove( selectedId );
			}
		};

		window.addEventListener( 'keydown', onKeyDown );
		return () => window.removeEventListener( 'keydown', onKeyDown );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ selectedId, template, keyboard ] );

	return {
		template,
		selected,
		selectedId,
		setSelectedId,
		canUndo: canUndo( history ),
		canRedo: canRedo( history ),
		undo: () => setHistory( undo ),
		redo: () => setHistory( redo ),
		/** Starts over from a design, with no undo back past it (a starter picked, a draft restored). */
		reset: ( next: Template ) => setHistory( initHistory( next ) ),
		/** Swaps in the server's cleaned copy after a save, without an undo step. */
		replacePresent: ( next: Template ) => setHistory( ( h ) => replace( h, next ) ),
		insert,
		move,
		changeAttrs,
		remove,
		duplicate,
		reorder,
		change,
	};
}

export type DesignEditor = ReturnType< typeof useDesignEditor >;
