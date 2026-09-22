import { useEffect, useRef, useState } from 'preact/hooks';
import type { EditorBootstrap, Template } from '../types';
import { api } from '../api';
import { useDesignEditor } from './useDesignEditor';
import { DesignWorkspace } from './DesignWorkspace';
import { Toolbar } from './Toolbar';
import { Gallery } from './Gallery';
import { Notice } from './ui';

function draftKey( id: string ): string {
	return `protech-editor-draft-${ id || 'new' }`;
}

/** The template library's editor (Messaging -> Emails -> Templates -> Edit). */
export function App( { boot }: { boot: EditorBootstrap } ) {
	const initial = boot.template as Template;
	const [ mode, setMode ] = useState< 'edit' | 'preview' >( 'edit' );
	const [ previewWidth, setPreviewWidth ] = useState< 'desktop' | 'phone' >( 'desktop' );
	const [ saving, setSaving ] = useState( false );
	const [ saveErrors, setSaveErrors ] = useState< string[] >( [] );
	const [ dirty, setDirty ] = useState( false );
	const [ savedSnapshot, setSavedSnapshot ] = useState( initial );
	const [ draftOffer, setDraftOffer ] = useState< Template | null >( null );
	const [ showGallery, setShowGallery ] = useState( '' === initial.id && 0 === initial.blocks.length );

	const editor = useDesignEditor( initial, boot.schema, () => handleSave(), ! showGallery );
	const template = editor.template;

	// Offer to restore a local draft left over from before a reload, once, on mount.
	useEffect( () => {
		try {
			const raw = window.localStorage.getItem( draftKey( initial.id ) );

			if ( raw ) {
				const draft = JSON.parse( raw ) as Template;

				if ( JSON.stringify( draft ) !== JSON.stringify( initial ) ) {
					setDraftOffer( draft );
					setShowGallery( false );
				}
			}
		} catch {
			/* localStorage can throw in a private window; a missed draft offer is not worth failing the page over. */
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// Autosave to localStorage only, debounced: never the server, so a half-typed
	// template can never become what an automation or campaign sends.
	const autosaveTimer = useRef< number | undefined >();
	useEffect( () => {
		setDirty( JSON.stringify( template ) !== JSON.stringify( savedSnapshot ) );

		window.clearTimeout( autosaveTimer.current );
		autosaveTimer.current = window.setTimeout( () => {
			try {
				window.localStorage.setItem( draftKey( template.id ), JSON.stringify( template ) );
			} catch {
				/* best effort */
			}
		}, 1000 );

		return () => window.clearTimeout( autosaveTimer.current );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ JSON.stringify( template ) ] );

	useEffect( () => {
		const warnBeforeUnload = ( e: BeforeUnloadEvent ) => {
			if ( dirty ) {
				e.preventDefault();
				e.returnValue = '';
			}
		};

		window.addEventListener( 'beforeunload', warnBeforeUnload );
		return () => window.removeEventListener( 'beforeunload', warnBeforeUnload );
	}, [ dirty ] );

	function handleSave(): void {
		setSaving( true );

		api
			.save( template )
			.then( ( result ) => {
				setSaveErrors( result.errors );

				if ( 0 === result.errors.length ) {
					editor.replacePresent( result.template );
					setSavedSnapshot( result.template );

					try {
						window.localStorage.removeItem( draftKey( template.id ) );
					} catch {
						/* best effort */
					}

					if ( ! template.id && result.template.id ) {
						// A brand-new template just got its real id: swap the URL so a
						// reload (or the browser back button) lands on the saved one.
						const url = new URL( window.location.href );
						url.searchParams.set( 'edit', result.template.id );
						window.history.replaceState( {}, '', url.toString() );
					}
				}
			} )
			.catch( ( e: Error ) => setSaveErrors( [ e.message ] ) )
			.finally( () => setSaving( false ) );
	}

	if ( showGallery ) {
		return (
			<div className="pw-app pc-app">
				<Gallery
					categoryLabels={ boot.categoryLabels }
					onBlank={ () => setShowGallery( false ) }
					onPick={ ( picked ) => {
						editor.reset( picked );
						setShowGallery( false );
					} }
				/>
			</div>
		);
	}

	return (
		<div className="pw-app pc-app">
			<Toolbar
				name={ template.name }
				onNameChange={ ( name ) => editor.change( { name } ) }
				canUndo={ editor.canUndo }
				canRedo={ editor.canRedo }
				onUndo={ editor.undo }
				onRedo={ editor.redo }
				mode={ mode }
				onModeChange={ setMode }
				previewWidth={ previewWidth }
				onPreviewWidthChange={ setPreviewWidth }
				saving={ saving }
				dirty={ dirty }
				errors={ saveErrors }
				onSave={ handleSave }
				onBackToList={ () => {
					window.location.href = boot.urls.list ?? '';
				} }
			/>

			{ draftOffer ? (
				<Notice tone="warning">
					You have unsaved changes from a previous visit.{ ' ' }
					<button
						type="button"
						className="pc-link"
						onClick={ () => {
							editor.reset( draftOffer );
							setDraftOffer( null );
						} }
					>
						Restore them
					</button>{ ' ' }
					or{ ' ' }
					<button
						type="button"
						className="pc-link"
						onClick={ () => {
							try {
								window.localStorage.removeItem( draftKey( initial.id ) );
							} catch {
								/* best effort */
							}
							setDraftOffer( null );
						} }
					>
						discard them
					</button>
					.
				</Notice>
			) : null }

			{ saveErrors.length > 0 ? (
				<Notice tone="error">
					<ul className="pc-list">
						{ saveErrors.map( ( err, i ) => (
							<li key={ i }>{ err }</li>
						) ) }
					</ul>
				</Notice>
			) : null }

			<DesignWorkspace boot={ boot } editor={ editor } mode={ mode } previewWidth={ previewWidth } variant="template" />
		</div>
	);
}
