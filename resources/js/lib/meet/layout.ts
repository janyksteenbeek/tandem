/**
 * Where tiles go. Pure geometry, so it can be reasoned about and tested without a browser.
 */

export type GridLayout = {
    columns: number;
    rows: number;
    tileWidth: number;
    tileHeight: number;
};

/**
 * The grid that gives `count` tiles of the given aspect ratio the largest size inside a
 * `width` x `height` box. Tried for every column count: with at most a dozen people that is
 * cheaper and more exact than any closed-form guess.
 */
export function computeGrid(
    count: number,
    width: number,
    height: number,
    gap = 12,
    aspect = 16 / 9,
): GridLayout {
    if (count <= 0 || width <= 0 || height <= 0) {
        return { columns: 1, rows: 1, tileWidth: 0, tileHeight: 0 };
    }

    const candidates: GridLayout[] = [];

    for (let columns = 1; columns <= count; columns++) {
        const rows = Math.ceil(count / columns);
        const maxWidth = (width - gap * (columns - 1)) / columns;
        const maxHeight = (height - gap * (rows - 1)) / rows;

        if (maxWidth <= 0 || maxHeight <= 0) {
            continue;
        }

        const tileWidth = Math.min(maxWidth, maxHeight * aspect);

        candidates.push({
            columns,
            rows,
            tileWidth,
            tileHeight: tileWidth / aspect,
        });
    }

    if (candidates.length === 0) {
        return { columns: 1, rows: count, tileWidth: 0, tileHeight: 0 };
    }

    // Among layouts that are as good as the best one (within 2%), take the widest: fewer
    // rows read better and leave no lonely tile on a row of its own.
    const largest = Math.max(...candidates.map((layout) => layout.tileWidth));
    const best = candidates
        .filter((layout) => layout.tileWidth >= largest * 0.98)
        .reduce((widest, layout) =>
            layout.columns > widest.columns ? layout : widest,
        );

    return {
        ...best,
        tileWidth: Math.floor(best.tileWidth),
        tileHeight: Math.floor(best.tileHeight),
    };
}

export type StageMode = 'solo' | 'duo' | 'grid' | 'focus';

/**
 * How the room is arranged:
 *  - `focus`: something takes the stage (a shared screen or a pinned person), the rest go
 *    in a strip.
 *  - `solo`: you are alone; your own picture fills the stage.
 *  - `duo`: one other person fills the stage, you are a small picture-in-picture.
 *  - `grid`: everyone the same size.
 */
export function stageMode(
    participantCount: number,
    hasScreenShare: boolean,
    hasPinned: boolean,
): StageMode {
    if (hasScreenShare || hasPinned) {
        return 'focus';
    }

    if (participantCount <= 1) {
        return 'solo';
    }

    return participantCount === 2 ? 'duo' : 'grid';
}

/**
 * Which shared screen is on stage. The one the viewer picked wins while it is still being
 * shared; otherwise the most recently started one, preferring other people's screens over
 * your own (you can already see yours).
 */
export function pickStageScreen<T extends { id: string; isLocal: boolean }>(
    screens: T[],
    selectedId: string | null,
): T | null {
    if (screens.length === 0) {
        return null;
    }

    const selected = screens.find((screen) => screen.id === selectedId);

    if (selected) {
        return selected;
    }

    const remote = screens.filter((screen) => !screen.isLocal);

    return remote[remote.length - 1] ?? screens[screens.length - 1];
}

/**
 * Order for the grid and the strip: you first, then whoever is speaking or spoke most
 * recently, then by arrival. Stable, so tiles do not jump around on every syllable: a person
 * only moves forward when they are not already among the first `visible` tiles.
 */
export function orderParticipants<
    T extends { identity: string; isLocal: boolean; joinedAt: number },
>(
    participants: T[],
    previousOrder: string[],
    lastSpokeAt: Record<string, number>,
    visible: number,
): T[] {
    const byIdentity = new Map(participants.map((p) => [p.identity, p]));
    const order = previousOrder.filter((identity) => byIdentity.has(identity));

    participants
        .filter((p) => !order.includes(p.identity))
        .sort((a, b) => a.joinedAt - b.joinedAt)
        .forEach((p) => order.push(p.identity));

    const local = participants.find((p) => p.isLocal);

    if (local) {
        order.splice(order.indexOf(local.identity), 1);
        order.unshift(local.identity);
    }

    if (visible > 0 && order.length > visible) {
        const hidden = order
            .slice(visible)
            .filter((identity) => (lastSpokeAt[identity] ?? 0) > 0)
            .sort((a, b) => (lastSpokeAt[b] ?? 0) - (lastSpokeAt[a] ?? 0));

        for (const identity of hidden) {
            const shown = order.slice(local ? 1 : 0, visible);
            const quietest = shown.reduce((lowest, candidate) =>
                (lastSpokeAt[candidate] ?? 0) < (lastSpokeAt[lowest] ?? 0)
                    ? candidate
                    : lowest,
            );

            if ((lastSpokeAt[identity] ?? 0) > (lastSpokeAt[quietest] ?? 0)) {
                const from = order.indexOf(identity);
                const to = order.indexOf(quietest);
                order[from] = quietest;
                order[to] = identity;
            }
        }
    }

    return order
        .map((identity) => byIdentity.get(identity))
        .filter((p): p is T => p !== undefined);
}
