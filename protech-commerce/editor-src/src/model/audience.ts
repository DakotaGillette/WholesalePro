import type { AudienceInput } from '../types';

/**
 * The Send step's audience, kept to what Audience::normalize() reads. The
 * server is the judge of what is valid; these only keep the payload tidy
 * and let the screen say what is missing before a request is made.
 */

export const AUDIENCE_TYPES: Array< { key: string; label: string } > = [
	{ key: 'all', label: 'All of them' },
	{ key: 'recent', label: 'Ordered in the last ... days' },
	{ key: 'inactive', label: 'No order in ... days' },
	{ key: 'never_ordered', label: 'Have never ordered' },
	{ key: 'bought_product', label: 'Bought a product' },
	{ key: 'tier', label: 'Specific tiers (wholesale only)' },
	{ key: 'selected', label: 'Customers you picked' },
];

export const SCOPES: Array< { key: string; label: string } > = [
	{ key: 'wholesale', label: 'Wholesale customers' },
	{ key: 'retail', label: 'Retail customers' },
	{ key: 'everyone', label: 'Everyone' },
];

/** Only the keys the chosen type uses, with defaults filled in. */
export function tidyAudience( input: AudienceInput ): AudienceInput {
	const type = input.type || 'all';
	const out: AudienceInput = { type, scope: input.scope || 'wholesale' };

	if ( 'recent' === type ) {
		out.days = positive( input.days, 30 );
	} else if ( 'inactive' === type ) {
		out.days = positive( input.days, 60 );
	} else if ( 'bought_product' === type ) {
		out.product_id = positive( input.product_id, 0 );
	} else if ( 'tier' === type ) {
		out.tiers = [ ...( input.tiers ?? [] ) ];
	} else if ( 'selected' === type ) {
		out.user_ids = [ ...( input.user_ids ?? [] ) ];
	}

	return out;
}

/** What is missing before this audience can be sent to, in words for the screen. */
export function audienceProblems( input: AudienceInput ): string[] {
	const a = tidyAudience( input );

	if ( 'bought_product' === a.type && ! a.product_id ) {
		return [ 'Choose the product they bought.' ];
	}

	if ( 'selected' === a.type && 0 === ( a.user_ids ?? [] ).length ) {
		return [ 'No customers were picked. Pick them on the Customers tab, or choose another audience.' ];
	}

	return [];
}

function positive( value: unknown, fallback: number ): number {
	const n = Math.floor( Number( value ) );

	return Number.isFinite( n ) && n > 0 ? n : fallback;
}
