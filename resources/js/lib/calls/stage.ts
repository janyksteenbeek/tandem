import { gridColumns } from '@/lib/calls/playback';
import type { StageLayout } from '@/lib/calls/playback';

/** A box inside the player's stage, in CSS pixels from its top-left corner. */
export type Rect = { left: number; top: number; width: number; height: number };

export type StageRects = {
    /** Where the shared screen goes (mode `screen`). */
    screen: Rect | null;
    /** One box per person, keyed by media identity. */
    tiles: Record<string, Rect>;
    /** Boxes for the other live screen shares, keyed by track file (clickable thumbnails). */
    thumbnails: Record<string, Rect>;
    /** Whether the people strip runs along the bottom (narrow players) or down the side. */
    strip: 'side' | 'bottom' | 'none';
};

const GAP = 8;
const TILE_RATIO = 16 / 9;

/**
 * Where everything sits on the stage. With a shared screen the screen gets all the room that
 * is left after a strip of people (down the right side, or along the bottom on a narrow
 * player), so text stays as large as the player allows. Without one, people share the stage
 * in the grid that keeps their tiles largest.
 */
export function stageRects(
    layout: StageLayout,
    width: number,
    height: number,
): StageRects {
    const empty: StageRects = {
        screen: null,
        tiles: {},
        thumbnails: {},
        strip: 'none',
    };

    if (width <= 0 || height <= 0) {
        return empty;
    }

    if (layout.mode === 'screen' && layout.screen) {
        const others = layout.screens.filter(
            (screen) => screen.file !== layout.screen?.file,
        );
        const slots = layout.tiles.length + others.length;

        if (slots === 0) {
            return {
                ...empty,
                screen: { left: 0, top: 0, width, height },
            };
        }

        const tiles: Record<string, Rect> = {};
        const thumbnails: Record<string, Rect> = {};
        const keys: { key: string; into: Record<string, Rect> }[] = [
            ...others.map((screen) => ({ key: screen.file, into: thumbnails })),
            ...layout.tiles.map((tile) => ({
                key: tile.identity,
                into: tiles,
            })),
        ];

        if (width < 640) {
            // Along the bottom: a single row, as tall as fits, never more than 56px, so a
            // phone still gives most of its small stage to the shared screen.
            const tileHeight = Math.min(
                56,
                (width - GAP * (slots + 1)) / slots / TILE_RATIO,
            );
            const tileWidth = tileHeight * TILE_RATIO;
            const rowWidth = slots * tileWidth + (slots - 1) * GAP;
            const top = height - tileHeight - GAP;

            keys.forEach(({ key, into }, index) => {
                into[key] = {
                    left: (width - rowWidth) / 2 + index * (tileWidth + GAP),
                    top,
                    width: tileWidth,
                    height: tileHeight,
                };
            });

            return {
                screen: { left: 0, top: 0, width, height: top - GAP },
                tiles,
                thumbnails,
                strip: 'bottom',
            };
        }

        // Down the side: tiles shrink before the strip is allowed to scroll.
        // Kept slim: every pixel given to the strip is one the shared screen's text loses.
        const maxTileWidth = Math.min(184, width * 0.16);
        const fitHeight = (height - GAP * (slots + 1)) / slots;
        const tileWidth = Math.min(maxTileWidth, fitHeight * TILE_RATIO);
        const tileHeight = tileWidth / TILE_RATIO;
        const columnHeight = slots * tileHeight + (slots - 1) * GAP;
        const left = width - tileWidth - GAP;

        keys.forEach(({ key, into }, index) => {
            into[key] = {
                left,
                top:
                    Math.max(GAP, (height - columnHeight) / 2) +
                    index * (tileHeight + GAP),
                width: tileWidth,
                height: tileHeight,
            };
        });

        return {
            screen: { left: 0, top: 0, width: left - GAP, height },
            tiles,
            thumbnails,
            strip: 'side',
        };
    }

    if (layout.mode === 'grid') {
        const count = layout.tiles.length;
        const columns = gridColumns(count, width / height);
        const rows = Math.ceil(count / columns);
        const tileWidth = Math.min(
            (width - GAP * (columns + 1)) / columns,
            ((height - GAP * (rows + 1)) / rows) * TILE_RATIO,
        );
        const tileHeight = tileWidth / TILE_RATIO;
        const gridHeight = rows * tileHeight + (rows - 1) * GAP;
        const tiles: Record<string, Rect> = {};

        layout.tiles.forEach((tile, index) => {
            const row = Math.floor(index / columns);
            const inRow = row === rows - 1 ? count - row * columns : columns;
            const rowWidth = inRow * tileWidth + (inRow - 1) * GAP;

            tiles[tile.identity] = {
                left:
                    (width - rowWidth) / 2 +
                    (index % columns) * (tileWidth + GAP),
                top: (height - gridHeight) / 2 + row * (tileHeight + GAP),
                width: tileWidth,
                height: tileHeight,
            };
        });

        return { ...empty, tiles };
    }

    return empty;
}

export function rectStyle(
    rect: Rect | null | undefined,
): Record<string, string> {
    if (!rect) {
        // Kept in the DOM and decoding, just not shown.
        return {
            left: '0px',
            top: '0px',
            width: '2px',
            height: '2px',
            visibility: 'hidden',
        };
    }

    return {
        left: `${rect.left}px`,
        top: `${rect.top}px`,
        width: `${rect.width}px`,
        height: `${rect.height}px`,
    };
}
