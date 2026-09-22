import { useEffect, useRef, useState } from 'preact/hooks';
import type { Template } from '../types';
import { api } from '../api';

interface Props {
	template: Template;
	width: 'desktop' | 'phone';
}

/**
 * The one thing in this editor that has to be accurate: the real
 * EmailRenderer output, fetched fresh whenever the template changes
 * (debounced), never the canvas's own approximation.
 */
export function TruePreview( { template, width }: Props ) {
	const [ html, setHtml ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const timer = useRef< number | undefined >();

	useEffect( () => {
		setLoading( true );
		window.clearTimeout( timer.current );

		timer.current = window.setTimeout( () => {
			api
				.preview( template )
				.then( ( result ) => {
					setHtml( result.html );
					setError( '' );
				} )
				.catch( ( e: Error ) => setError( e.message ) )
				.finally( () => setLoading( false ) );
		}, 300 );

		return () => window.clearTimeout( timer.current );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ JSON.stringify( template ) ] );

	return (
		<div className="pw-true-preview">
			{ error ? <div className="notice notice-error inline"><p>{ error }</p></div> : null }
			<iframe
				title="Email preview"
				sandbox=""
				srcDoc={ html }
				className={ `pw-preview-frame pw-preview-frame--${ width }` }
				style={ { opacity: loading ? 0.5 : 1 } }
			/>
		</div>
	);
}
