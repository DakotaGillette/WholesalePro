import { useEffect, useRef, useState } from 'preact/hooks';
import { api } from '../../api';

/**
 * A small, real rendering of an email: fetched only once the card scrolls
 * into view, drawn at full width in a sandboxed frame and scaled down, and
 * never clickable itself (the card around it is the button).
 */
export function Thumb( { source }: { source: string } ) {
	const box = useRef< HTMLDivElement | null >( null );
	const [ html, setHtml ] = useState< string | null >( null );
	const [ failed, setFailed ] = useState( false );
	// The email is drawn 640px wide, then scaled to the card's own width.
	const [ scale, setScale ] = useState( 0.34 );

	useEffect( () => {
		const el = box.current;

		if ( ! el ) {
			return undefined;
		}

		const measure = () => setScale( Math.max( 0.2, el.clientWidth / 640 ) );
		measure();

		if ( ! ( 'ResizeObserver' in window ) ) {
			return undefined;
		}

		const observer = new ResizeObserver( measure );
		observer.observe( el );
		return () => observer.disconnect();
	}, [] );

	useEffect( () => {
		const el = box.current;

		if ( ! el ) {
			return undefined;
		}

		const load = () =>
			api.emails
				.thumbnail( source )
				.then( ( r ) => setHtml( r.html ) )
				.catch( () => setFailed( true ) );

		if ( ! ( 'IntersectionObserver' in window ) ) {
			load();
			return undefined;
		}

		const observer = new IntersectionObserver(
			( entries ) => {
				if ( entries.some( ( e ) => e.isIntersecting ) ) {
					observer.disconnect();
					load();
				}
			},
			{ rootMargin: '200px' }
		);

		observer.observe( el );
		return () => observer.disconnect();
	}, [ source ] );

	return (
		<div className="pc-thumb" ref={ box } aria-hidden="true">
			{ null !== html ? (
				<iframe className="pc-thumb-frame" title="" sandbox="" srcDoc={ html } tabIndex={ -1 } scrolling="no" style={ { transform: `scale( ${ scale } )` } } />
			) : (
				<div className={ `pc-thumb-placeholder${ failed ? ' is-failed' : '' }` }>{ failed ? 'No preview' : '' }</div>
			) }
		</div>
	);
}
