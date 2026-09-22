import { useEffect, useRef, useState } from 'preact/hooks';
import type { EditorBootstrap, EmailPayload, Template } from '../../types';
import { api } from '../../api';
import { useDesignEditor } from '../useDesignEditor';
import { DesignWorkspace } from '../DesignWorkspace';
import { TagPicker } from '../TagPicker';
import { Icon } from '../icons';
import { Notice } from '../ui';
import { SendPreview } from '../SendPreview';
import { ConfirmModal } from './ConfirmModal';

interface Props {
	boot: EditorBootstrap;
	payload: EmailPayload;
	onChange: ( payload: EmailPayload ) => void;
	onNext: () => void;
}

const AUTOSAVE_MS = 2500;

/**
 * Step 3: design the email. The subject and preview text sit above the
 * canvas, as in MailPoet; everything else is the same editor the template
 * library uses. A draft saves itself a moment after each change (it is a
 * draft, so nothing half-finished can go out), and Next saves first.
 */
export function DesignStep( { boot, payload, onChange, onNext }: Props ) {
	const initial = payload.design as Template;
	const [ mode, setMode ] = useState< 'edit' | 'preview' >( 'edit' );
	const [ previewWidth, setPreviewWidth ] = useState< 'desktop' | 'phone' >( 'desktop' );
	const [ saving, setSaving ] = useState( false );
	const [ saved, setSaved ] = useState( JSON.stringify( initial ) );
	const [ warnings, setWarnings ] = useState< string[] >( payload.errors );
	const [ failure, setFailure ] = useState( '' );
	const [ menuOpen, setMenuOpen ] = useState( false );
	const [ naming, setNaming ] = useState( false );
	const [ templateName, setTemplateName ] = useState( '' );
	const [ savingTemplate, setSavingTemplate ] = useState( false );
	const [ templateError, setTemplateError ] = useState( '' );
	const [ savedTemplate, setSavedTemplate ] = useState( '' );
	const menuWrap = useRef< HTMLSpanElement | null >( null );

	// The Save menu closes on Escape or a click elsewhere.
	useEffect( () => {
		if ( ! menuOpen ) {
			return undefined;
		}

		const onKey = ( e: KeyboardEvent ) => 'Escape' === e.key && setMenuOpen( false );
		const onClick = ( e: MouseEvent ) => {
			if ( menuWrap.current && ! menuWrap.current.contains( e.target as Node ) ) {
				setMenuOpen( false );
			}
		};

		document.addEventListener( 'keydown', onKey );
		document.addEventListener( 'mousedown', onClick );

		return () => {
			document.removeEventListener( 'keydown', onKey );
			document.removeEventListener( 'mousedown', onClick );
		};
	}, [ menuOpen ] );
	const subjectRef = useRef< HTMLInputElement | null >( null );
	const preheaderRef = useRef< HTMLInputElement | null >( null );

	const editor = useDesignEditor( initial, boot.schema, () => save() );
	const template = editor.template;
	const snapshot = JSON.stringify( template );
	const dirty = snapshot !== saved;

	const save = (): Promise< boolean > => {
		const sending = JSON.stringify( template );
		setSaving( true );

		return api.emails
			.update( payload.email.id, { design: template } )
			.then( ( result ) => {
				setSaved( sending );
				setWarnings( result.errors );
				setFailure( '' );
				onChange( { ...result, design: template } );
				return true;
			} )
			.catch( ( e: Error ) => {
				setFailure( e.message );
				return false;
			} )
			.finally( () => setSaving( false ) );
	};

	// Save a moment after the last change.
	const timer = useRef< number | undefined >();
	useEffect( () => {
		if ( ! dirty ) {
			return undefined;
		}

		window.clearTimeout( timer.current );
		timer.current = window.setTimeout( () => void save(), AUTOSAVE_MS );

		return () => window.clearTimeout( timer.current );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ snapshot ] );

	useEffect( () => {
		const warn = ( e: BeforeUnloadEvent ) => {
			if ( dirty ) {
				e.preventDefault();
				e.returnValue = '';
			}
		};

		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );

	/** Saves the email first (the template copies what is saved), then the copy. */
	const saveAsTemplate = () => {
		if ( savingTemplate ) {
			return;
		}

		setSavingTemplate( true );
		setTemplateError( '' );

		void ( dirty ? save() : Promise.resolve( true ) )
			.then( ( ok ) => ( ok ? api.emails.saveAsTemplate( payload.email.id, templateName ) : Promise.reject( new Error( 'Could not save the email first.' ) ) ) )
			.then( ( result ) => {
				if ( result.errors?.length ) {
					setTemplateError( result.errors.join( ' ' ) );
					return;
				}

				setSavedTemplate( result.name );
				setNaming( false );
			} )
			.catch( ( e: Error ) => setTemplateError( e.message ) )
			.finally( () => setSavingTemplate( false ) );
	};

	const next = () => {
		window.clearTimeout( timer.current );
		void ( dirty ? save() : Promise.resolve( true ) ).then( ( ok ) => ok && onNext() );
	};

	const status = saving ? 'Saving...' : failure ? 'Not saved' : dirty ? 'Unsaved changes' : 'Draft saved';

	return (
		<div className="pc-app pw-app pc-design">
			<div className="pc-design-bar">
				<div className="pc-design-fields">
					<div className="pc-field pc-field--subject">
						<label className="pc-label" htmlFor="pc-subject">
							Subject
						</label>
						<input
							id="pc-subject"
							ref={ subjectRef }
							type="text"
							className="pc-input pc-input--lg"
							value={ template.subject }
							placeholder="What your customers see in their inbox"
							onInput={ ( e ) => editor.change( { subject: ( e.target as HTMLInputElement ).value } ) }
						/>
						<TagPicker mergeTags={ boot.mergeTags } value={ template.subject } elRef={ subjectRef } onChange={ ( subject ) => editor.change( { subject } ) } />
					</div>
					<div className="pc-field">
						<label className="pc-label" htmlFor="pc-preheader">
							Preview text
						</label>
						<input
							id="pc-preheader"
							ref={ preheaderRef }
							type="text"
							className="pc-input"
							value={ template.preheader }
							placeholder="The line shown after the subject in most inboxes"
							onInput={ ( e ) => editor.change( { preheader: ( e.target as HTMLInputElement ).value } ) }
						/>
					</div>
				</div>

				<div className="pc-design-actions">
					<div className="pc-design-tools">
						<button type="button" className="pc-icon-btn" onClick={ editor.undo } disabled={ ! editor.canUndo } aria-label="Undo" title="Undo">
							<Icon name="undo" size={ 18 } />
						</button>
						<button type="button" className="pc-icon-btn" onClick={ editor.redo } disabled={ ! editor.canRedo } aria-label="Redo" title="Redo">
							<Icon name="redo" size={ 18 } />
						</button>
						<span className="pc-segmented" role="group" aria-label="View">
							<button type="button" className={ `pc-seg${ 'edit' === mode ? ' is-active' : '' }` } aria-pressed={ 'edit' === mode } onClick={ () => setMode( 'edit' ) }>
								Edit
							</button>
							<button type="button" className={ `pc-seg${ 'preview' === mode ? ' is-active' : '' }` } aria-pressed={ 'preview' === mode } onClick={ () => setMode( 'preview' ) }>
								Preview
							</button>
						</span>
						{ 'preview' === mode ? (
							<span className="pc-segmented" role="group" aria-label="Preview width">
								<button type="button" className={ `pc-seg${ 'desktop' === previewWidth ? ' is-active' : '' }` } aria-pressed={ 'desktop' === previewWidth } onClick={ () => setPreviewWidth( 'desktop' ) }>
									Desktop
								</button>
								<button type="button" className={ `pc-seg${ 'phone' === previewWidth ? ' is-active' : '' }` } aria-pressed={ 'phone' === previewWidth } onClick={ () => setPreviewWidth( 'phone' ) }>
									Phone
								</button>
							</span>
						) : null }
					</div>

					<div className="pc-design-save">
						<span className={ `pc-save-status${ failure ? ' is-error' : '' }` } aria-live="polite">
							{ status }
						</span>
						<SendPreview template={ template } />
						<span className="pc-split">
							<button type="button" className="pc-btn pc-split-main" onClick={ () => void save() } disabled={ saving || ! dirty }>
								Save
							</button>
							<span className="pc-popover-wrap" ref={ menuWrap }>
								<button
									type="button"
									className="pc-btn pc-split-toggle"
									aria-label="More save options"
									aria-haspopup="menu"
									aria-expanded={ menuOpen }
									onClick={ () => setMenuOpen( ! menuOpen ) }
								>
									<Icon name="chevronDown" size={ 16 } />
								</button>
								{ menuOpen ? (
									<div className="pc-menu" role="menu">
										<button
											type="button"
											role="menuitem"
											className="pc-menu-item"
											onClick={ () => {
												setMenuOpen( false );
												setTemplateName( payload.email.name );
												setNaming( true );
											} }
										>
											Save as template
										</button>
									</div>
								) : null }
							</span>
						</span>
						<button type="button" className="pc-btn pc-btn--primary" onClick={ next } disabled={ saving }>
							Next
							<Icon name="arrowRight" size={ 16 } />
						</button>
					</div>
				</div>
			</div>

			{ failure ? <Notice tone="error">Could not save: { failure }</Notice> : null }

			{ savedTemplate ? (
				<Notice tone="success">
					Saved to your templates as <strong>{ savedTemplate }</strong>. It shows under Your templates next time you start an email.
				</Notice>
			) : null }

			{ naming ? (
				<ConfirmModal
					title="Save as template"
					confirmLabel="Save template"
					busyLabel="Saving..."
					busy={ savingTemplate }
					onCancel={ () => setNaming( false ) }
					onConfirm={ saveAsTemplate }
				>
					<p className="pc-help">A copy of this design goes into your templates, to start other emails from. This email is not changed.</p>
					<div className="pc-field">
						<label className="pc-label" htmlFor="pc-template-name">
							Template name
						</label>
						<input
							id="pc-template-name"
							type="text"
							className="pc-input"
							value={ templateName }
							onInput={ ( e ) => setTemplateName( ( e.target as HTMLInputElement ).value ) }
							onKeyDown={ ( e ) => 'Enter' === e.key && saveAsTemplate() }
						/>
					</div>
					{ templateError ? <Notice tone="error">{ templateError }</Notice> : null }
				</ConfirmModal>
			) : null }

			{ warnings.length > 0 ? (
				<Notice tone="warning">
					<strong>Fix before sending:</strong>
					<ul className="pc-list">
						{ warnings.map( ( w, i ) => (
							<li key={ i }>{ w }</li>
						) ) }
					</ul>
				</Notice>
			) : null }

			<DesignWorkspace boot={ boot } editor={ editor } mode={ mode } previewWidth={ previewWidth } variant="email" />
		</div>
	);
}
