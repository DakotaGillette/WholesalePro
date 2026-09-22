import { useRef } from 'preact/hooks';
import type { SchemaField } from '../types';
import { TagPicker } from './TagPicker';

type TagTarget = HTMLInputElement | HTMLTextAreaElement;

interface Props {
	field: SchemaField;
	value: unknown;
	/** Plain-language merge tags; when the field is tag-eligible, an "Insert a personal detail" picker appears under it. */
	mergeTags?: Record< string, string >;
	onChange: ( value: unknown ) => void;
}

/** Renders one input for one schema field, generated from EmailBlocks::schema() so a new block type never needs new markup here. */
export function Field( { field, value, mergeTags, onChange }: Props ) {
	const id = `field-${ field.key }`;
	const inputRef = useRef< TagTarget | null >( null );
	const showTagPicker = !! field.tag && ( 'text' === field.type || 'textarea' === field.type ) && !! mergeTags;

	return (
		<div className="pw-field">
			<label htmlFor={ id } className="pw-field-label">
				{ field.label }
			</label>
			<FieldInput id={ id } field={ field } value={ value } onChange={ onChange } inputRef={ inputRef } />
			{ field.help ? <p className="pw-field-help">{ field.help }</p> : null }
			{ showTagPicker ? <TagPicker mergeTags={ mergeTags! } value={ String( value ?? '' ) } elRef={ inputRef } onChange={ onChange } /> : null }
		</div>
	);
}

function FieldInput( { id, field, value, onChange, inputRef }: Props & { id: string; inputRef: { current: TagTarget | null } } ) {
	switch ( field.type ) {
		case 'text':
			return (
				<input
					ref={ inputRef as { current: HTMLInputElement | null } }
					id={ id }
					type="text"
					className="pw-input"
					value={ String( value ?? '' ) }
					onInput={ ( e ) => onChange( ( e.target as HTMLInputElement ).value ) }
				/>
			);

		case 'textarea':
			return (
				<textarea
					ref={ inputRef as { current: HTMLTextAreaElement | null } }
					id={ id }
					className="pw-input pw-textarea"
					rows={ 5 }
					value={ String( value ?? '' ) }
					onInput={ ( e ) => onChange( ( e.target as HTMLTextAreaElement ).value ) }
				/>
			);

		case 'number':
			return (
				<input
					id={ id }
					type="number"
					className="pw-input pw-input-number"
					min={ field.min }
					max={ field.max }
					value={ Number( value ?? 0 ) }
					onInput={ ( e ) => onChange( Number( ( e.target as HTMLInputElement ).value ) ) }
				/>
			);

		case 'select':
			return (
				<select id={ id } className="pw-input" value={ String( value ?? '' ) } onChange={ ( e ) => onChange( ( e.target as HTMLSelectElement ).value ) }>
					{ Object.entries( field.options ?? {} ).map( ( [ key, label ] ) => (
						<option key={ key } value={ key }>
							{ label }
						</option>
					) ) }
				</select>
			);

		case 'checkbox':
			return (
				<label className="pw-checkbox">
					<input type="checkbox" checked={ Boolean( value ) } onChange={ ( e ) => onChange( ( e.target as HTMLInputElement ).checked ) } />
				</label>
			);

		case 'color':
			return <ColorField id={ id } value={ String( value ?? '' ) } onChange={ onChange } />;

		case 'image':
			return <ImageField value={ Number( value ?? 0 ) } onChange={ onChange } />;

		case 'products':
			return <ProductsField value={ ( value as number[] ) ?? [] } onChange={ onChange } />;

		default:
			return null;
	}
}

function ColorField( { id, value, onChange }: { id: string; value: string; onChange: ( v: unknown ) => void } ) {
	return (
		<span className="pw-color-field">
			<input
				id={ id }
				type="color"
				value={ value || '#000000' }
				onInput={ ( e ) => onChange( ( e.target as HTMLInputElement ).value ) }
			/>
			<input type="text" className="pw-input pw-color-text" placeholder="empty" value={ value } onInput={ ( e ) => onChange( ( e.target as HTMLInputElement ).value ) } />
		</span>
	);
}

/** Media Library picker via wp.media(), the same API the old editor used. Exported so SettingsPanels can reuse it for the per-template logo. */
export function ImageField( { value, onChange }: { value: number; onChange: ( v: unknown ) => void } ) {
	const choose = () => {
		if ( ! window.wp?.media ) {
			return;
		}

		const frame = window.wp.media( { title: 'Choose a picture', button: { text: 'Use this picture' }, library: { type: 'image' }, multiple: false } );

		frame.on( 'select', () => {
			const attachment = frame.state().get( 'selection' ).first().toJSON();
			onChange( attachment.id );
		} );

		frame.open();
	};

	return (
		<span>
			<button type="button" className="button" onClick={ choose }>
				{ value ? 'Change picture' : 'Choose picture' }
			</button>
			{ value ? (
				<button type="button" className="button-link pw-remove-link" onClick={ () => onChange( 0 ) }>
					Remove
				</button>
			) : null }
		</span>
	);
}

/** A simple multi-select-by-id product picker: type an id and add it. Good enough until the full product search ships. */
function ProductsField( { value, onChange }: { value: number[]; onChange: ( v: unknown ) => void } ) {
	const inputRef = useRef< HTMLInputElement >( null );

	const add = () => {
		const id = Number( inputRef.current?.value );

		if ( id > 0 && ! value.includes( id ) ) {
			onChange( [ ...value, id ] );
		}

		if ( inputRef.current ) {
			inputRef.current.value = '';
		}
	};

	return (
		<div>
			<div className="pw-product-chips">
				{ value.map( ( id ) => (
					<span key={ id } className="pw-chip">
						#{ id }
						<button type="button" onClick={ () => onChange( value.filter( ( v ) => v !== id ) ) } aria-label={ `Remove product ${ id }` }>
							&times;
						</button>
					</span>
				) ) }
			</div>
			<input ref={ inputRef } type="number" className="pw-input pw-input-number" placeholder="Product ID" />
			<button type="button" className="button" onClick={ add }>
				Add
			</button>
		</div>
	);
}
