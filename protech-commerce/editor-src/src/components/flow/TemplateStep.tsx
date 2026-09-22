import { useEffect, useState } from 'preact/hooks';
import type { EditorBootstrap, EmailPayload, GalleryData } from '../../types';
import { api } from '../../api';
import { Notice } from '../ui';
import { Thumb } from './Thumb';

type Tab = 'starters' | 'templates' | 'recent';

interface Card {
	source: 'starter' | 'template' | 'campaign';
	ref: string;
	name: string;
	meta: string;
}

/** Step 2: pick what the email starts from. Picking one makes the draft (a copy of it) and opens Design. */
export function TemplateStep( { boot, onCreated }: { boot: EditorBootstrap; onCreated: ( created: EmailPayload ) => void } ) {
	const [ gallery, setGallery ] = useState< GalleryData | null >( null );
	const [ tab, setTab ] = useState< Tab >( 'starters' );
	const [ category, setCategory ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		api.emails
			.gallery()
			.then( setGallery )
			.catch( ( e: Error ) => setError( e.message ) );
	}, [] );

	const pick = ( card: Card ) => {
		if ( busy ) {
			return;
		}

		setBusy( `${ card.source }:${ card.ref }` );
		setError( '' );

		api.emails
			.create( card.source, card.ref, boot.presetAudience )
			.then( onCreated )
			.catch( ( e: Error ) => {
				setError( e.message );
				setBusy( '' );
			} );
	};

	if ( ! gallery ) {
		return (
			<div className="pc-page">
				<h2 className="pc-page-title">Choose a template</h2>
				{ error ? <Notice tone="error">{ error }</Notice> : <p className="pc-help">Loading templates...</p> }
			</div>
		);
	}

	const labels = gallery.labels;
	const tabs: Array< { key: Tab; label: string; count: number } > = [
		{ key: 'starters', label: 'Starters', count: gallery.starters.length },
		{ key: 'templates', label: 'Your templates', count: gallery.templates.length },
		{ key: 'recent', label: 'Recently sent', count: gallery.recent.length },
	];

	let cards: Card[] = [];

	if ( 'starters' === tab ) {
		// Blank first, then the rest in their order.
		cards = [ ...gallery.starters ]
			.sort( ( a, b ) => Number( 'blank' !== a.category ) - Number( 'blank' !== b.category ) )
			.filter( ( s ) => '' === category || s.category === category || 'blank' === s.category )
			.map( ( s ) => ( { source: 'starter', ref: s.key, name: s.name, meta: labels[ s.category ] ?? '' } ) );
	} else if ( 'templates' === tab ) {
		cards = gallery.templates.map( ( t ) => ( { source: 'template', ref: t.id, name: t.name, meta: labels[ t.category ] ?? 'Your template' } ) );
	} else {
		cards = gallery.recent.map( ( c ) => ( { source: 'campaign', ref: c.id, name: c.name, meta: `Sent ${ new Date( c.sent_at * 1000 ).toLocaleDateString() }` } ) );
	}

	const starterCategories = Array.from( new Set( gallery.starters.map( ( s ) => s.category ).filter( ( c ) => 'blank' !== c ) ) );

	return (
		<div className="pc-page">
			<h2 className="pc-page-title">Choose a template</h2>
			<p className="pc-help pc-page-intro">Your email gets its own copy, so changing it never changes the template.</p>

			<div className="pc-tabs" role="tablist" aria-label="Template sources">
				{ tabs.map( ( t ) => (
					<button
						key={ t.key }
						type="button"
						role="tab"
						aria-selected={ tab === t.key }
						className={ `pc-tab${ tab === t.key ? ' is-active' : '' }` }
						onClick={ () => setTab( t.key ) }
					>
						{ t.label } <span className="pc-count">{ t.count }</span>
					</button>
				) ) }
			</div>

			{ 'starters' === tab && starterCategories.length > 1 ? (
				<div className="pc-chips" role="group" aria-label="Filter by category">
					{ [ '', ...starterCategories ].map( ( c ) => (
						<button key={ c || 'all' } type="button" className={ `pc-chip${ category === c ? ' is-active' : '' }` } aria-pressed={ category === c } onClick={ () => setCategory( c ) }>
							{ '' === c ? 'All' : labels[ c ] ?? c }
						</button>
					) ) }
				</div>
			) : null }

			{ error ? <Notice tone="error">{ error }</Notice> : null }

			{ 0 === cards.length ? (
				<p className="pc-empty">
					{ 'templates' === tab ? 'No saved templates yet. Templates you save from the library show up here.' : 'Nothing sent yet. Emails you send show up here to start from again.' }
				</p>
			) : (
				<div className="pc-template-grid">
					{ cards.map( ( card ) => {
						const key = `${ card.source }:${ card.ref }`;

						return (
							<button key={ key } type="button" className={ `pc-template-card${ busy === key ? ' is-busy' : '' }` } onClick={ () => pick( card ) } disabled={ '' !== busy }>
								<Thumb source={ key } />
								<span className="pc-template-name">{ card.name }</span>
								<span className="pc-template-meta">{ busy === key ? 'Opening...' : card.meta }</span>
							</button>
						);
					} ) }
				</div>
			) }
		</div>
	);
}
