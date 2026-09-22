interface Props {
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
			<button type="button" className="button-link pw-toolbar-back" onClick={ onBackToList }>
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
				<button type="button" className="button" onClick={ onUndo } disabled={ ! canUndo } aria-label="Undo">
					Undo
				</button>
				<button type="button" className="button" onClick={ onRedo } disabled={ ! canRedo } aria-label="Redo">
					Redo
				</button>
			</span>

			<span className="pw-toolbar-group">
				<button type="button" className={ `button${ 'edit' === mode ? ' button-primary' : '' }` } onClick={ () => onModeChange( 'edit' ) }>
					Edit
				</button>
				<button type="button" className={ `button${ 'preview' === mode ? ' button-primary' : '' }` } onClick={ () => onModeChange( 'preview' ) }>
					Preview
				</button>
				{ 'preview' === mode ? (
					<>
						<button type="button" className={ `button${ 'desktop' === previewWidth ? ' button-primary' : '' }` } onClick={ () => onPreviewWidthChange( 'desktop' ) }>
							Desktop
						</button>
						<button type="button" className={ `button${ 'phone' === previewWidth ? ' button-primary' : '' }` } onClick={ () => onPreviewWidthChange( 'phone' ) }>
							Phone
						</button>
					</>
				) : null }
			</span>

			{ errors.length > 0 ? <span className="pw-toolbar-errors">{ errors.length } issue(s)</span> : null }

			<button type="button" className="button button-primary pw-save" onClick={ onSave } disabled={ saving }>
				{ saving ? 'Saving...' : dirty ? 'Save changes' : 'Saved' }
			</button>
		</div>
	);
}
