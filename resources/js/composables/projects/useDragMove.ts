import { onBeforeUnmount, ref, shallowRef } from 'vue';

export type DragHandlers<T> = {
    onMove?: (dx: number, dy: number, payload: T, event: PointerEvent) => void;
    onEnd?: (dx: number, dy: number, payload: T, event: PointerEvent) => void;
    onCancel?: (payload: T) => void;
};

const THRESHOLD = 4;

/**
 * Free-form pointer drag (chips onto calendar days, bars along a timeline). Starts after a 4px
 * threshold so clicks still work, cancels on Escape and never leaves listeners behind.
 */
export function useDragMove<T>(handlers: DragHandlers<T>) {
    const dragging = ref(false);
    const payload = shallowRef<T | null>(null);
    const dx = ref(0);
    const dy = ref(0);

    let originX = 0;
    let originY = 0;
    let pending = false;
    let active: T | null = null;

    const cleanup = () => {
        window.removeEventListener('pointermove', onMove);
        window.removeEventListener('pointerup', onUp);
        window.removeEventListener('pointercancel', onCancelEvent);
        window.removeEventListener('keydown', onKey);
        document.body.style.userSelect = '';
        document.body.style.cursor = '';
        pending = false;
        dragging.value = false;
        dx.value = 0;
        dy.value = 0;
    };

    const onMove = (event: PointerEvent) => {
        const nextX = event.clientX - originX;
        const nextY = event.clientY - originY;

        if (
            pending &&
            Math.abs(nextX) < THRESHOLD &&
            Math.abs(nextY) < THRESHOLD
        ) {
            return;
        }

        if (pending) {
            pending = false;
            dragging.value = true;
            document.body.style.userSelect = 'none';
            document.body.style.cursor = 'grabbing';
        }

        dx.value = nextX;
        dy.value = nextY;

        if (active !== null) {
            handlers.onMove?.(nextX, nextY, active, event);
        }
    };

    const onUp = (event: PointerEvent) => {
        const wasDragging = dragging.value;
        const current = active;
        const endX = dx.value;
        const endY = dy.value;
        cleanup();
        active = null;
        payload.value = null;

        if (wasDragging && current !== null) {
            handlers.onEnd?.(endX, endY, current, event);
        }
    };

    const cancel = () => {
        const current = active;
        const wasDragging = dragging.value;
        cleanup();
        active = null;
        payload.value = null;

        if (wasDragging && current !== null) {
            handlers.onCancel?.(current);
        }
    };

    const onCancelEvent = () => cancel();

    const onKey = (event: KeyboardEvent) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            cancel();
        }
    };

    const start = (event: PointerEvent, data: T) => {
        if (event.button !== 0 && event.pointerType === 'mouse') {
            return;
        }

        originX = event.clientX;
        originY = event.clientY;
        active = data;
        payload.value = data;
        pending = true;

        window.addEventListener('pointermove', onMove, { passive: true });
        window.addEventListener('pointerup', onUp);
        window.addEventListener('pointercancel', onCancelEvent);
        window.addEventListener('keydown', onKey);
    };

    onBeforeUnmount(() => cleanup());

    return { dragging, payload, dx, dy, start, cancel };
}

/** The element under the pointer that carries `data-date`, if any. */
export function dateUnderPointer(event: {
    clientX: number;
    clientY: number;
}): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const el = document.elementFromPoint(event.clientX, event.clientY);
    const target = el?.closest<HTMLElement>('[data-date]');

    return target?.dataset.date ?? null;
}
