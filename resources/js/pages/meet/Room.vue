<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CircleSlash,
    Clock,
    DoorOpen,
    Hourglass,
    LogOut,
    MonitorSmartphone,
    PhoneOff,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import BrandMark from '@/components/BrandMark.vue';
import MeetingRoom from '@/components/meet/MeetingRoom.vue';
import PreJoin from '@/components/meet/PreJoin.vue';
import StatusScreen from '@/components/meet/StatusScreen.vue';
import { Button } from '@/components/ui/button';
import {
    hydrateMeetPreferences,
    provideMeetPreferences,
} from '@/composables/meet/useMeetPreferences';
import type { SessionEnd } from '@/composables/meet/useRoomSession';
import { useBranding } from '@/composables/useBranding';
import { api, HttpError } from '@/lib/http';
import { meetEndpoints } from '@/lib/meet/endpoints';
import type { JoinPhase } from '@/lib/meet/types';
import type { JoinRefusal, JoinStanding, MeetCall, MeetViewer } from '@/types';

/**
 * /meet/{code}: the one page a meeting link opens, for members and guests alike. It walks
 * through pre-join, the waiting room and the meeting itself, and explains every way that
 * can end.
 */
const props = defineProps<{
    call: MeetCall;
    viewer: MeetViewer;
    media: { configured: boolean };
}>();

const branding = useBranding();
const endpoints = meetEndpoints(props.call.code);
const preferences = provideMeetPreferences();

const initialPhase = (): JoinPhase => {
    if (props.call.status === 'ended') {
        return 'ended';
    }

    switch (props.viewer.status) {
        case 'removed':
            return 'removed';
        case 'denied':
            return 'denied';
        case 'waiting':
            return 'waiting';
        default:
            return 'prejoin';
    }
};

const phase = ref<JoinPhase>(initialPhase());
const callUuid = ref<string | null>(props.call.uuid);
const joining = ref(false);
const joinError = ref<string | null>(null);
const passwordRejected = ref(false);
const leftBecause = ref<'left' | 'duplicate' | 'refused'>('left');
const refusedMessage = ref<string | null>(null);
const displayName = ref(props.viewer.name ?? '');

let pollTimer: ReturnType<typeof setTimeout> | undefined;

const applyStanding = (standing: JoinStanding) => {
    if (standing.call_status === 'ended') {
        phase.value = 'ended';

        return;
    }

    switch (standing.status) {
        case 'admitted':
        case 'joined':
        case 'left':
            callUuid.value = standing.call_uuid;
            displayName.value = standing.participant.name;
            phase.value = standing.call_uuid ? 'room' : 'prejoin';
            break;
        case 'waiting':
            phase.value = 'waiting';
            pollStanding();
            break;
        case 'denied':
            phase.value = 'denied';
            break;
        case 'removed':
            phase.value = 'removed';
            break;
        default:
            phase.value = 'prejoin';
    }
};

/** While waiting, ask every couple of seconds whether a host has answered. */
const pollStanding = () => {
    clearTimeout(pollTimer);

    pollTimer = setTimeout(async () => {
        if (phase.value !== 'waiting') {
            return;
        }

        try {
            applyStanding(await api.get<JoinStanding>(endpoints.status()));
        } catch {
            // Offline for a moment: keep waiting.
            pollStanding();
        }
    }, 2500);
};

const join = async (payload: { name: string; password: string | null }) => {
    joining.value = true;
    joinError.value = null;
    passwordRejected.value = false;

    try {
        applyStanding(
            await api.post<JoinStanding>(endpoints.join(), {
                name: payload.name || undefined,
                password: payload.password ?? undefined,
                invite: props.viewer.invite ?? undefined,
            }),
        );
    } catch (error) {
        if (error instanceof HttpError) {
            const refusal = error.body as Partial<JoinRefusal>;

            if (refusal.reason === 'ended') {
                phase.value = 'ended';
            } else if (refusal.reason === 'removed') {
                phase.value = 'removed';
            } else {
                passwordRejected.value = refusal.reason === 'password';
                joinError.value =
                    Object.values(error.errors)[0]?.[0] ??
                    error.message ??
                    'You could not join. Try again.';
            }
        } else {
            joinError.value =
                'The meeting could not be reached. Check your connection.';
        }
    } finally {
        joining.value = false;
    }
};

const handleEnd = (end: SessionEnd) => {
    switch (end.kind) {
        case 'ended':
            phase.value = 'ended';
            break;
        case 'removed':
            phase.value = 'removed';
            break;
        case 'duplicate':
            leftBecause.value = 'duplicate';
            phase.value = 'left';
            break;
        case 'refused':
            leftBecause.value = 'refused';
            refusedMessage.value = end.message;
            phase.value = 'left';
            break;
        default:
            leftBecause.value = 'left';
            phase.value = 'left';
    }
};

const rejoin = () => {
    joinError.value = null;
    phase.value = 'prejoin';
};

onMounted(() => {
    hydrateMeetPreferences(preferences);

    if (phase.value === 'waiting') {
        pollStanding();
    }
});

onBeforeUnmount(() => clearTimeout(pollTimer));

const inRoom = computed(
    () => phase.value === 'room' && callUuid.value !== null,
);
const firstName = computed(() => displayName.value.trim().split(' ')[0] ?? '');
</script>

<template>
    <Head :title="call.title" />

    <MeetingRoom
        v-if="inRoom"
        :code="call.code"
        :call-uuid="callUuid as string"
        :title="call.title"
        :history-url="call.history_url"
        :calls-url="viewer.calls_url"
        @end="handleEnd"
    />

    <div v-else class="flex min-h-0 flex-1 flex-col overflow-y-auto">
        <header
            class="flex shrink-0 items-center justify-between px-5 py-4 sm:px-8"
        >
            <component
                :is="viewer.calls_url ? Link : 'span'"
                :href="viewer.calls_url ?? undefined"
                class="flex items-center gap-2.5 rounded-lg outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
            >
                <BrandMark :size="26" />
                <span class="text-[15px] font-semibold tracking-tight">{{
                    branding.name
                }}</span>
            </component>
            <span
                class="rounded-full border bg-card px-3 py-1 font-mono text-xs text-muted-foreground"
                >{{ call.code }}</span
            >
        </header>

        <main class="flex flex-1 items-center justify-center pb-10">
            <PreJoin
                v-if="phase === 'prejoin'"
                :call="call"
                :viewer="viewer"
                :joining="joining"
                :error="joinError"
                :password-rejected="passwordRejected"
                :media-configured="media.configured"
                @join="join"
            />

            <StatusScreen
                v-else-if="phase === 'waiting'"
                :icon="Hourglass"
                title="Waiting to be let in"
                tone="brand"
                pulse
            >
                A host of
                <span class="font-medium text-foreground">{{
                    call.title
                }}</span>
                has been told you are here. This page moves on by itself as soon
                as they let you in.
            </StatusScreen>

            <StatusScreen
                v-else-if="phase === 'denied'"
                :icon="CircleSlash"
                title="You were not let in"
                tone="danger"
            >
                A host declined your request to join this meeting. If you think
                that was a mistake, ask them to send you an invitation.
            </StatusScreen>

            <StatusScreen
                v-else-if="phase === 'removed'"
                :icon="DoorOpen"
                title="You were removed from the meeting"
                tone="danger"
            >
                A host removed you, so this link no longer lets you back in.
            </StatusScreen>

            <StatusScreen
                v-else-if="phase === 'ended'"
                :icon="PhoneOff"
                title="This meeting has ended"
            >
                Thanks for joining.
                <template v-if="call.history_url">
                    The chat, shared files and any recording are in the
                    meeting's history.
                </template>
                <template #actions>
                    <Button v-if="call.history_url" as-child class="rounded-xl">
                        <Link :href="call.history_url"
                            ><Clock /> Open meeting history</Link
                        >
                    </Button>
                    <Button
                        v-if="viewer.calls_url"
                        as-child
                        variant="outline"
                        class="rounded-xl"
                    >
                        <Link :href="viewer.calls_url">Back to Calls</Link>
                    </Button>
                </template>
            </StatusScreen>

            <StatusScreen
                v-else-if="phase === 'left' && leftBecause === 'duplicate'"
                :icon="MonitorSmartphone"
                title="You joined from somewhere else"
            >
                This meeting is now open in another tab or on another device, so
                it was closed here.
                <template #actions>
                    <Button class="rounded-xl" @click="rejoin"
                        >Use this tab instead</Button
                    >
                </template>
            </StatusScreen>

            <StatusScreen
                v-else-if="phase === 'left' && leftBecause === 'refused'"
                :icon="CircleSlash"
                title="You could not rejoin"
                tone="danger"
            >
                {{ refusedMessage }}
                <template #actions>
                    <Button class="rounded-xl" @click="rejoin"
                        >Try again</Button
                    >
                </template>
            </StatusScreen>

            <StatusScreen v-else :icon="LogOut" title="You left the meeting">
                {{
                    firstName
                        ? `See you next time, ${firstName}.`
                        : 'See you next time.'
                }}
                <template #actions>
                    <Button
                        class="rounded-xl"
                        data-testid="rejoin"
                        @click="rejoin"
                        >Rejoin</Button
                    >
                    <Button
                        v-if="viewer.calls_url"
                        as-child
                        variant="outline"
                        class="rounded-xl"
                    >
                        <Link :href="viewer.calls_url">Back to Calls</Link>
                    </Button>
                </template>
            </StatusScreen>
        </main>
    </div>
</template>
