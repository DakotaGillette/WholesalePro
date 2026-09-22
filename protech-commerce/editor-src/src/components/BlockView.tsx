import { useContext, useState } from 'preact/hooks';
import type { Block, TemplateStyle } from '../types';
import type { Address } from '../model/template';
import { DND_MOVE_BLOCK, startDrag } from '../dnd';
import { CanvasContext } from './Canvas';

interface Props {
	block: Block;
	address: Address;
	/** Position in its list, and the list's length: for the drop index and the move up/down buttons. */
	index: number;
	count: number;
	style: TemplateStyle;
	fonts: Record< string, string >;
}

/**
 * A live, client-rendered approximation of what this block will look like,
 * close enough to design from without a round trip to the server. It is
 * not the email itself: the Preview tab's real EmailRenderer output, and
 * the actual send, are the only things that have to be pixel-accurate.
 * Nothing here is ever what gets saved or sent.
 *
 * The whole block is a drop target: its top half drops before it, its
 * bottom half after it, with a line showing which. (Until 3.8.0 only an
 * 8px gap between blocks accepted a drop, which was very hard to hit.)
 * Hovering shows MailPoet-style controls: move up, move down, settings,
 * duplicate, delete, and a handle to drag it.
 */
export function BlockView( { block, address, index, count, style, fonts }: Props ) {
	const ctx = useContext( CanvasContext )!;
	const [ dragging, setDragging ] = useState( false );
	const a = block.attrs;
	const font = fonts[ String( style.font ) ] || fonts.helvetica;
	const headingFont = fonts[ String( style.heading_font ) ] || font;
	const selected = block.id === ctx.selectedId;
	const dropPos = ctx.target?.key === block.id ? ctx.target.pos : null;

	const classes = [ 'pw-canvas-block' ];

	if ( selected ) {
		classes.push( 'is-selected' );
	}

	if ( dragging ) {
		classes.push( 'is-dragging' );
	}

	if ( dropPos ) {
		classes.push( `is-drop-${ dropPos }` );
	}

	const wrapStyle = {
		paddingTop: `${ Number( a.pt ?? 0 ) }px`,
		paddingBottom: `${ Number( a.pb ?? 0 ) }px`,
		paddingLeft: '24px',
		paddingRight: '24px',
		background: String( a.bg ?? '' ) || 'transparent',
		textAlign: ( String( a.align ?? 'left' ) as 'left' | 'center' | 'right' ) || 'left',
		cursor: 'grab',
		['--pw-brand' as string]: style.brand || '#42649d',
	};

	/** A button on the hover toolbar: stops the click from also selecting (or deselecting) the block. */
	const tool = ( label: string, icon: string, onClick: () => void, disabled = false ) => (
		<button
			type="button"
			className="pw-block-tool"
			title={ label }
			aria-label={ label }
			disabled={ disabled }
			onClick={ ( e ) => {
				e.stopPropagation();
				onClick();
			} }
		>
			<span className={ `dashicons dashicons-${ icon }` } aria-hidden="true" />
		</button>
	);

	return (
		<div
			className={ classes.join( ' ' ) }
			style={ wrapStyle }
			draggable
			onDragStart={ ( e ) => {
				e.stopPropagation();
				e.dataTransfer?.setData( DND_MOVE_BLOCK, block.id );
				e.dataTransfer!.effectAllowed = 'move';
				startDrag( { type: block.type, blockId: block.id, from: address } );
				setDragging( true );
			} }
			onDragEnd={ () => setDragging( false ) }
			onDragOver={ ( e ) => {
				// The innermost block under the pointer decides; its columns block and the canvas stay out of it.
				e.stopPropagation();

				if ( ! ctx.canDropIn( address ) ) {
					return;
				}

				e.preventDefault();

				const pos = halfOf( e );

				if ( ctx.target?.key !== block.id || ctx.target.pos !== pos ) {
					ctx.setTarget( { key: block.id, pos } );
				}
			} }
			onDrop={ ( e ) => {
				e.preventDefault();
				e.stopPropagation();
				ctx.dropAt( address, 'before' === halfOf( e ) ? index : index + 1 );
			} }
			onClick={ ( e ) => {
				e.stopPropagation();
				ctx.onSelect( block.id );
			} }
			data-block-id={ block.id }
		>
			<div className="pw-block-tools">
				{ tool( 'Move up', 'arrow-up-alt2', () => ctx.onReorder( block.id, -1 ), 0 === index ) }
				{ tool( 'Move down', 'arrow-down-alt2', () => ctx.onReorder( block.id, 1 ), index >= count - 1 ) }
				{ tool( 'Settings', 'admin-generic', () => ctx.onSelect( block.id ) ) }
				{ tool( 'Duplicate', 'admin-page', () => ctx.onDuplicate( block.id ) ) }
				{ tool( 'Delete', 'trash', () => ctx.onRemove( block.id ) ) }
				<span className="pw-block-tool pw-block-handle" title="Drag to move" aria-hidden="true">
					<span className="dashicons dashicons-move" />
				</span>
			</div>
			<BlockBody block={ block } style={ style } fonts={ fonts } font={ font } headingFont={ headingFont } />
		</div>
	);
}

/** Which half of the block the pointer is over: the top half drops before it, the bottom half after. */
function halfOf( e: DragEvent ): 'before' | 'after' {
	const rect = ( e.currentTarget as HTMLElement ).getBoundingClientRect();
	return e.clientY < rect.top + rect.height / 2 ? 'before' : 'after';
}

function BlockBody( {
	block,
	style,
	fonts,
	font,
	headingFont,
}: {
	block: Block;
	style: TemplateStyle;
	fonts: Record< string, string >;
	font: string;
	headingFont: string;
} ) {
	const ctx = useContext( CanvasContext )!;
	const a = block.attrs;

	switch ( block.type ) {
		case 'heading': {
			const size = Number( a.size ?? 26 );
			return (
				<div style={ { fontFamily: headingFont, fontSize: `${ size }px`, fontWeight: 700, color: String( a.color || style.text ) } }>
					{ String( a.text ?? '' ) || <Empty label="Heading" /> }
				</div>
			);
		}

		case 'text': {
			const html = String( a.html ?? '' ).trim();
			return (
				<div style={ { fontFamily: font, fontSize: `${ Number( a.size ?? 15 ) }px`, lineHeight: 1.6, color: String( a.color || style.text ) } }>
					{ html ? (
						html.split( /\n{2,}/ ).map( ( p, i ) => <p key={ i } style={ { margin: '0 0 10px' } } dangerouslySetInnerHTML={ { __html: p } } /> )
					) : (
						<Empty label="Write your message here." />
					) }
				</div>
			);
		}

		case 'button': {
			const full = Boolean( a.full_width );
			return (
				<a
					href="#"
					onClick={ ( e ) => e.preventDefault() }
					style={ {
						display: full ? 'block' : 'inline-block',
						textAlign: 'center',
						padding: '12px 24px',
						borderRadius: `${ Number( a.radius ?? 8 ) }px`,
						background: String( a.bg_color || style.brand || '#42649d' ),
						color: String( a.color || '#ffffff' ),
						fontFamily: font,
						fontSize: `${ Number( a.size ?? 15 ) }px`,
						fontWeight: 700,
						textDecoration: 'none',
					} }
				>
					{ String( a.label ?? '' ) || 'Button text' }
				</a>
			);
		}

		case 'image': {
			const url = String( a.url ?? '' );
			const width = Number( a.width ?? 552 );
			return url ? (
				<img src={ url } alt={ String( a.alt ?? '' ) } style={ { maxWidth: `${ width }px`, width: '100%', height: 'auto', display: 'inline-block' } } />
			) : (
				<div style={ { border: '1px dashed #c3c4c7', padding: '32px', color: '#8c8f94', maxWidth: `${ width }px`, margin: '0 auto' } }>No picture chosen</div>
			);
		}

		case 'product_grid': {
			const columns = Number( a.columns ?? 3 );
			const productIds = ( a.product_ids as unknown[] | undefined ) ?? [];
			const label = 'newest' === a.mode ? 'The newest products' : 'on_sale' === a.mode ? 'Products currently on sale' : `${ productIds.length } chosen product(s)`;
			return (
				<div>
					<div style={ { display: 'grid', gridTemplateColumns: `repeat(${ columns }, 1fr)`, gap: '12px' } }>
						{ Array.from( { length: columns } ).map( ( _, i ) => (
							<div key={ i } style={ { border: '1px solid #e5e7eb', borderRadius: '6px', padding: '12px', textAlign: 'center', fontFamily: font, fontSize: '13px', color: style.muted } }>
								<div style={ { background: '#f0f0f1', height: '80px', borderRadius: '4px', marginBottom: '8px' } } />
								Product
							</div>
						) ) }
					</div>
					<div style={ { fontSize: '12px', color: style.muted, marginTop: '6px' } }>{ label }</div>
				</div>
			);
		}

		case 'explainer_quantities':
		case 'explainer_ladder': {
			const title = String( a.title ?? '' );
			return (
				<div style={ { background: '#eef3fa', border: '1px solid #c9d6ea', borderRadius: '8px', padding: '20px', fontFamily: font } }>
					{ a.show_title && title ? <p style={ { margin: '0 0 12px', fontWeight: 700, fontSize: '12px', textTransform: 'uppercase', color: style.brand } }>{ title }</p> : null }
					<div style={ { fontSize: '13px', color: style.muted } }>
						{ 'explainer_quantities' === block.type ? 'Pack / Display / Case diagram (wholesale only)' : 'Quantity price levels (wholesale only)' }
					</div>
				</div>
			);
		}

		case 'order_items':
		case 'order_totals':
			return (
				<div style={ { background: '#eef3fa', border: '1px solid #c9d6ea', borderRadius: '8px', padding: '20px', fontFamily: font, fontSize: '13px', color: style.muted } }>
					{ 'order_items' === block.type
						? 'Order items: picture, name, quantity and price for each product (order emails only)'
						: 'Order totals: subtotal, shipping, discount, tax and total (order emails only)' }
				</div>
			);

		case 'columns': {
			const gap = Number( a.gap ?? 16 );
			const valign = String( a.valign ?? 'top' );
			return (
				<div
					style={ {
						display: 'flex',
						gap: `${ gap }px`,
						alignItems: 'middle' === valign ? 'center' : 'bottom' === valign ? 'flex-end' : 'flex-start',
					} }
				>
					{ ( block.children ?? [] ).map( ( col, i ) => {
						const colAddress: Address = { parentId: block.id, column: i };
						const key = `col:${ block.id }:${ i }`;
						const isOver = ctx.target?.key === key;
						return (
							<div
								key={ i }
								data-column={ i }
								className={ `pw-canvas-column${ isOver ? ' is-over' : '' }` }
								// The column's own empty space (below its blocks, or all of an empty column) appends to it.
								onDragOver={ ( e ) => {
									e.stopPropagation();

									if ( ! ctx.canDropIn( colAddress ) ) {
										return;
									}

									e.preventDefault();

									if ( ! isOver ) {
										ctx.setTarget( { key, pos: 'inside' } );
									}
								} }
								onDrop={ ( e ) => {
									e.preventDefault();
									e.stopPropagation();
									ctx.dropAt( colAddress, col.length );
								} }
							>
								{ 0 === col.length ? (
									<div style={ { padding: '12px' } }>
										<Empty label={ `Column ${ i + 1 }` } />
									</div>
								) : (
									col.map( ( b, j ) => <BlockView key={ b.id } block={ b } address={ colAddress } index={ j } count={ col.length } style={ style } fonts={ fonts } /> )
								) }
							</div>
						);
					} ) }
				</div>
			);
		}

		case 'divider':
			return <hr style={ { border: 0, borderTop: `${ Number( a.thickness ?? 1 ) }px solid ${ String( a.color || '#e5e7eb' ) }`, width: `${ Number( a.width_pct ?? 100 ) }%`, margin: '0 auto' } } />;

		case 'spacer':
			return <div style={ { height: `${ Number( a.height ?? 24 ) }px` } } />;

		case 'social': {
			const networks = [ 'facebook', 'instagram', 'x', 'youtube' ] as const;
			const size = Number( a.size ?? 28 );
			const active = networks.filter( ( n ) => a[ n ] );
			return active.length ? (
				<div style={ { display: 'flex', gap: '8px' } }>
					{ active.map( ( n ) => (
						<span
							key={ n }
							style={ {
								width: `${ size }px`,
								height: `${ size }px`,
								lineHeight: `${ size }px`,
								borderRadius: '50%',
								background: style.brand || '#42649d',
								color: '#fff',
								textAlign: 'center',
								fontSize: `${ Math.round( size * 0.4 ) }px`,
								fontWeight: 700,
							} }
						>
							{ n === 'facebook' ? 'f' : n === 'instagram' ? 'IG' : n === 'x' ? 'X' : 'YT' }
						</span>
					) ) }
				</div>
			) : (
				<Empty label="Social links (no accounts set yet)" />
			);
		}

		case 'video':
			return a.url ? (
				<div style={ { background: '#eef3fa', borderRadius: '8px', padding: '40px 20px', textAlign: 'center', color: style.brand || '#42649d', fontFamily: font } }>&#9658; Watch the video</div>
			) : (
				<Empty label="Video (no link yet)" />
			);

		case 'html':
			return a.code ? (
				<div style={ { border: '1px dashed #c3c4c7', borderRadius: '4px', padding: '12px', fontFamily: 'monospace', fontSize: '12px', color: '#646970', whiteSpace: 'pre-wrap', maxHeight: '120px', overflow: 'hidden' } }>
					{ String( a.code ) }
				</div>
			) : (
				<Empty label="Custom HTML (empty)" />
			);

		default:
			return <Empty label={ block.type } />;
	}
}

function Empty( { label }: { label: string } ) {
	return <span style={ { color: '#8c8f94', fontStyle: 'italic' } }>{ label }</span>;
}
