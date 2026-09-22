import type { Address } from './model/template';

/**
 * The two custom MIME types the canvas's native HTML5 drag and drop carries data
 * under. Shared so a palette tile's setData() and the canvas can never silently
 * drift apart again (they did once: two different literal strings for the same
 * "new block from the palette" transfer, so every drop silently inserted nothing).
 * Firefox will not start a drag without some data set, so both are still set.
 */
export const DND_NEW_BLOCK_TYPE = 'text/protech-block-type';
export const DND_MOVE_BLOCK = 'text/protech-move-block';

/**
 * What is being dragged right now. dataTransfer.getData() is empty during
 * dragover (browsers only reveal it on drop), and the canvas needs to know
 * the block's type while hovering to refuse, say, columns inside a column
 * before the user lets go. Set on dragstart, cleared on dragend.
 */
export interface ActiveDrag {
	type: string;
	/** Null for a new block from the palette. */
	blockId: string | null;
	from: Address | null;
}

let active: ActiveDrag | null = null;

export function startDrag( drag: ActiveDrag ): void {
	active = drag;
}

export function currentDrag(): ActiveDrag | null {
	return active;
}

export function endDrag(): void {
	active = null;
}
