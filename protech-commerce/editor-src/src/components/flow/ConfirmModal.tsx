import type { ComponentChildren } from 'preact';
import { useEffect, useRef } from 'preact/hooks';

interface Props {
	title: string;
	children: ComponentChildren;
	confirmLabel: string;
	busy: boolean;
	onConfirm: () => void;
	onCancel: () => void;
}

/** A small dialog: focus starts on Cancel, Escape closes it, and focus stays inside while it is open. */
export function ConfirmModal( { title, children, confirmLabel, busy, onConfirm, onCancel }: Props ) {
	const dialog = useRef< HTMLDivElement | null >( null );
	const cancel = useRef< HTMLButtonElement | null >( null );

	useEffect( () => {
		const opener = document.activeElement as HTMLElement | null;
		cancel.current?.focus();

		const onKey = ( e: KeyboardEvent ) => {
			if ( 'Escape' === e.key && ! busy ) {
				onCancel();
			}

			if ( 'Tab' === e.key && dialog.current ) {
				const focusable = Array.from( dialog.current.querySelectorAll< HTMLElement >( 'button:not([disabled])' ) );
				const first = focusable[ 0 ];
				const last = focusable[ focusable.length - 1 ];

				if ( e.shiftKey && document.activeElement === first ) {
					e.preventDefault();
					last?.focus();
				} else if ( ! e.shiftKey && document.activeElement === last ) {
					e.preventDefault();
					first?.focus();
				}
			}
		};

		document.addEventListener( 'keydown', onKey );

		return () => {
			document.removeEventListener( 'keydown', onKey );
			opener?.focus();
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return (
		<div className="pc-modal-backdrop" onClick={ () => ! busy && onCancel() }>
			<div className="pc-modal" role="dialog" aria-modal="true" aria-labelledby="pc-modal-title" ref={ dialog } onClick={ ( e ) => e.stopPropagation() }>
				<h2 id="pc-modal-title" className="pc-modal-title">
					{ title }
				</h2>
				<div className="pc-modal-body">{ children }</div>
				<div className="pc-modal-actions">
					<button type="button" className="pc-btn" ref={ cancel } onClick={ onCancel } disabled={ busy }>
						Cancel
					</button>
					<button type="button" className="pc-btn pc-btn--primary" onClick={ onConfirm } disabled={ busy }>
						{ busy ? 'Sending...' : confirmLabel }
					</button>
				</div>
			</div>
		</div>
	);
}
