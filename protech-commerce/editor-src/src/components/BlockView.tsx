import type { Block, TemplateStyle } from '../types';
import type { Address } from '../model/template';
import { DropZone } from './DropZone';

interface Props {
	block: Block;
	address: Address;
	style: TemplateStyle;
	fonts: Record< string, string >;
	selectedId: string | null;
	onSelect: ( id: string ) => void;
	onInsert: ( address: Address, index: number, type: string ) => void;
	onMove: ( fromParentId: string | null, fromColumn: number, blockId: string, toAddress: Address, toIndex: number ) => void;
}

/**
 * A live, client-rendered approximation of what this block will look like,
 * close enough to design from without a round trip to the server. It is
 * not the email itself: the Preview tab's real EmailRenderer output, and
 * the actual send, are the only things that have to be pixel-accurate.
 * Nothing here is ever what gets saved or sent.
 */
export function BlockView( { block, address, style, fonts, selectedId, onSelect, onInsert, onMove }: Props ) {
	const a = block.attrs;
	const font = fonts[ String( style.font ) ] || fonts.helvetica;
	const selected = block.id === selectedId;

	const wrapStyle = {
		paddingTop: `${ Number( a.pt ?? 0 ) }px`,
		paddingBottom: `${ Number( a.pb ?? 0 ) }px`,
		paddingLeft: '24px',
		paddingRight: '24px',
		background: String( a.bg ?? '' ) || 'transparent',
		textAlign: ( String( a.align ?? 'left' ) as 'left' | 'center' | 'right' ) || 'left',
		outline: selected ? `2px solid ${ style.brand || '#42649d' }` : '2px solid transparent',
		outlineOffset: '-2px',
		cursor: 'grab',
	};

	return (
		<div
			className="pw-canvas-block"
			style={ wrapStyle }
			draggable
			onDragStart={ ( e ) => {
				e.stopPropagation();
				e.dataTransfer?.setData( 'text/protech-move-block', JSON.stringify( { id: block.id, parentId: address.parentId, column: address.column } ) );
				e.dataTransfer!.effectAllowed = 'move';
			} }
			onClick={ ( e ) => {
				e.stopPropagation();
				onSelect( block.id );
			} }
			data-block-id={ block.id }
		>
			<BlockBody block={ block } style={ style } fonts={ fonts } font={ font } selectedId={ selectedId } onSelect={ onSelect } onInsert={ onInsert } onMove={ onMove } />
		</div>
	);
}

function BlockBody( {
	block,
	style,
	fonts,
	font,
	selectedId,
	onSelect,
	onInsert,
	onMove,
}: {
	block: Block;
	style: TemplateStyle;
	fonts: Record< string, string >;
	font: string;
	selectedId: string | null;
	onSelect: ( id: string ) => void;
	onInsert: ( address: Address, index: number, type: string ) => void;
	onMove: ( fromParentId: string | null, fromColumn: number, blockId: string, toAddress: Address, toIndex: number ) => void;
} ) {
	const a = block.attrs;

	switch ( block.type ) {
		case 'heading': {
			const size = Number( a.size ?? 26 );
			return (
				<div style={ { fontFamily: font, fontSize: `${ size }px`, fontWeight: 700, color: String( a.color || style.text ) } }>
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
			const label = 'newest' === a.mode ? 'The newest products' : `${ productIds.length } chosen product(s)`;
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
						return (
							<div key={ i } data-column={ i } style={ { flex: 1, minWidth: 0, border: '1px dashed #e5e7eb', borderRadius: '4px', padding: '4px' } }>
								<DropZone
									onDropBlockType={ ( type ) => onInsert( colAddress, 0, type ) }
									onDropMove={ ( id, pId, c ) => onMove( pId, c, id, colAddress, 0 ) }
								/>
								{ 0 === col.length ? (
									<div style={ { padding: '12px' } }>
										<Empty label={ `Column ${ i + 1 }` } />
									</div>
								) : (
									col.map( ( b, j ) => (
										<div key={ b.id }>
											<BlockView block={ b } address={ colAddress } style={ style } fonts={ fonts } selectedId={ selectedId } onSelect={ onSelect } onInsert={ onInsert } onMove={ onMove } />
											<DropZone
												onDropBlockType={ ( type ) => onInsert( colAddress, j + 1, type ) }
												onDropMove={ ( id, pId, c ) => onMove( pId, c, id, colAddress, j + 1 ) }
											/>
										</div>
									) )
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

		default:
			return <Empty label={ block.type } />;
	}
}

function Empty( { label }: { label: string } ) {
	return <span style={ { color: '#8c8f94', fontStyle: 'italic' } }>{ label }</span>;
}
