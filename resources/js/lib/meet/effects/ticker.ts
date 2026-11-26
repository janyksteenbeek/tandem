/**
 * Calls back at a steady rate, also while the tab is in the background (see the worker).
 * Where a worker cannot be started it falls back to a plain timer, which is only steady
 * while the tab is visible.
 */
export function startTicker(
    framesPerSecond: number,
    onTick: () => void,
): () => void {
    const interval = Math.round(1000 / Math.max(1, framesPerSecond));
    let worker: Worker | null = null;
    let timer: ReturnType<typeof setInterval> | undefined;

    const fallBack = () => {
        worker?.terminate();
        worker = null;
        timer ??= setInterval(onTick, interval);
    };

    try {
        worker = new Worker(new URL('./ticker.worker.ts', import.meta.url), {
            type: 'module',
        });
        worker.onmessage = onTick;
        worker.onerror = fallBack;
        worker.postMessage(interval);
    } catch {
        fallBack();
    }

    return () => {
        worker?.terminate();
        worker = null;
        clearInterval(timer);
    };
}
