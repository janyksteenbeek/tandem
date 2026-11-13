import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { deviceOptions } from '@/lib/meet/preferences';

type DeviceInfo = Pick<MediaDeviceInfo, 'deviceId' | 'kind' | 'label'>;

/**
 * The cameras, microphones and speakers the browser can see, kept current as things are
 * plugged in and out.
 */
export function useDevices(onChange?: () => void) {
    const devices = ref<DeviceInfo[]>([]);

    const audioInputs = computed(() =>
        deviceOptions(devices.value, 'audioinput'),
    );
    const videoInputs = computed(() =>
        deviceOptions(devices.value, 'videoinput'),
    );
    const audioOutputs = computed(() =>
        deviceOptions(devices.value, 'audiooutput'),
    );

    /** Choosing a speaker needs `setSinkId`, which Safari and Firefox on mobile lack. */
    const canSelectOutput = ref(false);

    const refresh = async (): Promise<void> => {
        if (
            typeof navigator === 'undefined' ||
            !navigator.mediaDevices?.enumerateDevices
        ) {
            return;
        }

        try {
            devices.value = (
                await navigator.mediaDevices.enumerateDevices()
            ).map(({ deviceId, kind, label }) => ({ deviceId, kind, label }));
        } catch {
            devices.value = [];
        }
    };

    const handleChange = async () => {
        await refresh();
        onChange?.();
    };

    onMounted(() => {
        canSelectOutput.value =
            typeof HTMLMediaElement !== 'undefined' &&
            'setSinkId' in HTMLMediaElement.prototype;

        void refresh();
        navigator.mediaDevices?.addEventListener?.(
            'devicechange',
            handleChange,
        );
    });

    onBeforeUnmount(() => {
        navigator.mediaDevices?.removeEventListener?.(
            'devicechange',
            handleChange,
        );
    });

    return { audioInputs, videoInputs, audioOutputs, canSelectOutput, refresh };
}
