import { useEffect, useMemo, useRef, useState } from 'preact/hooks';
import type { EditorBootstrap, Template } from '../types';
import { canRedo, canUndo, History, initHistory, push, redo, replace, undo } from '../model/history';
import { Address, countBlocks, duplicateBlock, findBlock, listAt, makeBlock, moveBlock as moveBlockModel, removeBlock, ROOT, insertBlock, updateBlockAttrs } from '../model/template';
import { api } from '../api';
import { Canvas } from './Canvas';
import { TruePreview } from './TruePreview';
import { Inspector } from './Inspector';
import { Palette } from './Palette';
import { SettingsPanels } from './SettingsPanels';
import { SendTest } from './SendTest';
import { Toolbar } from './Toolbar';
import { Gallery } from './Gallery';

function draftKey( id: string ): string {
	return `protech-editor-draft-${ id || 'new' }`;
}

export function App( { boot }: { boot: EditorBootstrap } ) {
	const [ history, setHistory ] = useState< History< Template > >( () => initHistory( boot.template ) );
	const [ selectedId, setSelectedId ] = useState< string | null >( null );
	const [ mode, setMode ] = useState< 'edit' | 'preview' >( 'edit' );
	const [ previewWidth, setPreviewWidth ] = useState< 'desktop' | 'phone' >( 'desktop' );
	const [ saving, setSaving ] = useState( false );
	const [ saveErrors, setSaveErrors ] = useState< string[] >( [] );
	const [ dirty, setDirty ] = useState( false );
	const [ savedSnapshot, setSavedSnapshot ] = useState( boot.template );
	const [ draftOffer, setDraftOffer ] = useState< Template | null >( null );
	const [ showGallery, setShowGallery ] = useState( '' === boot.template.id && 0 === boot.template.blocks.length );

	const template = history.present;

	// Offer to restore a local draft left over from before a reload, once, on mount.
	useEffect( () => {
		try {
			const raw = window.localStorage.getItem( draftKey( boot.template.id ) );

			if ( raw ) {
				const draft = JSON.parse( raw ) as Template;

				if ( JSON.stringify( draft ) !== JSON.stringify( boot.template ) ) {
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

	// Keyboard: Ctrl/Cmd+Z undo, Shift+Ctrl/Cmd+Z redo, Ctrl/Cmd+S save,
	// Delete/Backspace removes the selected block -- but never while a
	// field has focus, where those keys mean what they always mean.
	useEffect( () => {
		const onKeyDown = ( e: KeyboardEvent ) => {
			const mod = e.ctrlKey || e.metaKey;
			const target = e.target as HTMLElement | null;
			const inField = !! target && [ 'INPUT', 'TEXTAREA', 'SELECT' ].includes( target.tagName );

			if ( mod && 'z' === e.key.toLowerCase() && ! e.shiftKey ) {
				e.preventDefault();
				setHistory( ( h ) => undo( h ) );
			} else if ( mod && ( ( 'z' === e.key.toLowerCase() && e.shiftKey ) || 'y' === e.key.toLowerCase() ) ) {
				e.preventDefault();
				setHistory( ( h ) => redo( h ) );
			} else if ( mod && 's' === e.key.toLowerCase() ) {
				e.preventDefault();
				handleSave();
			} else if ( ( 'Delete' === e.key || 'Backspace' === e.key ) && selectedId && ! inField ) {
				e.preventDefault();
				handleRemove( selectedId );
			}
		};

		window.addEventListener( 'keydown', onKeyDown );
		return () => window.removeEventListener( 'keydown', onKeyDown );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ selectedId, template ] );

	const selected = useMemo( () => ( selectedId ? findBlock( template, selectedId ) : null ), [ template, selectedId ] );

	function apply( next: Template ): void {
		setHistory( ( h ) => push( h, next ) );
	}

	function handleInsert( address: Address, index: number, type: string ): void {
		if ( countBlocks( template.blocks ) >= boot.schema.limits.max_blocks ) {
			window.alert( `A template can hold at most ${ boot.schema.limits.max_blocks } blocks.` );
			return;
		}

		const block = makeBlock( boot.schema, type );

		if ( ! block ) {
			return;
		}

		apply( insertBlock( template, address, index, block ) );
		setSelectedId( block.id );
	}

	function handleMove( fromParentId: string | null, fromColumn: number, blockId: string, toAddress: Address, toIndex: number ): void {
		apply( moveBlockModel( template, { parentId: fromParentId, column: fromColumn }, blockId, toAddress, toIndex ) );
	}

	function handleAttrsChange( patch: Record< string, unknown > ): void {
		if ( ! selected ) {
			return;
		}

		apply( updateBlockAttrs( template, selected.address, selected.block.id, patch ) );
	}

	function handleRemove( id: string ): void {
		const found = findBlock( template, id );

		if ( ! found ) {
			return;
		}

		apply( removeBlock( template, found.address, id ) );
		setSelectedId( null );
	}

	function handleDuplicate( id: string ): void {
		const found = findBlock( template, id );

		if ( ! found ) {
			return;
		}

		apply( duplicateBlock( template, found.address, id ) );
	}

	function handleReorder( id: string, direction: -1 | 1 ): void {
		const found = findBlock( template, id );

		if ( ! found ) {
			return;
		}

		const list = listAt( template, found.address );
		const idx = list.findIndex( ( b ) => b.id === id );

		if ( -1 === idx || ( -1 === direction && idx <= 0 ) || ( 1 === direction && idx >= list.length - 1 ) ) {
			return;
		}

		const toIndex = -1 === direction ? idx - 1 : idx + 2;
		apply( moveBlockModel( template, found.address, id, found.address, toIndex ) );
	}

	function handleTemplateChange( patch: Partial< Template > ): void {
		apply( { ...template, ...patch } );
	}

	function handleSave(): void {
		setSaving( true );

		api
			.save( template )
			.then( ( result ) => {
				setSaveErrors( result.errors );

				if ( 0 === result.errors.length ) {
					setHistory( ( h ) => replace( h, result.template ) );
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
			<div className="pw-app">
				<Gallery
					categoryLabels={ boot.categoryLabels }
					onBlank={ () => setShowGallery( false ) }
					onPick={ ( picked ) => {
						setHistory( initHistory( picked ) );
						setShowGallery( false );
					} }
				/>
			</div>
		);
	}

	return (
		<div className="pw-app">
			<Toolbar
				name={ template.name }
				onNameChange={ ( name ) => handleTemplateChange( { name } ) }
				canUndo={ canUndo( history ) }
				canRedo={ canRedo( history ) }
				onUndo={ () => setHistory( undo ) }
				onRedo={ () => setHistory( redo ) }
				mode={ mode }
				onModeChange={ setMode }
				previewWidth={ previewWidth }
				onPreviewWidthChange={ setPreviewWidth }
				saving={ saving }
				dirty={ dirty }
				errors={ saveErrors }
				onSave={ handleSave }
				onBackToList={ () => {
					window.location.href = boot.urls.list;
				} }
			/>

			{ draftOffer ? (
				<div className="notice notice-warning inline pw-draft-notice">
					<p>
						You have unsaved changes from a previous visit.{ ' ' }
						<button
							type="button"
							className="button-link"
							onClick={ () => {
								setHistory( initHistory( draftOffer ) );
								setDraftOffer( null );
							} }
						>
							Restore
						</button>{ ' ' }
						or{ ' ' }
						<button
							type="button"
							className="button-link"
							onClick={ () => {
								try {
									window.localStorage.removeItem( draftKey( boot.template.id ) );
								} catch {
									/* best effort */
								}
								setDraftOffer( null );
							} }
						>
							discard
						</button>
						.
					</p>
				</div>
			) : null }

			{ saveErrors.length > 0 ? (
				<div className="notice notice-error inline">
					<ul>
						{ saveErrors.map( ( err, i ) => (
							<li key={ i }>{ err }</li>
						) ) }
					</ul>
				</div>
			) : null }

			<div className="pw-layout">
				<div className="pw-canvas-wrap">
					{ 'edit' === mode ? (
						<Canvas
							template={ template }
							schema={ boot.schema }
							styleDefaults={ boot.styleDefaults }
							selectedId={ selectedId }
							onSelect={ setSelectedId }
							onInsert={ handleInsert }
							onMove={ handleMove }
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
							onChange={ handleAttrsChange }
							onBack={ () => setSelectedId( null ) }
							onMoveUp={ () => handleReorder( selected.block.id, -1 ) }
							onMoveDown={ () => handleReorder( selected.block.id, 1 ) }
							onDuplicate={ () => handleDuplicate( selected.block.id ) }
							onRemove={ () => handleRemove( selected.block.id ) }
						/>
					) : (
						<>
							<div className="pw-panel-title">Add a block</div>
							<Palette schema={ boot.schema } nested={ false } onAdd={ ( type ) => handleInsert( ROOT, template.blocks.length, type ) } />
							<SendTest template={ template } />
							<SettingsPanels template={ template } schema={ boot.schema } slots={ boot.slots } mergeTags={ boot.mergeTags } onChange={ handleTemplateChange } />
						</>
					) }
				</div>
			</div>
		</div>
	);
}
