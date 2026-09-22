/** Matches EmailBlocks::sanitize()'s accepted id shape ('b_' + hex), so a client-minted id needs no server-side rewriting. */
export function newBlockId(): string {
	const hex = Array.from( { length: 8 }, () => Math.floor( Math.random() * 16 ).toString( 16 ) ).join( '' );
	return `b_${ hex }`;
}
