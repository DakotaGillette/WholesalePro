import type { Schema } from '../types';
import { DND_NEW_BLOCK_TYPE, endDrag, startDrag } from '../dnd';

interface Props {
	schema: Schema;
	/** True inside a column: columns cannot contain columns. */
	nested: boolean;
	/** False hides the Custom HTML tile: saving one without it is refused anyway. */
	canUseHtml: boolean;
	onAdd: ( type: string ) => void;
}

export function Palette( { schema, nested, canUseHtml, onAdd }: Props ) {
	const types = Object.entries( schema.types ).filter( ( [ type ] ) => ! ( nested && 'columns' === type ) && ( canUseHtml || 'html' !== type ) );

	return (
		<div className="pw-palette">
			{ types.map( ( [ type, def ] ) => (
				<button
					key={ type }
					type="button"
					className="pw-palette-tile"
					draggable
					onDragStart={ ( e ) => {
						e.dataTransfer?.setData( DND_NEW_BLOCK_TYPE, type );
						e.dataTransfer!.effectAllowed = 'copy';
						startDrag( { type, blockId: null, from: null } );
					} }
					onDragEnd={ endDrag }
					onClick={ () => onAdd( type ) }
					title={ def.help }
				>
					{ def.label }
				</button>
			) ) }
		</div>
	);
}
