/**
 * A metronome. It lives in a worker because browsers slow down or stop the timers and
 * animation frames of a tab that is not in front, and a camera effect has to keep producing
 * frames while its owner looks at another tab.
 */
const scope = self as unknown as {
    onmessage: ((event: MessageEvent<number>) => void) | null;
    postMessage(message: number): void;
};

let timer: ReturnType<typeof setInterval> | undefined;

scope.onmessage = (event) => {
    clearInterval(timer);

    if (event.data > 0) {
        timer = setInterval(() => scope.postMessage(0), event.data);
    }
};
