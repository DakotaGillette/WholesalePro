import { useEffect, useRef, useState } from 'preact/hooks';
import { api, ProductHit } from '../../api';

interface Props {
	id: string;
	value: number;
	onChange: ( id: number ) => void;
}

/** Type part of a product's name, pick it from the list. Shows the chosen product's name, not its ID. */
export function ProductSearch( { id, value, onChange }: Props ) {
	const [ query, setQuery ] = useState( '' );
	const [ hits, setHits ] = useState< ProductHit[] >( [] );
	const [ chosen, setChosen ] = useState< ProductHit | null >( null );
	const [ open, setOpen ] = useState( false );
	const timer = useRef< number | undefined >();

	// The name of a product chosen earlier (a saved draft).
	useEffect( () => {
		if ( value > 0 && chosen?.id !== value ) {
			api.product( value )
				.then( ( r ) => setChosen( r[ 0 ] ?? null ) )
				.catch( () => setChosen( null ) );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ value ] );

	useEffect( () => {
		window.clearTimeout( timer.current );

		if ( query.trim().length < 2 ) {
			setHits( [] );
			return undefined;
		}

		timer.current = window.setTimeout( () => {
			api.products( query )
				.then( ( r ) => {
					setHits( r );
					setOpen( true );
				} )
				.catch( () => setHits( [] ) );
		}, 300 );

		return () => window.clearTimeout( timer.current );
	}, [ query ] );

	if ( chosen && value === chosen.id ) {
		return (
			<div className="pc-chosen">
				<span>
					{ chosen.name }
					{ chosen.sku ? <span className="pc-muted"> ({ chosen.sku })</span> : null }
				</span>
				<button
					type="button"
					className="pc-link"
					onClick={ () => {
						setChosen( null );
						onChange( 0 );
					} }
				>
					Change
				</button>
			</div>
		);
	}

	return (
		<div className="pc-combobox">
			<input
				id={ id }
				type="search"
				className="pc-input"
				placeholder="Type part of the product name"
				value={ query }
				role="combobox"
				aria-expanded={ open && hits.length > 0 }
				aria-controls={ `${ id }-list` }
				aria-autocomplete="list"
				onInput={ ( e ) => setQuery( ( e.target as HTMLInputElement ).value ) }
				onKeyDown={ ( e ) => {
					if ( 'Escape' === e.key ) {
						setOpen( false );
					}
				} }
			/>
			{ open && hits.length > 0 ? (
				<ul className="pc-options" id={ `${ id }-list` } role="listbox">
					{ hits.map( ( hit ) => (
						<li key={ hit.id } role="option" aria-selected={ false }>
							<button
								type="button"
								onClick={ () => {
									setChosen( hit );
									setOpen( false );
									setQuery( '' );
									onChange( hit.id );
								} }
							>
								{ hit.name }
								{ hit.sku ? <span className="pc-muted"> ({ hit.sku })</span> : null }
							</button>
						</li>
					) ) }
				</ul>
			) : null }
			{ open && query.trim().length >= 2 && 0 === hits.length ? <p className="pc-help">No products match.</p> : null }
		</div>
	);
}
