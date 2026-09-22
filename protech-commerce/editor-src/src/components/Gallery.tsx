import { useEffect, useState } from 'preact/hooks';
import type { Starter, Template } from '../types';
import { api } from '../api';

interface Props {
	categoryLabels: Record< string, string >;
	onPick: ( template: Template ) => void;
	onBlank: () => void;
}

/**
 * Shown instead of a blank canvas when a brand-new template is opened: pick
 * a starting point, or skip straight to blank. Choosing one only fills the
 * (still unsaved) editor state, the same as typing -- nothing reaches the
 * server until Save.
 */
export function Gallery( { categoryLabels, onPick, onBlank }: Props ) {
	const [ starters, setStarters ] = useState< Starter[] | null >( null );
	const [ error, setError ] = useState( '' );
	const [ category, setCategory ] = useState( '' );
	const [ search, setSearch ] = useState( '' );

	useEffect( () => {
		api
			.starters()
			.then( setStarters )
			.catch( ( e: Error ) => setError( e.message ) );
	}, [] );

	const categories = Object.keys( categoryLabels ).filter( ( key ) => 'blank' !== key );

	const shown = ( starters ?? [] ).filter( ( starter ) => {
		if ( 'blank' === starter.category ) {
			return false;
		}

		if ( category && starter.category !== category ) {
			return false;
		}

		return '' === search || starter.name.toLowerCase().includes( search.toLowerCase() );
	} );

	return (
		<div className="pw-gallery">
			<h2>Start a new template</h2>
			<p className="pw-gallery-intro">Choose a starting point, or begin from a blank template.</p>

			<button type="button" className="button button-primary pw-gallery-blank" onClick={ onBlank }>
				Blank template
			</button>

			{ error ? <div className="notice notice-error inline"><p>{ error }</p></div> : null }

			{ starters && starters.length > 0 ? (
				<>
					<div className="pw-gallery-filters">
						<input
							type="search"
							className="pw-input"
							placeholder="Search starters…"
							value={ search }
							onInput={ ( e ) => setSearch( ( e.target as HTMLInputElement ).value ) }
						/>
						<select className="pw-input" value={ category } onChange={ ( e ) => setCategory( ( e.target as HTMLSelectElement ).value ) }>
							<option value="">All categories</option>
							{ categories.map( ( key ) => (
								<option key={ key } value={ key }>
									{ categoryLabels[ key ] }
								</option>
							) ) }
						</select>
					</div>

					<div className="pw-gallery-grid">
						{ shown.map( ( starter ) => (
							<button
								key={ starter.key }
								type="button"
								className="pw-gallery-tile"
								onClick={ () => onPick( starter.template ) }
							>
								<span className="pw-gallery-tile-name">{ starter.name }</span>
								<span className="pw-gallery-tile-category">{ categoryLabels[ starter.category ] ?? starter.category }</span>
							</button>
						) ) }
					</div>

					{ 0 === shown.length ? <p className="pw-gallery-empty">No starters match.</p> : null }
				</>
			) : null }
		</div>
	);
}
