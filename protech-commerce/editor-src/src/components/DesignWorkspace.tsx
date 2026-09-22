import type { EditorBootstrap } from '../types';
import { ROOT } from '../model/template';
import type { DesignEditor } from './useDesignEditor';
import { Canvas } from './Canvas';
import { TruePreview } from './TruePreview';
import { Inspector } from './Inspector';
import { Palette } from './Palette';
import { SettingsPanels } from './SettingsPanels';

interface Props {
	boot: EditorBootstrap;
	editor: DesignEditor;
	mode: 'edit' | 'preview';
	previewWidth: 'desktop' | 'phone';
	/** An email's own design has no type or "sent automatically as" panels: the Send step decides those. */
	variant: 'template' | 'email';
}

/** The canvas (or the true preview) and the sidebar: blocks to add, a selected block's settings, and the design panels. */
export function DesignWorkspace( { boot, editor, mode, previewWidth, variant }: Props ) {
	const { template, selected } = editor;

	return (
		<div className="pw-layout">
			<div className="pw-canvas-wrap">
				{ 'edit' === mode ? (
					<Canvas
						template={ template }
						schema={ boot.schema }
						styleDefaults={ boot.styleDefaults }
						selectedId={ editor.selectedId }
						onSelect={ editor.setSelectedId }
						onInsert={ editor.insert }
						onMove={ editor.move }
						onRemove={ editor.remove }
						onDuplicate={ editor.duplicate }
						onReorder={ editor.reorder }
					/>
				) : (
					<TruePreview template={ template } width={ previewWidth } />
				) }
			</div>

			<div className="pw-sidebar">
				{ selected ? (
					<Inspector
						template={ template }
						schema={ boot.schema }
						selectedType={ selected.block.type }
						attrs={ selected.block.attrs }
						mergeTags={ boot.mergeTags }
						onChange={ editor.changeAttrs }
						onBack={ () => editor.setSelectedId( null ) }
						onMoveUp={ () => editor.reorder( selected.block.id, -1 ) }
						onMoveDown={ () => editor.reorder( selected.block.id, 1 ) }
						onDuplicate={ () => editor.duplicate( selected.block.id ) }
						onRemove={ () => editor.remove( selected.block.id ) }
					/>
				) : (
					<>
						<div className="pw-panel-title">Content</div>
						<Palette schema={ boot.schema } nested={ false } canUseHtml={ boot.caps.unfiltered_html } onAdd={ ( type ) => editor.insert( ROOT, template.blocks.length, type ) } />
						<SettingsPanels template={ template } schema={ boot.schema } slots={ boot.slots ?? {} } mergeTags={ boot.mergeTags } onChange={ editor.change } variant={ variant } />
					</>
				) }
			</div>
		</div>
	);
}
