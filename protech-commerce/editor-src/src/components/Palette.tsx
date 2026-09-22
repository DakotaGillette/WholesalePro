import type { Schema } from '../types';

interface Props {
	schema: Schema;
	/** True inside a column: columns cannot contain columns. */
	nested: boolean;
	onAdd: ( type: string ) => void;
}

export function Palette( { schema, nested, onAdd }: Props ) {
	const types = Object.entries( schema.types ).filter( ( [ type ] ) => ! ( nested && 'columns' === type ) );

	return (
		<div className="pw-palette">
			{ types.map( ( [ type, def ] ) => (
				<button
					key={ type }
					type="button"
					className="pw-palette-tile"
					draggable
					onDragStart={ ( e ) => {
						e.dataTransfer?.setData( 'text/protech-block-type', type );
						e.dataTransfer!.effectAllowed = 'copy';
					} }
					onClick={ () => onAdd( type ) }
					title={ def.help }
				>
					{ def.label }
				</button>
			) ) }
		</div>
	);
}
