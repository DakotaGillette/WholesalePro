/**
 * A capped undo/redo stack over immutable snapshots of whatever T is
 * (a Template, in practice). Typing is coalesced by the caller (one
 * history entry per pause, not per keystroke); every structural change
 * (add/move/copy/remove) pushes its own entry.
 */

const MAX_DEPTH = 100;

export interface History< T > {
	past: T[];
	present: T;
	future: T[];
}

export function initHistory< T >( present: T ): History< T > {
	return { past: [], present, future: [] };
}

export function push< T >( history: History< T >, next: T ): History< T > {
	if ( next === history.present ) {
		return history;
	}

	const past = [ ...history.past, history.present ].slice( -MAX_DEPTH );
	return { past, present: next, future: [] };
}

/** Replaces the present state without creating an undo step, e.g. after the server hands back a cleaned template. */
export function replace< T >( history: History< T >, next: T ): History< T > {
	return { ...history, present: next };
}

export function undo< T >( history: History< T > ): History< T > {
	if ( 0 === history.past.length ) {
		return history;
	}

	const past = history.past.slice();
	const previous = past.pop() as T;

	return { past, present: previous, future: [ history.present, ...history.future ] };
}

export function redo< T >( history: History< T > ): History< T > {
	if ( 0 === history.future.length ) {
		return history;
	}

	const [ next, ...future ] = history.future;

	return { past: [ ...history.past, history.present ], present: next, future };
}

export function canUndo< T >( history: History< T > ): boolean {
	return history.past.length > 0;
}

export function canRedo< T >( history: History< T > ): boolean {
	return history.future.length > 0;
}
