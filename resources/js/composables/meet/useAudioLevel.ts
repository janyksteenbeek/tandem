import type { Ref } from 'vue';
import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * How loud a microphone track is right now, from 0 to 1, for level meters. Measured locally
 * with an analyser; nothing leaves the browser.
 */
export function useAudioLevel(
    track: Ref<MediaStreamTrack | null | undefined>,
): Ref<number> {
    const level = ref(0);

    let context: AudioContext | null = null;
    let source: MediaStreamAudioSourceNode | null = null;
    let frame = 0;

    const stop = () => {
        if (frame) {
            cancelAnimationFrame(frame);
            frame = 0;
        }

        source?.disconnect();
        source = null;
        void context?.close().catch(() => {});
        context = null;
        level.value = 0;
    };

    const start = (mediaTrack: MediaStreamTrack) => {
        const Context =
            window.AudioContext ??
            (window as unknown as { webkitAudioContext?: typeof AudioContext })
                .webkitAudioContext;

        if (!Context) {
            return;
        }

        try {
            context = new Context();
            source = context.createMediaStreamSource(
                new MediaStream([mediaTrack]),
            );

            const analyser = context.createAnalyser();
            analyser.fftSize = 512;
            analyser.smoothingTimeConstant = 0.5;
            source.connect(analyser);

            const samples = new Float32Array(analyser.fftSize);
            let smoothed = 0;

            const tick = () => {
                analyser.getFloatTimeDomainData(samples);

                let sum = 0;

                for (const sample of samples) {
                    sum += sample * sample;
                }

                // RMS on a perceptual-ish scale: normal speech lands around the middle.
                const rms = Math.sqrt(sum / samples.length);
                const scaled = Math.min(1, Math.pow(rms * 4, 0.7));

                smoothed = scaled > smoothed ? scaled : smoothed * 0.88;
                level.value = mediaTrack.enabled ? smoothed : 0;
                frame = requestAnimationFrame(tick);
            };

            tick();
        } catch {
            stop();
        }
    };

    watch(
        track,
        (mediaTrack) => {
            stop();

            if (mediaTrack && typeof window !== 'undefined') {
                start(mediaTrack);
            }
        },
        { immediate: true },
    );

    onBeforeUnmount(stop);

    return level;
}
