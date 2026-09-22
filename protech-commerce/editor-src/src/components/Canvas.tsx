import type { Schema, Template, TemplateStyle } from '../types';
import type { Address } from '../model/template';
import { ROOT } from '../model/template';
import { resolveStyle } from '../model/style';
import { BlockView } from './BlockView';
import { DropZone } from './DropZone';

interface Props {
	template: Template;
	schema: Schema;
	styleDefaults: TemplateStyle;
	selectedId: string | null;
	onSelect: ( id: string | null ) => void;
	onInsert: ( address: Address, index: number, type: string ) => void;
	onMove: ( fromParentId: string | null, fromColumn: number, blockId: string, toAddress: Address, toIndex: number ) => void;
}

export function Canvas( { template, schema, styleDefaults, selectedId, onSelect, onInsert, onMove }: Props ) {
	const style = resolveStyle( template, styleDefaults );

	return (
		<div className="pw-canvas-page" style={ { background: style.page_bg } } onClick={ () => onSelect( null ) }>
			<div className="pw-canvas-card" style={ { maxWidth: `${ style.width }px`, background: style.canvas } }>
				{ template.header.show_logo ? (
					<div className="pw-canvas-header" style={ { fontFamily: schema.fonts[ style.font ], color: style.brand } }>
						Your logo or store name
					</div>
				) : null }

				<DropZone onDropBlockType={ ( type ) => onInsert( ROOT, 0, type ) } onDropMove={ ( id, pId, c ) => onMove( pId, c, id, ROOT, 0 ) } />

				{ 0 === template.blocks.length ? (
					<p className="pw-canvas-empty">Drag a block from the right, or click one to add it here.</p>
				) : null }

				{ template.blocks.map( ( block, i ) => (
					<div key={ block.id }>
						<BlockView block={ block } address={ ROOT } style={ style } fonts={ schema.fonts } selectedId={ selectedId } onSelect={ onSelect } onInsert={ onInsert } onMove={ onMove } />
						<DropZone onDropBlockType={ ( type ) => onInsert( ROOT, i + 1, type ) } onDropMove={ ( id, pId, c ) => onMove( pId, c, id, ROOT, i + 1 ) } />
					</div>
				) ) }

				<div className="pw-canvas-footer" style={ { color: style.muted, fontFamily: schema.fonts[ style.font ] } }>
					{ template.footer.text || ( template.footer.show_address ? 'Store name, address' : '' ) }
				</div>
			</div>
		</div>
	);
}
