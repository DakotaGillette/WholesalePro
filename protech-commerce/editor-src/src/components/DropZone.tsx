import { useState } from 'preact/hooks';
import { DND_NEW_BLOCK_TYPE, DND_MOVE_BLOCK } from '../dnd';

interface Props {
	onDropBlockType: ( type: string ) => void;
	onDropMove: ( blockId: string, fromParentId: string | null, fromColumn: number ) => void;
}

/** A thin "insert here" gap between blocks (or the only slot in an empty column). Accepts a palette drag (new block) or an existing block being moved. */
export function DropZone( { onDropBlockType, onDropMove }: Props ) {
	const [ over, setOver ] = useState( false );

	return (
		<div
			className={ `pw-dropzone${ over ? ' is-over' : '' }` }
			onDragOver={ ( e ) => {
				e.preventDefault();
				setOver( true );
			} }
			onDragLeave={ () => setOver( false ) }
			onDrop={ ( e ) => {
				e.preventDefault();
				setOver( false );

				const newType = e.dataTransfer?.getData( DND_NEW_BLOCK_TYPE );
				const moveRaw = e.dataTransfer?.getData( DND_MOVE_BLOCK );

				if ( newType ) {
					onDropBlockType( newType );
					return;
				}

				if ( moveRaw ) {
					try {
						const { id, parentId, column } = JSON.parse( moveRaw );
						onDropMove( id, parentId, column );
					} catch {
						/* ignore malformed transfer data */
					}
				}
			} }
		/>
	);
}
