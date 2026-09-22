/**
 * Small line icons (24px grid, drawn with the current text color) for the
 * block tiles and the new-email flow. Inline SVG, so nothing extra loads.
 */

const PATHS: Record< string, string > = {
	heading: 'M5 5v14M13 5v14M5 12h8M17 9l2-1v11',
	text: 'M4 6h16M4 10h16M4 14h16M4 18h10',
	button: 'M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM8 12h8',
	image: 'M4 5h16v14H4zM4 16l5-5 4 4 3-3 4 4M15.5 9.5h.01',
	product_grid: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
	explainer_quantities: 'M3 14h6v6H3zM9 8h6v12H9zM15 3h6v17h-6z',
	explainer_ladder: 'M4 20h4v-4h4v-4h4V8h4',
	columns: 'M4 4h7v16H4zM13 4h7v16h-7z',
	order_items: 'M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01',
	order_totals: 'M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6M9 16h3',
	social: 'M18 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM18 22a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8.6 13.5l6.8 4M15.4 6.5l-6.8 4',
	video: 'M3 5h18v14H3zM10 9v6l5-3z',
	html: 'M8 8l-4 4 4 4M16 8l4 4-4 4M13 6l-2 12',
	divider: 'M3 12h18',
	spacer: 'M12 3v18M8 7l4-4 4 4M8 17l4 4 4-4',
	check: 'M5 12l5 5 9-10',
	mail: 'M3 6h18v12H3zM3 7l9 6 9-6',
	sms: 'M4 5h16v11H9l-5 4zM8 10h8',
	auto: 'M4 12a8 8 0 0 1 14-5.3M20 12a8 8 0 0 1-14 5.3M18 3v4h-4M6 21v-4h4',
	close: 'M6 6l12 12M18 6L6 18',
	eye: 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
	undo: 'M9 14L4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3',
	redo: 'M15 14l5-5-5-5M20 9H9a5 5 0 0 0 0 10h3',
	arrowLeft: 'M19 12H5M11 6l-6 6 6 6',
	arrowRight: 'M5 12h14M13 6l6 6-6 6',
	chevronDown: 'M6 9l6 6 6-6',
};

export function Icon( { name, size = 20, className = '' }: { name: string; size?: number; className?: string } ) {
	const d = PATHS[ name ] ?? PATHS.text;

	return (
		<svg
			className={ `pc-icon ${ className }` }
			width={ size }
			height={ size }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth={ 1.8 }
			strokeLinecap="round"
			strokeLinejoin="round"
			aria-hidden="true"
			focusable="false"
		>
			<path d={ d } />
		</svg>
	);
}
