import { useRef } from 'preact/hooks';
import type { Schema, Template } from '../types';
import { ImageField } from './Field';
import { TagPicker } from './TagPicker';

interface Props {
	template: Template;
	schema: Schema;
	slots: Record< string, string >;
	mergeTags: Record< string, string >;
	onChange: ( patch: Partial< Template > ) => void;
}

const FONT_LABELS: Record< string, string > = {
	helvetica: 'Helvetica / Arial',
	georgia: 'Georgia',
	system: 'System font',
};

/** The right-hand panels the old editor had (Email settings, Sent automatically as, Design, Header and footer), unchanged in what they store. */
export function SettingsPanels( { template, schema, slots, mergeTags, onChange }: Props ) {
	const subjectRef = useRef< HTMLInputElement | null >( null );
	const preheaderRef = useRef< HTMLInputElement | null >( null );
	const footerTextRef = useRef< HTMLTextAreaElement | null >( null );

	return (
		<>
			<details className="pw-panel">
				<summary>Email settings</summary>
				<div className="pw-field">
					<label className="pw-field-label" htmlFor="pw-kind">
						Type
					</label>
					<select id="pw-kind" className="pw-input" value={ template.kind } onChange={ ( e ) => onChange( { kind: ( e.target as HTMLSelectElement ).value as Template[ 'kind' ] } ) }>
						<option value="marketing">Marketing (has unsubscribe links)</option>
						<option value="transactional">Service email (no unsubscribe links)</option>
					</select>
				</div>
				<div className="pw-field">
					<label className="pw-field-label" htmlFor="pw-subject">
						Subject
					</label>
					<input
						ref={ subjectRef }
						id="pw-subject"
						type="text"
						className="pw-input"
						value={ template.subject }
						onInput={ ( e ) => onChange( { subject: ( e.target as HTMLInputElement ).value } ) }
					/>
					<TagPicker mergeTags={ mergeTags } value={ template.subject } elRef={ subjectRef } onChange={ ( subject ) => onChange( { subject } ) } />
				</div>
				<div className="pw-field">
					<label className="pw-field-label" htmlFor="pw-preheader">
						Preview text
					</label>
					<input
						ref={ preheaderRef }
						id="pw-preheader"
						type="text"
						className="pw-input"
						value={ template.preheader }
						onInput={ ( e ) => onChange( { preheader: ( e.target as HTMLInputElement ).value } ) }
					/>
					<TagPicker mergeTags={ mergeTags } value={ template.preheader } elRef={ preheaderRef } onChange={ ( preheader ) => onChange( { preheader } ) } />
				</div>
			</details>

			<details className="pw-panel">
				<summary>Sent automatically as</summary>
				<div className="pw-field">
					<label className="pw-field-label" htmlFor="pw-slot">
						Use this design for
					</label>
					<select id="pw-slot" className="pw-input" value={ template.slot } onChange={ ( e ) => onChange( { slot: ( e.target as HTMLSelectElement ).value } ) }>
						<option value="">Nothing (I will pick it in a rule or message)</option>
						{ Object.entries( slots ).map( ( [ key, label ] ) => (
							<option key={ key } value={ key }>
								{ label }
							</option>
						) ) }
					</select>
					<p className="pw-field-help">
						One of the emails the shop sends on its own. Only one template can have each. These are service emails, so they carry no unsubscribe link.
					</p>
				</div>
			</details>

			<details className="pw-panel">
				<summary>Design</summary>
				<p className="pw-field-help">Leave a color empty to use your Email design settings.</p>
				<ColorField label="Brand color" value={ template.style.brand } onChange={ ( v ) => onChange( { style: { ...template.style, brand: v } } ) } />
				<ColorField label="Text color" value={ template.style.text } onChange={ ( v ) => onChange( { style: { ...template.style, text: v } } ) } />
				<ColorField label="Email background" value={ template.style.canvas } onChange={ ( v ) => onChange( { style: { ...template.style, canvas: v } } ) } />
				<ColorField label="Page background" value={ template.style.page_bg } onChange={ ( v ) => onChange( { style: { ...template.style, page_bg: v } } ) } />
				<div className="pw-field">
					<label className="pw-field-label" htmlFor="pw-font">
						Font
					</label>
					<select id="pw-font" className="pw-input" value={ template.style.font } onChange={ ( e ) => onChange( { style: { ...template.style, font: ( e.target as HTMLSelectElement ).value } } ) }>
						{ Object.keys( schema.fonts ).map( ( key ) => (
							<option key={ key } value={ key }>
								{ FONT_LABELS[ key ] ?? key }
							</option>
						) ) }
					</select>
				</div>
				<div className="pw-field">
					<label className="pw-field-label" htmlFor="pw-width">
						Width (px, 0 = default)
					</label>
					<input
						id="pw-width"
						type="number"
						className="pw-input pw-input-number"
						min={ 0 }
						max={ 700 }
						value={ template.style.width }
						onInput={ ( e ) => onChange( { style: { ...template.style, width: Number( ( e.target as HTMLInputElement ).value ) } } ) }
					/>
				</div>
			</details>

			<details className="pw-panel">
				<summary>Header and footer</summary>
				<label className="pw-checkbox-row">
					<input type="checkbox" checked={ template.header.show_logo } onChange={ ( e ) => onChange( { header: { ...template.header, show_logo: ( e.target as HTMLInputElement ).checked } } ) } />
					Show the logo (or store name) at the top
				</label>
				<div className="pw-field">
					<label className="pw-field-label">Logo for this template (empty uses the one in Email design)</label>
					<ImageField value={ template.header.logo_id } onChange={ ( logo_id ) => onChange( { header: { ...template.header, logo_id: logo_id as number } } ) } />
				</div>
				<div className="pw-field">
					<label className="pw-field-label" htmlFor="pw-footer-text">
						Footer text
					</label>
					<textarea
						ref={ footerTextRef }
						id="pw-footer-text"
						className="pw-input pw-textarea"
						rows={ 3 }
						value={ template.footer.text }
						onInput={ ( e ) => onChange( { footer: { ...template.footer, text: ( e.target as HTMLTextAreaElement ).value } } ) }
					/>
					<TagPicker mergeTags={ mergeTags } value={ template.footer.text } elRef={ footerTextRef } onChange={ ( text ) => onChange( { footer: { ...template.footer, text } } ) } />
				</div>
				<label className="pw-checkbox-row">
					<input
						type="checkbox"
						checked={ template.footer.show_address }
						onChange={ ( e ) => onChange( { footer: { ...template.footer, show_address: ( e.target as HTMLInputElement ).checked } } ) }
					/>
					Show the store address (marketing emails always show it)
				</label>
			</details>
		</>
	);
}

function ColorField( { label, value, onChange }: { label: string; value: string; onChange: ( v: string ) => void } ) {
	return (
		<div className="pw-field">
			<label className="pw-field-label">{ label }</label>
			<span className="pw-color-field">
				<input type="color" value={ value || '#000000' } onInput={ ( e ) => onChange( ( e.target as HTMLInputElement ).value ) } />
				<input type="text" className="pw-input pw-color-text" placeholder="empty" value={ value } onInput={ ( e ) => onChange( ( e.target as HTMLInputElement ).value ) } />
			</span>
		</div>
	);
}
