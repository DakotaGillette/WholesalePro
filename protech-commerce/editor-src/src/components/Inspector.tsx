import type { Schema, Template } from '../types';
import { Field } from './Field';

interface Props {
	template: Template;
	schema: Schema;
	selectedType: string;
	attrs: Record< string, unknown >;
	mergeTags: Record< string, string >;
	onChange: ( patch: Record< string, unknown > ) => void;
	onBack: () => void;
	onMoveUp: () => void;
	onMoveDown: () => void;
	onDuplicate: () => void;
	onRemove: () => void;
}

/** The settings for exactly one selected block: fields come straight from EmailBlocks::schema(), so a new PHP block type needs no new markup here. */
export function Inspector( { schema, selectedType, attrs, mergeTags, onChange, onBack, onMoveUp, onMoveDown, onDuplicate, onRemove }: Props ) {
	const def = schema.types[ selectedType ];

	if ( ! def ) {
		return null;
	}

	const showCommon = 'spacer' !== selectedType && 'columns' !== selectedType;

	return (
		<div className="pw-inspector">
			<div className="pw-inspector-head">
				<button type="button" className="button-link pw-back" onClick={ onBack }>
					&larr; Back to blocks
				</button>
				<strong>{ def.label }</strong>
			</div>

			<div className="pw-inspector-actions">
				<button type="button" className="button" onClick={ onMoveUp } aria-label="Move up">
					&uarr;
				</button>
				<button type="button" className="button" onClick={ onMoveDown } aria-label="Move down">
					&darr;
				</button>
				<button type="button" className="button" onClick={ onDuplicate }>
					Copy
				</button>
				<button type="button" className="button-link pw-remove-link" onClick={ onRemove }>
					Remove
				</button>
			</div>

			{ def.fields.map( ( field ) => (
				<Field key={ field.key } field={ field } value={ attrs[ field.key ] } mergeTags={ mergeTags } onChange={ ( v ) => onChange( { [ field.key ]: v } ) } />
			) ) }

			{ showCommon ? (
				<details className="pw-more">
					<summary>Spacing and background</summary>
					{ schema.common_fields.map( ( field ) => (
						<Field key={ field.key } field={ field } value={ attrs[ field.key ] } mergeTags={ mergeTags } onChange={ ( v ) => onChange( { [ field.key ]: v } ) } />
					) ) }
				</details>
			) : null }
		</div>
	);
}
