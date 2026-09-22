type TagTarget = HTMLInputElement | HTMLTextAreaElement;

interface Props {
	mergeTags: Record< string, string >;
	value: string;
	elRef: { current: TagTarget | null };
	onChange: ( value: string ) => void;
}

/**
 * "Insert a personal detail" for any text or textarea field that accepts
 * merge tags, matching the plain-language picker the old editor had so
 * nobody has to type braces from memory.
 */
export function TagPicker( { mergeTags, value, elRef, onChange }: Props ) {
	if ( 0 === Object.keys( mergeTags ).length ) {
		return null;
	}

	function insert( tag: string ): void {
		const el = elRef.current;
		const start = el?.selectionStart ?? value.length;
		const end = el?.selectionEnd ?? value.length;

		onChange( value.slice( 0, start ) + `{${ tag }}` + value.slice( end ) );

		if ( el ) {
			requestAnimationFrame( () => {
				const pos = start + tag.length + 2;
				el.focus();
				el.setSelectionRange( pos, pos );
			} );
		}
	}

	return (
		<select
			className="pw-input pw-tag-insert"
			aria-label="Insert a personal detail"
			value=""
			onChange={ ( e ) => {
				const tag = ( e.target as HTMLSelectElement ).value;
				( e.target as HTMLSelectElement ).value = '';

				if ( tag ) {
					insert( tag );
				}
			} }
		>
			<option value="">Insert a personal detail…</option>
			{ Object.entries( mergeTags ).map( ( [ tag, label ] ) => (
				<option key={ tag } value={ tag }>
					{ label }
				</option>
			) ) }
		</select>
	);
}
