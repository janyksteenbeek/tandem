<script setup lang="ts">
import {
    AudioLines,
    Check,
    ChevronUp,
    Circle,
    Headphones,
    Keyboard,
    Link2,
    Mail,
    Maximize,
    MessageSquare,
    Mic,
    MicOff,
    MonitorUp,
    MonitorX,
    MoreVertical,
    PhoneOff,
    Settings,
    Sparkles,
    Square,
    Users,
    Video,
    VideoOff,
} from '@lucide/vue';
import { computed } from 'vue';
import ControlButton from '@/components/meet/ControlButton.vue';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { CallState } from '@/composables/meet/useCallState';
import type { RoomSession } from '@/composables/meet/useRoomSession';
import { resolveDevice } from '@/lib/meet/preferences';
import type { DeviceOption } from '@/lib/meet/types';
import { cn } from '@/lib/utils';
import type { RecordingMode } from '@/types';

/**
 * The meeting toolbar: microphone, camera, screen, the two panels, recording, everything
 * else behind one menu, and the way out.
 */
const props = defineProps<{
    session: RoomSession;
    state: CallState;
    micLevel: number;
    chatOpen: boolean;
    peopleOpen: boolean;
    unread: number;
    audioInputs: DeviceOption[];
    videoInputs: DeviceOption[];
    audioOutputs: DeviceOption[];
    canSelectOutput: boolean;
    preferredAudioInput: string | null;
    preferredVideoInput: string | null;
    preferredAudioOutput: string | null;
}>();

const emit = defineEmits<{
    toggleChat: [];
    togglePeople: [];
    settings: [tab: string];
    leave: [];
    end: [];
    copyLink: [];
    invite: [];
    record: [mode: RecordingMode];
    stopRecording: [];
    fullscreen: [];
}>();

const me = computed(() => props.state.me.value);
const recording = computed(() => props.state.recording.value);
const recordingActive = computed(() => recording.value?.is_active ?? false);
const canShare = computed(
    () =>
        (me.value?.can.share_screen ?? false) &&
        props.session.canPublishScreen.value,
);
const supportsScreenShare = computed(
    () =>
        typeof navigator !== 'undefined' &&
        !!navigator.mediaDevices?.getDisplayMedia,
);
const waitingCount = computed(() => props.state.waiting.value.length);

const activeMic = computed(() =>
    resolveDevice(props.preferredAudioInput, props.audioInputs),
);
const activeCamera = computed(() =>
    resolveDevice(props.preferredVideoInput, props.videoInputs),
);
const activeSpeaker = computed(() =>
    resolveDevice(props.preferredAudioOutput, props.audioOutputs),
);

const RECORDING_MODES: { mode: RecordingMode; label: string; hint: string }[] =
    [
        {
            mode: 'full',
            label: 'Full meeting',
            hint: 'Voices, cameras and shared screens',
        },
        {
            mode: 'screen',
            label: 'Screen and audio',
            hint: 'Shared screens with the conversation',
        },
        { mode: 'audio', label: 'Audio only', hint: 'Just the conversation' },
    ];

const chevronClass =
    'flex h-11 w-6 items-center justify-center rounded-r-xl bg-foreground/[0.06] text-muted-foreground outline-none transition-colors hover:bg-foreground/[0.11] hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/60 dark:bg-white/[0.08] dark:hover:bg-white/[0.14]';
</script>

<template>
    <div
        class="flex shrink-0 justify-center px-3 pt-1 pb-3 sm:pb-4"
        role="toolbar"
        aria-label="Meeting controls"
    >
        <div
            class="flex max-w-full items-center gap-1.5 rounded-2xl border bg-card/95 p-1.5 shadow-float backdrop-blur-xl sm:gap-2 sm:p-2"
        >
            <!-- Microphone -->
            <div class="flex items-center">
                <ControlButton
                    :label="
                        session.micEnabled.value
                            ? 'Mute microphone'
                            : 'Unmute microphone'
                    "
                    :icon="session.micEnabled.value ? Mic : MicOff"
                    :tone="session.micEnabled.value ? 'default' : 'off'"
                    :pressed="session.micEnabled.value"
                    :busy="session.micBusy.value"
                    shortcut="M"
                    class="sm:rounded-r-none"
                    data-testid="toggle-mic"
                    @click="session.setMicrophone(!session.micEnabled.value)"
                >
                </ControlButton>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            aria-label="Microphone and speaker options"
                            :class="cn(chevronClass, 'relative hidden sm:flex')"
                        >
                            <ChevronUp class="size-3.5" />
                            <!-- A live level, so you can see you are being heard. -->
                            <span
                                v-if="session.micEnabled.value"
                                class="absolute bottom-1.5 left-1/2 h-1 w-3 -translate-x-1/2 overflow-hidden rounded-full bg-foreground/15"
                                aria-hidden="true"
                            >
                                <span
                                    class="block h-full rounded-full bg-success transition-[width] duration-75"
                                    :style="{
                                        width: `${Math.min(100, micLevel * 140)}%`,
                                    }"
                                />
                            </span>
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent side="top" align="start" class="w-72">
                        <DropdownMenuLabel class="text-xs text-muted-foreground"
                            >Microphone</DropdownMenuLabel
                        >
                        <DropdownMenuItem
                            v-for="device in audioInputs"
                            :key="device.id"
                            class="gap-2"
                            @click="
                                session.switchDevice('audioinput', device.id)
                            "
                        >
                            <Check
                                class="size-3.5"
                                :class="
                                    device.id === activeMic
                                        ? 'text-brand'
                                        : 'opacity-0'
                                "
                            />
                            <span class="truncate">{{ device.label }}</span>
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="audioInputs.length === 0"
                            disabled
                        >
                            No microphone found
                        </DropdownMenuItem>
                        <template
                            v-if="canSelectOutput && audioOutputs.length > 0"
                        >
                            <DropdownMenuSeparator />
                            <DropdownMenuLabel
                                class="text-xs text-muted-foreground"
                                >Speaker</DropdownMenuLabel
                            >
                            <DropdownMenuItem
                                v-for="device in audioOutputs"
                                :key="device.id"
                                class="gap-2"
                                @click="
                                    session.switchDevice(
                                        'audiooutput',
                                        device.id,
                                    )
                                "
                            >
                                <Check
                                    class="size-3.5"
                                    :class="
                                        device.id === activeSpeaker
                                            ? 'text-brand'
                                            : 'opacity-0'
                                    "
                                />
                                <span class="truncate">{{ device.label }}</span>
                            </DropdownMenuItem>
                        </template>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            class="gap-2"
                            @click="emit('settings', 'audio')"
                        >
                            <AudioLines class="size-4" /> Audio settings
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <!-- Camera -->
            <div class="flex items-center">
                <ControlButton
                    :label="
                        session.cameraEnabled.value
                            ? 'Turn camera off'
                            : 'Turn camera on'
                    "
                    :icon="session.cameraEnabled.value ? Video : VideoOff"
                    :tone="session.cameraEnabled.value ? 'default' : 'off'"
                    :pressed="session.cameraEnabled.value"
                    :busy="session.cameraBusy.value"
                    shortcut="V"
                    class="sm:rounded-r-none"
                    data-testid="toggle-camera"
                    @click="session.setCamera(!session.cameraEnabled.value)"
                />
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            aria-label="Camera options"
                            :class="cn(chevronClass, 'hidden sm:flex')"
                        >
                            <ChevronUp class="size-3.5" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent side="top" align="start" class="w-72">
                        <DropdownMenuLabel class="text-xs text-muted-foreground"
                            >Camera</DropdownMenuLabel
                        >
                        <DropdownMenuItem
                            v-for="device in videoInputs"
                            :key="device.id"
                            class="gap-2"
                            @click="
                                session.switchDevice('videoinput', device.id)
                            "
                        >
                            <Check
                                class="size-3.5"
                                :class="
                                    device.id === activeCamera
                                        ? 'text-brand'
                                        : 'opacity-0'
                                "
                            />
                            <span class="truncate">{{ device.label }}</span>
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="videoInputs.length === 0"
                            disabled
                        >
                            No camera found
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            class="gap-2"
                            data-testid="open-effects"
                            @click="emit('settings', 'effects')"
                        >
                            <Sparkles class="size-4" /> Effects
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            class="gap-2"
                            @click="emit('settings', 'video')"
                        >
                            <Video class="size-4" /> Video settings
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <!-- Screen share -->
            <ControlButton
                v-if="canShare && supportsScreenShare"
                :label="
                    session.screenSharing.value
                        ? 'Stop sharing your screen'
                        : 'Share your screen'
                "
                :icon="session.screenSharing.value ? MonitorX : MonitorUp"
                :tone="session.screenSharing.value ? 'active' : 'default'"
                :pressed="session.screenSharing.value"
                :busy="session.screenBusy.value"
                shortcut="S"
                class="hidden sm:flex"
                data-testid="toggle-screen"
                @click="session.setScreenShare(!session.screenSharing.value)"
            />

            <!-- Record -->
            <DropdownMenu v-if="me?.can.record">
                <DropdownMenuTrigger as-child>
                    <span class="hidden sm:block">
                        <ControlButton
                            :label="recordingActive ? 'Recording…' : 'Record'"
                            :icon="recordingActive ? Square : Circle"
                            :tone="recordingActive ? 'recording' : 'default'"
                            :pressed="recordingActive"
                            data-testid="record"
                        />
                    </span>
                </DropdownMenuTrigger>
                <DropdownMenuContent side="top" align="center" class="w-72">
                    <template v-if="recordingActive">
                        <DropdownMenuLabel
                            class="text-xs font-normal text-muted-foreground"
                        >
                            Recording on the server
                            {{
                                recording?.started_by
                                    ? `· started by ${recording.started_by}`
                                    : ''
                            }}
                        </DropdownMenuLabel>
                        <DropdownMenuItem
                            class="gap-2 text-destructive focus:text-destructive"
                            data-testid="stop-recording"
                            @click="emit('stopRecording')"
                        >
                            <Square class="size-4" /> Stop recording
                        </DropdownMenuItem>
                    </template>
                    <template v-else>
                        <DropdownMenuLabel
                            class="text-xs font-normal text-muted-foreground"
                        >
                            Recorded on the server. It keeps going if you leave.
                        </DropdownMenuLabel>
                        <DropdownMenuItem
                            v-for="option in RECORDING_MODES"
                            :key="option.mode"
                            class="flex-col items-start gap-0"
                            :data-testid="`record-${option.mode}`"
                            @click="emit('record', option.mode)"
                        >
                            <span class="font-medium">{{ option.label }}</span>
                            <span class="text-xs text-muted-foreground">{{
                                option.hint
                            }}</span>
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>

            <span
                class="mx-0.5 hidden h-7 w-px bg-border sm:block"
                aria-hidden="true"
            />

            <!-- Panels -->
            <ControlButton
                :label="chatOpen ? 'Close chat' : 'Open chat'"
                :icon="MessageSquare"
                :tone="chatOpen ? 'active' : 'default'"
                :pressed="chatOpen"
                :badge="chatOpen ? null : unread"
                shortcut="C"
                data-testid="toggle-chat"
                @click="emit('toggleChat')"
            />
            <ControlButton
                :label="peopleOpen ? 'Close people' : 'People'"
                :icon="Users"
                :tone="peopleOpen ? 'active' : 'default'"
                :pressed="peopleOpen"
                :badge="peopleOpen ? null : waitingCount"
                shortcut="P"
                class="hidden sm:flex"
                data-testid="toggle-people"
                @click="emit('togglePeople')"
            />

            <!-- Everything else -->
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <span>
                        <ControlButton
                            label="More"
                            :icon="MoreVertical"
                            :badge="
                                waitingCount > 0 && !peopleOpen
                                    ? waitingCount
                                    : null
                            "
                            class="sm:[&>span]:hidden"
                            data-testid="more"
                        />
                    </span>
                </DropdownMenuTrigger>
                <DropdownMenuContent side="top" align="end" class="w-64">
                    <!-- Things the wide toolbar shows as buttons -->
                    <div class="sm:hidden">
                        <DropdownMenuItem
                            class="gap-2"
                            @click="emit('togglePeople')"
                        >
                            <Users class="size-4" /> People
                            <span
                                v-if="waitingCount"
                                class="ml-auto rounded-full bg-brand px-1.5 text-[10px] font-semibold text-brand-foreground"
                                >{{ waitingCount }}</span
                            >
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="canShare && supportsScreenShare"
                            class="gap-2"
                            @click="
                                session.setScreenShare(
                                    !session.screenSharing.value,
                                )
                            "
                        >
                            <MonitorUp class="size-4" />
                            {{
                                session.screenSharing.value
                                    ? 'Stop sharing'
                                    : 'Share your screen'
                            }}
                        </DropdownMenuItem>
                        <template v-if="me?.can.record">
                            <DropdownMenuItem
                                v-if="recordingActive"
                                class="gap-2 text-destructive focus:text-destructive"
                                @click="emit('stopRecording')"
                            >
                                <Square class="size-4" /> Stop recording
                            </DropdownMenuItem>
                            <DropdownMenuSub v-else>
                                <DropdownMenuSubTrigger class="gap-2">
                                    <Circle class="size-4" /> Record
                                </DropdownMenuSubTrigger>
                                <DropdownMenuSubContent>
                                    <DropdownMenuItem
                                        v-for="option in RECORDING_MODES"
                                        :key="option.mode"
                                        @click="emit('record', option.mode)"
                                    >
                                        {{ option.label }}
                                    </DropdownMenuItem>
                                </DropdownMenuSubContent>
                            </DropdownMenuSub>
                        </template>
                        <DropdownMenuSeparator />
                    </div>

                    <DropdownMenuItem
                        class="gap-2"
                        data-testid="copy-link"
                        @click="emit('copyLink')"
                    >
                        <Link2 class="size-4" /> Copy meeting link
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="me?.can.invite"
                        class="gap-2"
                        @click="emit('invite')"
                    >
                        <Mail class="size-4" /> Invite by email
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuCheckboxItem
                        :model-value="session.audioOnly.value"
                        class="gap-2"
                        data-testid="audio-only"
                        @update:model-value="
                            (value) => session.setAudioOnly(value === true)
                        "
                    >
                        <Headphones class="size-4" />
                        <span class="flex flex-col">
                            <span>Audio only</span>
                            <span class="text-xs text-muted-foreground"
                                >Stop cameras, keep sound and screens</span
                            >
                        </span>
                    </DropdownMenuCheckboxItem>
                    <DropdownMenuItem class="gap-2" @click="emit('fullscreen')">
                        <Maximize class="size-4" /> Full screen
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        class="gap-2"
                        @click="emit('settings', 'shortcuts')"
                    >
                        <Keyboard class="size-4" /> Keyboard shortcuts
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        class="gap-2"
                        data-testid="open-settings"
                        @click="emit('settings', 'audio')"
                    >
                        <Settings class="size-4" /> Settings
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <span class="mx-0.5 h-7 w-px bg-border" aria-hidden="true" />

            <!-- Leave -->
            <DropdownMenu v-if="me?.can.manage">
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        aria-label="Leave or end the meeting"
                        class="flex h-11 items-center gap-2 rounded-xl bg-destructive px-3.5 text-sm font-semibold text-white transition-[background-color,transform] outline-none hover:bg-destructive/90 focus-visible:ring-[3px] focus-visible:ring-destructive/40 active:scale-[0.96] sm:px-4"
                        data-testid="leave"
                    >
                        <PhoneOff class="size-[18px]" />
                        <span class="hidden sm:inline">Leave</span>
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent side="top" align="end" class="w-64">
                    <DropdownMenuItem
                        class="flex-col items-start gap-0"
                        data-testid="leave-only"
                        @click="emit('leave')"
                    >
                        <span class="font-medium">Leave meeting</span>
                        <span class="text-xs text-muted-foreground"
                            >The meeting continues without you</span
                        >
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        class="flex-col items-start gap-0 text-destructive focus:text-destructive"
                        data-testid="end-for-all"
                        @click="emit('end')"
                    >
                        <span class="font-medium"
                            >End meeting for everyone</span
                        >
                        <span class="text-xs opacity-80"
                            >Everybody is disconnected</span
                        >
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <button
                v-else
                type="button"
                aria-label="Leave meeting"
                class="flex h-11 items-center gap-2 rounded-xl bg-destructive px-3.5 text-sm font-semibold text-white transition-[background-color,transform] outline-none hover:bg-destructive/90 focus-visible:ring-[3px] focus-visible:ring-destructive/40 active:scale-[0.96] sm:px-4"
                data-testid="leave"
                @click="emit('leave')"
            >
                <PhoneOff class="size-[18px]" />
                <span class="hidden sm:inline">Leave</span>
            </button>
        </div>
    </div>
</template>
