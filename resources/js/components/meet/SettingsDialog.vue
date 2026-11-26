<script setup lang="ts">
import {
    AudioLines,
    Gauge,
    Keyboard,
    Mic,
    MonitorUp,
    ShieldCheck,
    Sparkles,
    Video,
    Volume2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DeviceSelect from '@/components/meet/DeviceSelect.vue';
import EffectPicker from '@/components/meet/EffectPicker.vue';
import MicLevelMeter from '@/components/meet/MicLevelMeter.vue';
import QualityBars from '@/components/meet/QualityBars.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import type { CallState } from '@/composables/meet/useCallState';
import { useMeetPreferences } from '@/composables/meet/useMeetPreferences';
import type { RoomSession } from '@/composables/meet/useRoomSession';
import { reportError } from '@/lib/http';
import { resolveDevice } from '@/lib/meet/preferences';
import { QUALITY_LABEL } from '@/lib/meet/quality';
import type {
    ConnectionStats,
    DeviceOption,
    QualityLevel,
    VideoQualitySetting,
} from '@/lib/meet/types';
import { cn } from '@/lib/utils';
import type { CallSettings } from '@/types';

/**
 * Everything adjustable in a meeting, kept to what people actually need: devices, a simple
 * video quality choice, camera effects, how screens are shared, a readable view of the connection, the
 * shortcuts, and (for hosts) the rules of the meeting.
 */
const props = defineProps<{
    open: boolean;
    tab: string;
    session: RoomSession;
    state: CallState;
    stats: ConnectionStats;
    quality: QualityLevel;
    micLevel: number;
    audioInputs: DeviceOption[];
    videoInputs: DeviceOption[];
    audioOutputs: DeviceOption[];
    canSelectOutput: boolean;
    shortcuts: { keys: string; description: string }[];
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
    'update:tab': [tab: string];
}>();

const preferences = useMeetPreferences();

const me = computed(() => props.state.me.value);
const settings = computed(() => props.state.settings.value);
const isHost = computed(() => me.value?.can.manage ?? false);

const tabs = computed(() => [
    { id: 'audio', label: 'Audio', icon: AudioLines },
    { id: 'video', label: 'Video', icon: Video },
    { id: 'effects', label: 'Effects', icon: Sparkles },
    { id: 'screen', label: 'Screen sharing', icon: MonitorUp },
    { id: 'connection', label: 'Connection', icon: Gauge },
    { id: 'shortcuts', label: 'Shortcuts', icon: Keyboard },
    ...(isHost.value
        ? [{ id: 'meeting', label: 'Meeting', icon: ShieldCheck }]
        : []),
]);

const VIDEO_QUALITIES: {
    value: VideoQualitySetting;
    label: string;
    hint: string;
}[] = [
    { value: 'auto', label: 'Auto', hint: 'HD, adapting to your connection' },
    { value: 'standard', label: 'Standard', hint: 'Uses less data' },
    { value: 'high', label: 'High', hint: 'Full HD where your camera allows' },
];

// --- Host: meeting rules -----------------------------------------------------------------

const title = ref('');
const password = ref('');
const saving = ref(false);

watch(
    () => [props.open, props.state.call.value?.title] as const,
    ([open, current]) => {
        if (open) {
            title.value = current ?? '';
            password.value = '';
        }
    },
    { immediate: true },
);

const update = async (
    changes: Partial<CallSettings> & {
        title?: string | null;
        password?: string | null;
    },
) => {
    saving.value = true;

    try {
        await props.state.updateSettings(changes);
    } catch (error) {
        reportError(error, 'That setting could not be changed.');
    } finally {
        saving.value = false;
    }
};

const saveTitle = () => {
    if ((props.state.call.value?.title ?? '') !== title.value.trim()) {
        void update({ title: title.value.trim() || null });
    }
};

const savePassword = async () => {
    if (password.value.length < 4) {
        return;
    }

    await update({ password: password.value });
    password.value = '';
};

const metric = (value: number | null, unit: string) =>
    value === null ? '–' : `${value} ${unit}`;

const route = computed(() => {
    if (props.stats.relayed === null) {
        return '–';
    }

    return props.stats.relayed
        ? `Relayed (${props.stats.protocol ?? 'TURN'})`
        : `Direct (${props.stats.protocol ?? 'UDP'})`;
});
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent
            class="gap-0 overflow-hidden p-0 sm:max-w-2xl"
            data-testid="settings-dialog"
        >
            <DialogHeader class="border-b px-5 py-4">
                <DialogTitle>Settings</DialogTitle>
                <DialogDescription class="sr-only">
                    Devices, video quality, effects, screen sharing, connection
                    and shortcuts.
                </DialogDescription>
            </DialogHeader>

            <!-- A fixed height, so switching sections never moves the dialog under the pointer. -->
            <div class="flex h-[min(32rem,75vh)] flex-col sm:flex-row">
                <nav
                    class="flex shrink-0 gap-1 overflow-x-auto border-b p-2 sm:w-44 sm:flex-col sm:border-r sm:border-b-0"
                    role="tablist"
                    aria-label="Settings sections"
                >
                    <button
                        v-for="item in tabs"
                        :key="item.id"
                        type="button"
                        role="tab"
                        :aria-selected="tab === item.id"
                        :class="
                            cn(
                                'flex shrink-0 items-center gap-2 rounded-lg px-2.5 py-2 text-left text-[13px] font-medium whitespace-nowrap transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                tab === item.id
                                    ? 'bg-brand-soft text-brand-soft-foreground'
                                    : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                            )
                        "
                        :data-testid="`settings-tab-${item.id}`"
                        @click="emit('update:tab', item.id)"
                    >
                        <component :is="item.icon" class="size-4" />
                        {{ item.label }}
                    </button>
                </nav>

                <div
                    class="min-h-0 min-w-0 flex-1 overflow-y-auto p-5"
                    role="tabpanel"
                >
                    <!-- Audio -->
                    <div v-if="tab === 'audio'" class="flex flex-col gap-5">
                        <div class="grid gap-2">
                            <Label>Microphone</Label>
                            <DeviceSelect
                                label="Microphone"
                                :icon="Mic"
                                :options="audioInputs"
                                :model-value="
                                    resolveDevice(
                                        preferences.audioInputId,
                                        audioInputs,
                                    )
                                "
                                @update:model-value="
                                    (id) =>
                                        session.switchDevice('audioinput', id)
                                "
                            />
                            <MicLevelMeter
                                :level="micLevel"
                                :muted="!session.micEnabled.value"
                                :segments="24"
                            />
                            <p class="text-xs text-muted-foreground">
                                {{
                                    session.micEnabled.value
                                        ? 'Speak to check the level.'
                                        : 'Your microphone is muted.'
                                }}
                            </p>
                        </div>
                        <div v-if="canSelectOutput" class="grid gap-2">
                            <Label>Speaker</Label>
                            <DeviceSelect
                                label="Speaker"
                                :icon="Volume2"
                                :options="audioOutputs"
                                :model-value="
                                    resolveDevice(
                                        preferences.audioOutputId,
                                        audioOutputs,
                                    )
                                "
                                @update:model-value="
                                    (id) =>
                                        session.switchDevice('audiooutput', id)
                                "
                            />
                        </div>
                        <label class="flex items-start justify-between gap-4">
                            <span class="space-y-0.5">
                                <span class="block text-sm font-medium"
                                    >Noise suppression</span
                                >
                                <span
                                    class="block text-[13px] leading-relaxed text-muted-foreground"
                                >
                                    Filters steady background noise such as fans
                                    and typing. Turn it off if your voice sounds
                                    processed.
                                </span>
                            </span>
                            <Switch
                                :model-value="preferences.noiseSuppression"
                                @update:model-value="
                                    (value) =>
                                        session.setNoiseSuppression(value)
                                "
                            />
                        </label>
                        <p
                            class="rounded-lg bg-muted/60 px-3 py-2 text-xs leading-relaxed text-muted-foreground"
                        >
                            Echo cancellation and automatic gain are always on.
                            Your voice is sent at a fixed high quality and is
                            never reduced to make room for video.
                        </p>
                    </div>

                    <!-- Video -->
                    <div
                        v-else-if="tab === 'video'"
                        class="flex flex-col gap-5"
                    >
                        <div class="grid gap-2">
                            <Label>Camera</Label>
                            <DeviceSelect
                                label="Camera"
                                :icon="Video"
                                :options="videoInputs"
                                :model-value="
                                    resolveDevice(
                                        preferences.videoInputId,
                                        videoInputs,
                                    )
                                "
                                @update:model-value="
                                    (id) =>
                                        session.switchDevice('videoinput', id)
                                "
                            />
                        </div>
                        <fieldset class="grid gap-2">
                            <legend class="mb-2 text-sm font-medium">
                                Camera quality
                            </legend>
                            <div class="grid gap-2 sm:grid-cols-3">
                                <button
                                    v-for="option in VIDEO_QUALITIES"
                                    :key="option.value"
                                    type="button"
                                    role="radio"
                                    :aria-checked="
                                        preferences.videoQuality ===
                                        option.value
                                    "
                                    :class="
                                        cn(
                                            'rounded-xl border px-3 py-2.5 text-left transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                            preferences.videoQuality ===
                                                option.value
                                                ? 'border-brand bg-brand-soft/60'
                                                : 'hover:bg-accent',
                                        )
                                    "
                                    :data-testid="`video-quality-${option.value}`"
                                    @click="
                                        session.setVideoQuality(option.value)
                                    "
                                >
                                    <span class="block text-sm font-semibold">{{
                                        option.label
                                    }}</span>
                                    <span
                                        class="block text-xs leading-snug text-muted-foreground"
                                        >{{ option.hint }}</span
                                    >
                                </button>
                            </div>
                        </fieldset>
                        <label class="flex items-start justify-between gap-4">
                            <span class="space-y-0.5">
                                <span class="block text-sm font-medium"
                                    >Audio only</span
                                >
                                <span
                                    class="block text-[13px] leading-relaxed text-muted-foreground"
                                >
                                    Stops everyone's camera for you and switches
                                    yours off. Sound and shared screens stay.
                                    Useful on a weak connection.
                                </span>
                            </span>
                            <Switch
                                :model-value="session.audioOnly.value"
                                @update:model-value="
                                    (value) => session.setAudioOnly(value)
                                "
                            />
                        </label>
                    </div>

                    <!-- Effects -->
                    <div
                        v-else-if="tab === 'effects'"
                        class="flex flex-col gap-5"
                    >
                        <fieldset class="grid gap-2">
                            <legend class="mb-2 text-sm font-medium">
                                Camera effect
                            </legend>
                            <EffectPicker
                                :model-value="preferences.videoEffect"
                                :disabled="session.cameraBusy.value"
                                @update:model-value="
                                    (effect) => session.setVideoEffect(effect)
                                "
                            />
                        </fieldset>
                        <p
                            class="rounded-lg bg-muted/60 px-3 py-2 text-xs leading-relaxed text-muted-foreground"
                        >
                            Effects are made in your browser. Your face is
                            followed on this device and only the finished
                            picture is sent, to the meeting and to a recording.
                            With your camera off, an effect starts the next time
                            you switch it on.
                        </p>
                    </div>

                    <!-- Screen sharing -->
                    <div
                        v-else-if="tab === 'screen'"
                        class="flex flex-col gap-5"
                    >
                        <label class="flex items-start justify-between gap-4">
                            <span class="space-y-0.5">
                                <span class="block text-sm font-medium"
                                    >Optimise for motion</span
                                >
                                <span
                                    class="block text-[13px] leading-relaxed text-muted-foreground"
                                >
                                    For video and animation: smoother, at up to
                                    30 frames per second. Leave it off for code,
                                    documents and slides, which are sent as
                                    sharp as possible instead.
                                </span>
                            </span>
                            <Switch
                                :model-value="preferences.screenShareMotion"
                                data-testid="screen-motion"
                                @update:model-value="
                                    (value) =>
                                        session.setScreenShareMotion(value)
                                "
                            />
                        </label>
                        <p
                            class="rounded-lg bg-muted/60 px-3 py-2 text-xs leading-relaxed text-muted-foreground"
                        >
                            Your screen is shared at its own resolution, up to
                            4K, and is not scaled down. It is independent of the
                            camera quality setting. To share sound as well, pick
                            a browser tab and tick "Also share tab audio" in the
                            browser's dialog.
                        </p>
                    </div>

                    <!-- Connection -->
                    <div
                        v-else-if="tab === 'connection'"
                        class="flex flex-col gap-4"
                        data-testid="connection-stats"
                    >
                        <div
                            class="flex items-center gap-3 rounded-xl border px-4 py-3"
                        >
                            <QualityBars :level="quality" />
                            <div>
                                <p class="text-sm font-semibold">
                                    {{ QUALITY_LABEL[quality] }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    Measured on your audio, updated every two
                                    seconds.
                                </p>
                            </div>
                        </div>
                        <dl
                            class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border bg-border text-sm"
                        >
                            <div
                                v-for="row in [
                                    {
                                        label: 'Audio packet loss',
                                        value: metric(
                                            stats.audioLossPercent,
                                            '%',
                                        ),
                                        id: 'loss',
                                    },
                                    {
                                        label: 'Jitter',
                                        value: metric(stats.jitterMs, 'ms'),
                                        id: 'jitter',
                                    },
                                    {
                                        label: 'Round trip',
                                        value: metric(stats.roundTripMs, 'ms'),
                                        id: 'rtt',
                                    },
                                    {
                                        label: 'Route',
                                        value: route,
                                        id: 'route',
                                    },
                                    {
                                        label: 'Sending',
                                        value: metric(stats.sendKbps, 'kbit/s'),
                                        id: 'send',
                                    },
                                    {
                                        label: 'Receiving',
                                        value: metric(
                                            stats.receiveKbps,
                                            'kbit/s',
                                        ),
                                        id: 'receive',
                                    },
                                ]"
                                :key="row.label"
                                class="bg-card px-4 py-3"
                            >
                                <dt class="text-xs text-muted-foreground">
                                    {{ row.label }}
                                </dt>
                                <dd
                                    class="mt-0.5 font-medium tabular-nums"
                                    :data-testid="`stat-${row.id}`"
                                >
                                    {{ row.value }}
                                </dd>
                            </div>
                        </dl>
                        <p
                            class="text-xs leading-relaxed text-muted-foreground"
                        >
                            Live updates:
                            <span class="font-medium text-foreground">{{
                                state.realtimeHealthy.value
                                    ? 'connected'
                                    : 'checking every few seconds'
                            }}</span
                            >. "Relayed" means media travels through the relay
                            server because a direct path is blocked by a
                            firewall; it works, with a little more delay.
                        </p>
                    </div>

                    <!-- Shortcuts -->
                    <div v-else-if="tab === 'shortcuts'">
                        <ul class="divide-y">
                            <li
                                v-for="shortcut in shortcuts"
                                :key="shortcut.keys"
                                class="flex items-center justify-between py-2.5 text-sm"
                            >
                                <span>{{ shortcut.description }}</span>
                                <kbd
                                    class="rounded-md border bg-muted px-2 py-0.5 font-mono text-xs uppercase"
                                    >{{ shortcut.keys }}</kbd
                                >
                            </li>
                        </ul>
                        <p class="mt-3 text-xs text-muted-foreground">
                            Shortcuts are paused while you are typing in the
                            chat.
                        </p>
                    </div>

                    <!-- Meeting (hosts) -->
                    <div
                        v-else-if="tab === 'meeting' && settings"
                        class="flex flex-col gap-5"
                    >
                        <div class="grid gap-2">
                            <Label for="meeting-title">Meeting name</Label>
                            <Input
                                id="meeting-title"
                                v-model="title"
                                maxlength="200"
                                class="h-10 rounded-xl"
                                @blur="saveTitle"
                                @keydown.enter.prevent="saveTitle"
                            />
                        </div>

                        <label
                            v-for="toggle in [
                                {
                                    key: 'locked',
                                    label: 'Lock the meeting',
                                    hint: 'Nobody else can join, not even members.',
                                },
                                {
                                    key: 'waiting_room',
                                    label: 'Waiting room',
                                    hint: 'Guests wait until a host lets them in.',
                                },
                                {
                                    key: 'guest_uploads',
                                    label: 'Guests can share files',
                                    hint: 'Allow people without an account to upload in chat.',
                                },
                                {
                                    key: 'auto_email_transcript',
                                    label: 'Email the chat afterwards',
                                    hint: 'Send the meeting chat to everyone who attended once it ends.',
                                },
                            ] as const"
                            :key="toggle.key"
                            class="flex items-start justify-between gap-4"
                        >
                            <span class="space-y-0.5">
                                <span class="block text-sm font-medium">{{
                                    toggle.label
                                }}</span>
                                <span
                                    class="block text-[13px] text-muted-foreground"
                                    >{{ toggle.hint }}</span
                                >
                            </span>
                            <Switch
                                :model-value="settings[toggle.key]"
                                :disabled="saving"
                                :data-testid="`setting-${toggle.key}`"
                                @update:model-value="
                                    (value) => update({ [toggle.key]: value })
                                "
                            />
                        </label>

                        <label class="flex items-start justify-between gap-4">
                            <span class="space-y-0.5">
                                <span class="block text-sm font-medium"
                                    >Only hosts share their screen</span
                                >
                                <span
                                    class="block text-[13px] text-muted-foreground"
                                    >Takes effect for everyone
                                    immediately.</span
                                >
                            </span>
                            <Switch
                                :model-value="settings.screen_share === 'hosts'"
                                :disabled="saving"
                                data-testid="setting-screen_share"
                                @update:model-value="
                                    (value) =>
                                        update({
                                            screen_share: value
                                                ? 'hosts'
                                                : 'everyone',
                                        })
                                "
                            />
                        </label>

                        <label class="flex items-start justify-between gap-4">
                            <span class="space-y-0.5">
                                <span class="block text-sm font-medium"
                                    >Members can record</span
                                >
                                <span
                                    class="block text-[13px] text-muted-foreground"
                                    >Otherwise only hosts can. Guests never
                                    can.</span
                                >
                            </span>
                            <Switch
                                :model-value="settings.recording === 'members'"
                                :disabled="saving"
                                data-testid="setting-recording"
                                @update:model-value="
                                    (value) =>
                                        update({
                                            recording: value
                                                ? 'members'
                                                : 'hosts',
                                        })
                                "
                            />
                        </label>

                        <div class="grid gap-2 border-t pt-4">
                            <Label for="meeting-password">
                                Password
                                <span class="font-normal text-muted-foreground">
                                    ({{
                                        state.call.value?.has_password
                                            ? 'set'
                                            : 'none'
                                    }})
                                </span>
                            </Label>
                            <div class="flex gap-2">
                                <Input
                                    id="meeting-password"
                                    v-model="password"
                                    type="password"
                                    autocomplete="new-password"
                                    placeholder="At least 4 characters"
                                    class="h-10 rounded-xl"
                                    @keydown.enter.prevent="savePassword"
                                />
                                <Button
                                    variant="outline"
                                    class="h-10 rounded-xl"
                                    :disabled="saving || password.length < 4"
                                    @click="savePassword"
                                >
                                    Set
                                </Button>
                                <Button
                                    v-if="state.call.value?.has_password"
                                    variant="ghost"
                                    class="h-10 rounded-xl text-destructive"
                                    :disabled="saving"
                                    @click="update({ password: null })"
                                >
                                    Remove
                                </Button>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Guests are asked for it. Members and invited
                                people are not.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
