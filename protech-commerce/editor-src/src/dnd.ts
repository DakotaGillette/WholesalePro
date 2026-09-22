/**
 * The two custom MIME types the canvas's native HTML5 drag and drop carries data
 * under. Shared so a palette tile's setData() and a drop zone's getData() can
 * never silently drift apart again (they did once: two different literal
 * strings for the same "new block from the palette" transfer, so every drop
 * silently inserted nothing).
 */
export const DND_NEW_BLOCK_TYPE = 'text/protech-block-type';
export const DND_MOVE_BLOCK = 'text/protech-move-block';
