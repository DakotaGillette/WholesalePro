/**
 * Mirrors the PHP shapes exactly (EmailTemplates::defaults(),
 * EmailBlocks::schema()), so the server is always the one source of truth
 * for what a valid template looks like. Nothing here invents its own idea
 * of a field list.
 */

export type Attrs = Record< string, unknown >;

export interface Block {
	id: string;
	type: string;
	attrs: Attrs;
	/** Only columns blocks have this: one array of blocks per column. */
	children?: Block[][];
}

export interface TemplateStyle {
	width: number;
	page_bg: string;
	canvas: string;
	brand: string;
	text: string;
	muted: string;
	font: string;
}

export interface TemplateHeader {
	show_logo: boolean;
	logo_id: number;
}

export interface TemplateFooter {
	text: string;
	show_address: boolean;
}

export interface Template {
	id: string;
	name: string;
	kind: 'marketing' | 'transactional';
	slot: string;
	subject: string;
	preheader: string;
	blocks: Block[];
	style: TemplateStyle;
	header: TemplateHeader;
	footer: TemplateFooter;
	created_at: number;
	updated_at: number;
	created_by: number;
	seeded: string;
}

export interface SchemaField {
	key: string;
	label: string;
	type: 'text' | 'textarea' | 'number' | 'select' | 'checkbox' | 'color' | 'image' | 'products';
	tag?: boolean;
	summary?: boolean;
	help?: string;
	min?: number;
	max?: number;
	options?: Record< string, string >;
}

export interface BlockTypeSchema {
	label: string;
	help: string;
	defaults: Attrs;
	fields: SchemaField[];
	hidden: string[];
}

export interface Schema {
	types: Record< string, BlockTypeSchema >;
	common_fields: SchemaField[];
	limits: { max_blocks: number; max_depth: number };
	fonts: Record< string, string >;
}

export interface EditorBootstrap {
	restRoot: string;
	nonce: string;
	template: Template;
	schema: Schema;
	/** The site-wide email design (Messaging -> Settings), used wherever a template leaves a style value empty, exactly as EmailRenderer::style() resolves it server-side. */
	styleDefaults: TemplateStyle;
	/** Plain-language merge tag names, for the "Insert a personal detail" pickers on tag-eligible fields. */
	mergeTags: Record< string, string >;
	slots: Record< string, string >;
	urls: { list: string };
}

declare global {
	interface Window {
		protechEditor: EditorBootstrap;
		wp?: {
			media?: ( args: Record< string, unknown > ) => {
				open: () => void;
				on: ( event: string, cb: () => void ) => void;
				state: () => { get: ( key: string ) => { first: () => { toJSON: () => Record< string, unknown > } } };
			};
		};
	}
}
