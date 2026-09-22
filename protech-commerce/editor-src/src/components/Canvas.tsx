import { createContext } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import type { Schema, Template, TemplateStyle } from '../types';
import type { Address } from '../model/template';
import { canDropAt, ROOT } from '../model/template';
import { resolveStyle } from '../model/style';
import { currentDrag, endDrag } from '../dnd';
import { BlockView } from './BlockView';

/**
 * Where a drop would land if the user let go now: before or after a block
 * (its top or bottom half), or at the end of a column or of the email.
 * `key` is a block id, `col:<parentId>:<column>`, or `end`.
 */
export interface DropTarget {
	key: string;
	pos: 'before' | 'after' | 'inside';
}

export interface CanvasActions {
	selectedId: string | null;
	target: DropTarget | null;
	setTarget: ( target: DropTarget | null ) => void;
	/** Inserts the dragged block (new or moved) at this spot, if it is allowed there. */
	dropAt: ( address: Address, index: number ) => void;
	/** Whether the block being dragged may land in this list. */
	canDropIn: ( address: Address ) => boolean;
	onSelect: ( id: string ) => void;
	onRemove: ( id: string ) => void;
	onDuplicate: ( id: string ) => void;
	onReorder: ( id: string, direction: -1 | 1 ) => void;
}

export const CanvasContext = createContext< CanvasActions | null >( null );

interface Props {
	template: Template;
	schema: Schema;
	styleDefaults: TemplateStyle;
	selectedId: string | null;
	onSelect: ( id: string | null ) => void;
	onInsert: ( address: Address, index: number, type: string ) => void;
	onMove: ( fromParentId: string | null, fromColumn: number, blockId: string, toAddress: Address, toIndex: number ) => void;
	onRemove: ( id: string ) => void;
	onDuplicate: ( id: string ) => void;
	onReorder: ( id: string, direction: -1 | 1 ) => void;
}

export function Canvas( { template, schema, styleDefaults, selectedId, onSelect, onInsert, onMove, onRemove, onDuplicate, onReorder }: Props ) {
	const style = resolveStyle( template, styleDefaults );
	const [ target, setTarget ] = useState< DropTarget | null >( null );

	// A drag that ends anywhere (dropped outside, or Escape) must not leave a stale insertion line behind.
	useEffect( () => {
		const clear = () => {
			setTarget( null );
			endDrag();
		};

		document.addEventListener( 'dragend', clear );
		return () => document.removeEventListener( 'dragend', clear );
	}, [] );

	const actions: CanvasActions = {
		selectedId,
		target,
		setTarget,
		canDropIn: ( address ) => {
			const drag = currentDrag();
			return null !== drag && canDropAt( drag.type, drag.blockId, address );
		},
		dropAt: ( address, index ) => {
			const drag = currentDrag();
			setTarget( null );
			endDrag();

			if ( ! drag || ! canDropAt( drag.type, drag.blockId, address ) ) {
				return;
			}

			if ( null === drag.blockId || null === drag.from ) {
				onInsert( address, index, drag.type );
			} else {
				onMove( drag.from.parentId, drag.from.column, drag.blockId, address, index );
			}
		},
		onSelect: ( id ) => onSelect( id ),
		onRemove,
		onDuplicate,
		onReorder,
	};

	const atEnd = 'end' === target?.key;

	return (
		<CanvasContext.Provider value={ actions }>
			<div className="pw-canvas-page" style={ { background: style.page_bg } } onClick={ () => onSelect( null ) }>
				<div
					className="pw-canvas-card"
					style={ { maxWidth: `${ style.width }px`, background: style.canvas } }
					// Anywhere on the card that is not a block (the header, the gap below the last
					// block, the footer) appends to the end of the email, so a drop is never lost.
					onDragOver={ ( e ) => {
						if ( ! actions.canDropIn( ROOT ) ) {
							return;
						}

						e.preventDefault();

						if ( ! atEnd ) {
							setTarget( { key: 'end', pos: 'inside' } );
						}
					} }
					onDragLeave={ ( e ) => {
						const next = e.relatedTarget as Node | null;

						if ( ! next || ! ( e.currentTarget as HTMLElement ).contains( next ) ) {
							setTarget( null );
						}
					} }
					onDrop={ ( e ) => {
						e.preventDefault();
						actions.dropAt( ROOT, template.blocks.length );
					} }
				>
					{ template.header.show_logo ? (
						<div className="pw-canvas-header" style={ { fontFamily: schema.fonts[ style.font ], color: style.brand } }>
							Your logo or store name
						</div>
					) : null }

					{ 0 === template.blocks.length ? (
						<p className={ `pw-canvas-empty${ atEnd ? ' is-over' : '' }` }>Drag a block here, or click one on the right to add it.</p>
					) : null }

					{ template.blocks.map( ( block, i ) => (
						<BlockView key={ block.id } block={ block } address={ ROOT } index={ i } count={ template.blocks.length } style={ style } fonts={ schema.fonts } />
					) ) }

					{ atEnd && template.blocks.length > 0 ? <div className="pw-drop-line" /> : null }

					<div className="pw-canvas-footer" style={ { color: style.muted, fontFamily: schema.fonts[ style.font ] } }>
						{ template.footer.text || ( template.footer.show_address ? 'Store name, address' : '' ) }
					</div>
				</div>
			</div>
		</CanvasContext.Provider>
	);
}
