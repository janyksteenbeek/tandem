/**
 * Fractional ordering for drag-and-drop. Positions are decimals; inserting between two
 * neighbours takes their midpoint. When neighbours get too close the caller should rebalance.
 */
export const GAP = 1024;
export const MIN_GAP = 1e-6;

export function between(
    prev: number | null | undefined,
    next: number | null | undefined,
): { position: number; rebalance: boolean } {
    if (
        (prev === null || prev === undefined) &&
        (next === null || next === undefined)
    ) {
        return { position: GAP, rebalance: false };
    }

    if (prev === null || prev === undefined) {
        return { position: (next as number) - GAP, rebalance: false };
    }

    if (next === null || next === undefined) {
        return { position: prev + GAP, rebalance: false };
    }

    if (next - prev < MIN_GAP) {
        return { position: prev + MIN_GAP / 2, rebalance: true };
    }

    return { position: (prev + next) / 2, rebalance: false };
}

/** Evenly spaced positions for a whole column, used after a rebalance. */
export function rebalance(count: number): number[] {
    return Array.from({ length: count }, (_, i) => (i + 1) * GAP);
}
