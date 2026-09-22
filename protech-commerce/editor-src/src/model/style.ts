import type { Template, TemplateStyle } from '../types';

/** Mirrors EmailRenderer::style(): a template value wins when set, otherwise the site-wide default. */
export function resolveStyle( template: Template, defaults: TemplateStyle ): TemplateStyle {
	const s = template.style;

	return {
		width: s.width > 0 ? s.width : defaults.width,
		page_bg: s.page_bg || defaults.page_bg,
		canvas: s.canvas || defaults.canvas,
		brand: s.brand || defaults.brand,
		text: s.text || defaults.text,
		muted: s.muted || defaults.muted,
		font: s.font || defaults.font,
		heading_font: s.heading_font || defaults.heading_font || s.font || defaults.font,
		link_color: s.link_color || defaults.link_color || s.brand || defaults.brand,
		mobile_padding: s.mobile_padding > 0 ? s.mobile_padding : defaults.mobile_padding,
	};
}
