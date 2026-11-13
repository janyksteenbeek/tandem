<script setup lang="ts">
import {
    AudioLines,
    CircleDot,
    Lock,
    Mic,
    MicOff,
    RotateCw,
    Speaker,
    Video,
    VideoOff,
    Volume2,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import DeviceSelect from '@/components/meet/DeviceSelect.vue';
import MicLevelMeter from '@/components/meet/MicLevelMeter.vue';
import TrackVideo from '@/components/meet/TrackVideo.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useAudioLevel } from '@/composables/meet/useAudioLevel';
import { useDevices } from '@/composables/meet/useDevices';
import { useMeetPreferences } from '@/composables/meet/useMeetPreferences';
import { usePreview } from '@/composables/meet/usePreview';
import { resolveDevice } from '@/lib/meet/preferences';
import { cn } from '@/lib/utils';
import type { MeetCall, MeetViewer } from '@/types';

/**
 * The moment before a meeting: see yourself, check the microphone, pick devices, join.
 * Whatever is chosen here is remembered for next time.
 */
const props = defineProps<{
    call: MeetCall;
    viewer: MeetViewer;
    joining: boolean;
    /** A refusal from the server: wrong password, locked, full. */
    error: string | null;
    passwordRejected: boolean;
    mediaConfigured: boolean;
}>();

const emit = defineEmits<{
    join: [payload: { name: string; password: string | null }];
}>();

const preferences = useMeetPreferences();
const preview = usePreview(preferences);
const level = useAudioLevel(preview.audioTrack);

const devices = useDevices(() => {
    // Something was plugged in or out: fall back when the chosen device is gone.
    const microphone = resolveDevice(
        preferences.value.audioInputId,
        devices.audioInputs.value,
    );

    if (
        preview.audioTrack.value &&
        microphone &&
        microphone !== preferences.value.audioInputId
    ) {
        void preview.switchMicrophone(microphone);
    }

    const camera = resolveDevice(
        preferences.value.videoInputId,
        devices.videoInputs.value,
    );

    if (
        preview.videoTrack.value &&
        camera &&
        camera !== preferences.value.videoInputId
    ) {
        void preview.switchCamera(camera);
    }
});

const name = ref(props.viewer.name ?? '');
/** Not on touch screens, where focusing would cover the preview with the keyboard. */
const autofocusName =
    typeof window !== 'undefined' &&
    !window.matchMedia('(pointer: coarse)').matches;
const password = ref('');

const needsName = computed(() => !props.viewer.is_signed_in);
const needsPassword = computed(
    () =>
        props.call.has_password &&
        props.viewer.role === 'guest' &&
        !props.viewer.invite,
);
const willWait = computed(
    () =>
        props.call.waiting_room &&
        props.viewer.role === 'guest' &&
        !props.viewer.invite,
);
const canJoin = computed(
    () =>
        props.mediaConfigured &&
        !props.joining &&
        (!needsName.value || name.value.trim() !== '') &&
        (!needsPassword.value || password.value !== ''),
);

const selectedSpeaker = computed(() =>
    resolveDevice(preferences.value.audioOutputId, devices.audioOutputs.value),
);

onMounted(async () => {
    if (needsName.value && name.value === '') {
        name.value = preferences.value.displayName;
    }

    await preview.start();
    // Device names are only readable once permission was granted.
    await devices.refresh();
});

watch(
    () => props.joining,
    (joining) => {
        // Hand the devices over: the room opens them itself.
        if (joining) {
            stopTest();
        }
    },
);

const submit = () => {
    if (!canJoin.value) {
        return;
    }

    if (needsName.value) {
        preferences.value.displayName = name.value.trim();
    }

    emit('join', {
        name: name.value.trim(),
        password: needsPassword.value ? password.value : null,
    });
};

// --- Microphone test: record three seconds, play them back -------------------------------

const testState = ref<'idle' | 'recording' | 'playing'>('idle');
let recorder: MediaRecorder | null = null;
let playback: HTMLAudioElement | null = null;
let testTimer: ReturnType<typeof setTimeout> | undefined;

const canTestMicrophone = computed(
    () =>
        typeof MediaRecorder !== 'undefined' &&
        preview.audioTrack.value !== null,
);

const routeToSpeaker = async (element: HTMLAudioElement) => {
    const sink = selectedSpeaker.value;

    if (sink && 'setSinkId' in element) {
        await (
            element as HTMLAudioElement & {
                setSinkId: (id: string) => Promise<void>;
            }
        )
            .setSinkId(sink)
            .catch(() => {});
    }
};

const stopTest = () => {
    clearTimeout(testTimer);

    if (recorder && recorder.state !== 'inactive') {
        recorder.onstop = null;
        recorder.stop();
    }

    recorder = null;
    playback?.pause();
    playback = null;
    testState.value = 'idle';
};

const testMicrophone = () => {
    const track = preview.audioTrack.value;

    if (!track || testState.value !== 'idle') {
        stopTest();

        return;
    }

    const clone = track.clone();
    clone.enabled = true;

    const chunks: Blob[] = [];

    recorder = new MediaRecorder(new MediaStream([clone]));
    recorder.ondataavailable = (event) => chunks.push(event.data);
    recorder.onstop = async () => {
        clone.stop();

        const url = URL.createObjectURL(
            new Blob(chunks, { type: recorder?.mimeType }),
        );

        playback = new Audio(url);
        await routeToSpeaker(playback);
        playback.onended = () => {
            URL.revokeObjectURL(url);
            testState.value = 'idle';
        };
        testState.value = 'playing';
        void playback.play().catch(() => (testState.value = 'idle'));
    };

    testState.value = 'recording';
    recorder.start();
    testTimer = setTimeout(() => recorder?.stop(), 3000);
};

const testSpeaker = async () => {
    const Context =
        window.AudioContext ??
        (window as unknown as { webkitAudioContext?: typeof AudioContext })
            .webkitAudioContext;

    if (!Context) {
        return;
    }

    const context = new Context();
    const destination = context.createMediaStreamDestination();
    const element = new Audio();

    element.srcObject = destination.stream;
    await routeToSpeaker(element);
    void element.play().catch(() => {});

    // Two soft notes: enough to tell which speaker is selected.
    [523.25, 783.99].forEach((frequency, index) => {
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        const start = context.currentTime + index * 0.22;

        oscillator.frequency.value = frequency;
        gain.gain.setValueAtTime(0, start);
        gain.gain.linearRampToValueAtTime(0.18, start + 0.03);
        gain.gain.exponentialRampToValueAtTime(0.001, start + 0.5);
        oscillator.connect(gain).connect(destination);
        oscillator.start(start);
        oscillator.stop(start + 0.55);
    });

    setTimeout(() => void context.close().catch(() => {}), 1200);
};

onBeforeUnmount(stopTest);

const toggleClass = (on: boolean) =>
    cn(
        'flex size-11 items-center justify-center rounded-full backdrop-blur-md transition-[background-color,transform] duration-150 outline-none focus-visible:ring-[3px] focus-visible:ring-white/70 active:scale-95',
        on
            ? 'bg-white/20 text-white hover:bg-white/30'
            : 'bg-destructive text-white hover:bg-destructive/90',
    );
</script>

<template>
    <div
        class="mx-auto grid w-full max-w-6xl items-center gap-8 px-5 py-8 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)] lg:gap-14 lg:px-10 lg:py-0"
    >
        <!-- Preview ------------------------------------------------------------------ -->
        <section
            class="flex animate-rise flex-col gap-3"
            aria-label="Camera and microphone"
        >
            <div
                class="relative aspect-video overflow-hidden rounded-3xl bg-[oklch(0.2_0.012_275)] shadow-float ring-1 ring-black/5 dark:ring-white/10"
            >
                <TrackVideo
                    v-if="preview.videoTrack.value"
                    :track="preview.videoTrack.value"
                    mirror
                    label="Your camera"
                    class="rounded-3xl"
                />

                <div
                    v-else
                    class="absolute inset-0 flex flex-col items-center justify-center gap-4 px-8 text-center text-white"
                >
                    <template
                        v-if="
                            preview.cameraProblem.value &&
                            preferences.cameraEnabled
                        "
                    >
                        <span
                            class="flex size-12 items-center justify-center rounded-full bg-white/10"
                        >
                            <VideoOff class="size-5" />
                        </span>
                        <div class="max-w-sm space-y-1.5">
                            <p class="text-[15px] font-semibold">
                                {{ preview.cameraProblem.value.title }}
                            </p>
                            <p class="text-sm leading-relaxed text-white/70">
                                {{ preview.cameraProblem.value.help }}
                            </p>
                        </div>
                        <Button
                            size="sm"
                            variant="secondary"
                            class="bg-white/15 text-white hover:bg-white/25"
                            @click="preview.setCamera(true)"
                        >
                            <RotateCw /> Try again
                        </Button>
                    </template>
                    <template v-else-if="preview.starting.value">
                        <Spinner class="size-6 text-white/70" />
                        <p class="text-sm text-white/70">
                            Starting your camera…
                        </p>
                    </template>
                    <template v-else>
                        <UserAvatar
                            :name="name || viewer.name || 'You'"
                            :src="viewer.avatar"
                            size="xl"
                            class="size-24 text-3xl ring-4 ring-white/10"
                        />
                        <p class="text-sm text-white/70">Your camera is off</p>
                    </template>
                </div>

                <!-- Level + toggles -->
                <div
                    class="absolute inset-x-0 bottom-0 flex items-end justify-between gap-3 bg-gradient-to-t from-black/60 to-transparent p-4 pt-12"
                >
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            :class="
                                toggleClass(
                                    preferences.micEnabled &&
                                        !preview.microphoneProblem.value,
                                )
                            "
                            :aria-label="
                                preferences.micEnabled
                                    ? 'Turn microphone off'
                                    : 'Turn microphone on'
                            "
                            :aria-pressed="preferences.micEnabled"
                            data-testid="prejoin-mic"
                            @click="
                                preview.setMicrophone(!preferences.micEnabled)
                            "
                        >
                            <Mic
                                v-if="
                                    preferences.micEnabled &&
                                    !preview.microphoneProblem.value
                                "
                                class="size-5"
                            />
                            <MicOff v-else class="size-5" />
                        </button>
                        <button
                            type="button"
                            :class="
                                toggleClass(
                                    preferences.cameraEnabled &&
                                        !preview.cameraProblem.value,
                                )
                            "
                            :aria-label="
                                preferences.cameraEnabled
                                    ? 'Turn camera off'
                                    : 'Turn camera on'
                            "
                            :aria-pressed="preferences.cameraEnabled"
                            data-testid="prejoin-camera"
                            @click="
                                preview.setCamera(!preferences.cameraEnabled)
                            "
                        >
                            <Video
                                v-if="
                                    preferences.cameraEnabled &&
                                    !preview.cameraProblem.value
                                "
                                class="size-5"
                            />
                            <VideoOff v-else class="size-5" />
                        </button>
                    </div>

                    <div
                        v-if="preview.audioTrack.value"
                        class="flex w-36 flex-col gap-1.5 rounded-xl bg-black/35 px-3 py-2 backdrop-blur-md"
                    >
                        <span
                            class="flex items-center gap-1.5 text-[11px] font-medium text-white/80"
                        >
                            <AudioLines class="size-3" />
                            {{
                                preferences.micEnabled
                                    ? 'Say something'
                                    : 'Muted'
                            }}
                        </span>
                        <MicLevelMeter
                            :level="level"
                            :muted="!preferences.micEnabled"
                            class="[&>span:not(.bg-success):not(.bg-warning)]:bg-white/20"
                        />
                    </div>
                </div>
            </div>

            <!-- Microphone problem -->
            <div
                v-if="preview.microphoneProblem.value"
                role="alert"
                class="flex items-start gap-3 rounded-xl border border-destructive/25 bg-destructive/[0.06] px-4 py-3"
            >
                <MicOff class="mt-0.5 size-4 shrink-0 text-destructive" />
                <div class="min-w-0 flex-1 space-y-0.5">
                    <p class="text-sm font-semibold">
                        {{ preview.microphoneProblem.value.title }}
                    </p>
                    <p
                        class="text-[13px] leading-relaxed text-muted-foreground"
                    >
                        {{ preview.microphoneProblem.value.help }}
                    </p>
                </div>
                <Button
                    size="sm"
                    variant="outline"
                    @click="preview.restartMicrophone()"
                >
                    Try again
                </Button>
            </div>

            <!-- Devices -->
            <div class="grid gap-2 sm:grid-cols-3">
                <DeviceSelect
                    label="Microphone"
                    :icon="Mic"
                    :options="devices.audioInputs.value"
                    :model-value="
                        resolveDevice(
                            preferences.audioInputId,
                            devices.audioInputs.value,
                        )
                    "
                    @update:model-value="preview.switchMicrophone"
                />
                <DeviceSelect
                    label="Camera"
                    :icon="Video"
                    :options="devices.videoInputs.value"
                    :model-value="
                        resolveDevice(
                            preferences.videoInputId,
                            devices.videoInputs.value,
                        )
                    "
                    @update:model-value="preview.switchCamera"
                />
                <DeviceSelect
                    v-if="devices.canSelectOutput.value"
                    label="Speaker"
                    :icon="Volume2"
                    :options="devices.audioOutputs.value"
                    :model-value="selectedSpeaker"
                    @update:model-value="
                        (id) => (preferences.audioOutputId = id)
                    "
                />
            </div>

            <div class="flex flex-wrap items-center gap-2 text-[13px]">
                <Button
                    v-if="canTestMicrophone"
                    size="sm"
                    variant="ghost"
                    class="text-muted-foreground"
                    @click="testMicrophone"
                >
                    <CircleDot
                        :class="
                            testState === 'recording' &&
                            'animate-pulse text-destructive'
                        "
                    />
                    {{
                        testState === 'recording'
                            ? 'Recording… speak now'
                            : testState === 'playing'
                              ? 'Playing it back…'
                              : 'Test microphone'
                    }}
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    class="text-muted-foreground"
                    @click="testSpeaker"
                >
                    <Speaker /> Test speaker
                </Button>
            </div>
        </section>

        <!-- Join ---------------------------------------------------------------------- -->
        <section
            class="flex animate-rise flex-col gap-6 [animation-delay:60ms]"
            aria-label="Join meeting"
        >
            <header class="space-y-2">
                <p
                    class="text-xs font-semibold tracking-[0.08em] text-brand uppercase"
                >
                    {{ call.organization }}
                </p>
                <h1
                    class="font-display text-3xl leading-tight text-balance sm:text-[2.1rem]"
                >
                    {{ call.title }}
                </h1>
                <p class="text-[15px] text-muted-foreground">
                    {{
                        call.status === 'live'
                            ? 'This meeting is in progress.'
                            : 'Ready when you are.'
                    }}
                </p>
            </header>

            <div
                v-if="call.is_recording"
                class="flex items-start gap-3 rounded-xl border border-destructive/25 bg-destructive/[0.06] px-4 py-3"
                role="note"
            >
                <span
                    class="mt-1.5 size-2 shrink-0 animate-pulse rounded-full bg-destructive"
                />
                <p class="text-[13px] leading-relaxed">
                    <span class="font-semibold"
                        >This meeting is being recorded.</span
                    >
                    Your voice, camera and anything you share will be part of
                    the recording once you join.
                </p>
            </div>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div v-if="needsName" class="grid gap-1.5">
                    <Label for="meet-name">Your name</Label>
                    <Input
                        id="meet-name"
                        v-model="name"
                        v-focus="autofocusName"
                        maxlength="80"
                        autocomplete="name"
                        placeholder="How should others see you?"
                        class="h-11 rounded-xl text-[15px]"
                    />
                </div>
                <div v-else class="flex items-center gap-3">
                    <UserAvatar
                        :name="viewer.name"
                        :src="viewer.avatar"
                        size="lg"
                    />
                    <div class="min-w-0">
                        <p class="truncate text-[15px] font-medium">
                            {{ viewer.name }}
                        </p>
                        <p class="text-[13px] text-muted-foreground">
                            Joining as
                            {{
                                viewer.role === 'host' ? 'the host' : 'a member'
                            }}
                        </p>
                    </div>
                </div>

                <div v-if="needsPassword" class="grid gap-1.5">
                    <Label
                        for="meet-password"
                        class="flex items-center gap-1.5"
                    >
                        <Lock class="size-3.5" /> Meeting password
                    </Label>
                    <Input
                        id="meet-password"
                        v-model="password"
                        type="password"
                        autocomplete="off"
                        :aria-invalid="passwordRejected"
                        class="h-11 rounded-xl text-[15px]"
                    />
                </div>

                <p
                    v-if="error"
                    role="alert"
                    class="rounded-lg bg-destructive/[0.08] px-3 py-2 text-[13px] font-medium text-destructive"
                >
                    {{ error }}
                </p>

                <p
                    v-if="!mediaConfigured"
                    role="alert"
                    class="rounded-lg bg-warning/15 px-3 py-2 text-[13px] leading-relaxed"
                >
                    Calls are not set up on this server yet. An administrator
                    needs to configure the media server before anyone can join.
                </p>

                <Button
                    type="submit"
                    size="lg"
                    :disabled="!canJoin"
                    class="h-12 rounded-xl bg-brand text-[15px] font-semibold text-brand-foreground shadow-[0_8px_24px_-8px] shadow-brand/60 hover:bg-brand/90"
                    data-testid="join-button"
                >
                    <Spinner v-if="joining" />
                    {{
                        joining
                            ? 'Joining…'
                            : willWait
                              ? 'Ask to join'
                              : 'Join now'
                    }}
                </Button>

                <p
                    v-if="willWait"
                    class="text-center text-[13px] text-muted-foreground"
                >
                    A host will let you in once you ask.
                </p>
            </form>
        </section>
    </div>
</template>
