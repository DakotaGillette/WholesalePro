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
	/** '' = the same as `font`. */
	heading_font: string;
	/** '' = the brand color. */
	link_color: string;
	/** 0 = the site-wide default. */
	mobile_padding: number;
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
	/** Which starter this came from, if any ('' otherwise); carried through, never set from the UI. */
	category: string;
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

export interface Starter {
	key: string;
	name: string;
	category: string;
	template: Template;
}

/** Who an email goes to: Audience::normalize()'s shape. */
export interface AudienceInput {
	type: string;
	scope?: string;
	tiers?: string[];
	days?: number;
	product_id?: number;
	user_ids?: number[];
}

/** One email written in the new flow (RestEmails::payload()'s `email`). */
export interface EmailRecord {
	id: string;
	name: string;
	status: 'draft' | 'scheduled' | 'sent';
	audience: AudienceInput;
	service_message: boolean;
	updated_at: number;
	sent_at: number;
}

export interface EmailPayload {
	email: EmailRecord;
	design: Template | null;
	errors: string[];
}

export interface Estimate {
	label: string;
	total: number;
	sent_to: number;
	reasons: Array< { reason: string; label: string; count: number } >;
}

export interface GalleryData {
	starters: Array< { key: string; name: string; category: string } >;
	templates: Array< { id: string; name: string; category: string; updated_at: number } >;
	recent: Array< { id: string; name: string; sent_at: number } >;
	labels: Record< string, string >;
}

export interface EditorBootstrap {
	restRoot: string;
	nonce: string;
	/** 'template' (the template library's editor) unless the new-email flow says 'email'. */
	mode?: 'template' | 'email';
	/** Template mode only. */
	template?: Template;
	schema: Schema;
	/** The site-wide email design (Messaging -> Settings), in the same shape as a template's own `style`, used wherever a template leaves a value empty. */
	styleDefaults: TemplateStyle;
	/** Plain-language merge tag names, for the "Insert a personal detail" pickers on tag-eligible fields. */
	mergeTags: Record< string, string >;
	/** Template mode only. */
	slots?: Record< string, string >;
	/** category key => label, for the new-template Gallery. */
	categoryLabels: Record< string, string >;
	/** Whether the current admin can save a Custom HTML block; when false, the Palette never offers it. */
	caps: { unfiltered_html: boolean };
	/** Email mode: the email the address names, when it names one. */
	emailData?: EmailPayload | null;
	/** Email mode: the address named an email that no longer exists. wp_localize_script() turns true into "1". */
	missingEmail?: boolean | string;
	/** Email mode: the audience a Customers-tab "Message" link starts with. */
	presetAudience?: AudienceInput;
	/** Email mode: tier slug => label. */
	tiers?: Record< string, string >;
	/** Email mode: who emails come from (Messaging -> Settings -> Sending). */
	sender?: { name: string; email: string; reply_to: string };
	/** Email mode: whether texts can be sent (a text provider is connected). "1" or "" after wp_localize_script(). */
	smsReady?: boolean | string;
	urls: {
		list?: string;
		settings?: string;
		compose?: string;
		emails?: string;
		drafts?: string;
		automatic?: string;
		textForm?: string;
		templates?: string;
	};
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
