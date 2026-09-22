import type { Template } from '../types';
import { SendPreview } from './SendPreview';

interface Props {
	/** The design as it stands, for Send preview. */
	template: Template;
	name: string;
	onNameChange: ( name: string ) => void;
	canUndo: boolean;
	canRedo: boolean;
	onUndo: () => void;
	onRedo: () => void;
	mode: 'edit' | 'preview';
	onModeChange: ( mode: 'edit' | 'preview' ) => void;
	previewWidth: 'desktop' | 'phone';
	onPreviewWidthChange: ( width: 'desktop' | 'phone' ) => void;
	saving: boolean;
	dirty: boolean;
	errors: string[];
	onSave: () => void;
	onBackToList: () => void;
}

export function Toolbar( {
	template,
	name,
	onNameChange,
	canUndo,
	canRedo,
	onUndo,
	onRedo,
	mode,
	onModeChange,
	previewWidth,
	onPreviewWidthChange,
	saving,
	dirty,
	errors,
	onSave,
	onBackToList,
}: Props ) {
	return (
		<div className="pw-toolbar">
			<button type="button" className="pc-link pw-toolbar-back" onClick={ onBackToList }>
				&larr; All templates
			</button>

			<input
				type="text"
				className="pw-input pw-template-name"
				value={ name }
				placeholder="Template name"
				onInput={ ( e ) => onNameChange( ( e.target as HTMLInputElement ).value ) }
			/>

			<span className="pw-toolbar-group">
				<button type="button" className="pc-btn" onClick={ onUndo } disabled={ ! canUndo } aria-label="Undo">
					Undo
				</button>
				<button type="button" className="pc-btn" onClick={ onRedo } disabled={ ! canRedo } aria-label="Redo">
					Redo
				</button>
			</span>

			<span className="pw-toolbar-group">
				<button type="button" className={ `pc-seg${ 'edit' === mode ? ' is-active' : '' }` } onClick={ () => onModeChange( 'edit' ) }>
					Edit
				</button>
				<button type="button" className={ `pc-seg${ 'preview' === mode ? ' is-active' : '' }` } onClick={ () => onModeChange( 'preview' ) }>
					Preview
				</button>
				{ 'preview' === mode ? (
					<>
						<button type="button" className={ `pc-seg${ 'desktop' === previewWidth ? ' is-active' : '' }` } onClick={ () => onPreviewWidthChange( 'desktop' ) }>
							Desktop
						</button>
						<button type="button" className={ `pc-seg${ 'phone' === previewWidth ? ' is-active' : '' }` } onClick={ () => onPreviewWidthChange( 'phone' ) }>
							Phone
						</button>
					</>
				) : null }
			</span>

			<SendPreview template={ template } />

			{ errors.length > 0 ? <span className="pw-toolbar-errors">{ errors.length } issue(s)</span> : null }

			<button type="button" className="pc-btn pc-btn--primary pw-save" onClick={ onSave } disabled={ saving }>
				{ saving ? 'Saving...' : dirty ? 'Save changes' : 'Saved' }
			</button>
		</div>
	);
}
