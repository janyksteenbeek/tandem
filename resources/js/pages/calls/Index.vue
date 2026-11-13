<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    AudioLines,
    Clapperboard,
    Clock,
    Link2,
    MessageSquareText,
    MonitorPlay,
    SlidersHorizontal,
    Users,
    Video,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CallSettingsDialog from '@/components/calls/CallSettingsDialog.vue';
import DiagnosticsDialog from '@/components/calls/DiagnosticsDialog.vue';
import JoinMeetingField from '@/components/calls/JoinMeetingField.vue';
import Pagination from '@/components/calls/Pagination.vue';
import StartMeetingButton from '@/components/calls/StartMeetingButton.vue';
import StatusPill from '@/components/calls/StatusPill.vue';
import WorkspaceHeader from '@/components/projects/workspace/WorkspaceHeader.vue';
import type { WorkspaceTab } from '@/components/projects/workspace/WorkspaceTabs.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { copyText } from '@/composables/calls/useCallStarter';
import {
    CALL_STATUS,
    RECORDING_STATUS,
    formatClock,
    formatDuration,
    formatSize,
    plural,
} from '@/lib/calls/format';
import type { Paginated, RecordingListItem } from '@/lib/calls/types';
import { fullTime } from '@/lib/time';
import { index, show } from '@/routes/calls';
import { show as showRecording } from '@/routes/calls/recordings';
import type { CallOrganizationSettings, CallSummary } from '@/types';

const props = defineProps<{
    tab: 'meetings' | 'recordings';
    open: CallSummary[];
    recent: Paginated<CallSummary>;
    recordings: Paginated<RecordingListItem> | null;
    settings: CallOrganizationSettings;
    can: { manage: boolean };
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');

const settingsOpen = ref(false);
const diagnosticsOpen = ref(false);

const tabs = computed<WorkspaceTab[]>(() => [
    {
        key: 'meetings',
        label: 'Meetings',
        href: index.url(slug.value),
        only: ['tab', 'open', 'recent'],
    },
    {
        key: 'recordings',
        label: 'Recordings',
        href: index.url(slug.value, { query: { tab: 'recordings' } }),
        only: ['tab', 'recordings'],
    },
]);

const recordingIcon = (recording: RecordingListItem) =>
    recording.mode === 'audio'
        ? AudioLines
        : recording.has_screen_share || recording.mode === 'screen'
          ? MonitorPlay
          : Clapperboard;

const recordingHref = (recording: RecordingListItem) =>
    recording.is_playable
        ? showRecording.url([slug.value, recording.call.uuid, recording.uuid])
        : show.url([slug.value, recording.call.uuid]);

const meetingsEmpty = computed(
    () => props.open.length === 0 && props.recent.total === 0,
);
</script>

<template>
    <Head title="Calls" />

    <div class="flex h-full min-h-0 flex-col">
        <WorkspaceHeader
            :breadcrumbs="[{ label: 'Calls' }]"
            subtitle="Meet, share your screen, and keep the chat and recordings afterwards."
            :tabs="tabs"
            :active-tab="tab"
        >
            <template v-if="can.manage" #actions>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label="Diagnostics"
                            @click="diagnosticsOpen = true"
                            ><Activity class="size-4"
                        /></Button>
                    </TooltipTrigger>
                    <TooltipContent>Diagnostics</TooltipContent>
                </Tooltip>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label="Call settings"
                            @click="settingsOpen = true"
                            ><SlidersHorizontal class="size-4"
                        /></Button>
                    </TooltipTrigger>
                    <TooltipContent>Call settings</TooltipContent>
                </Tooltip>
            </template>
        </WorkspaceHeader>

        <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto">
            <div class="mx-auto max-w-5xl px-4 py-5 sm:px-6">
                <!-- Meetings -->
                <template v-if="tab === 'meetings'">
                    <section
                        class="calls-hero relative overflow-hidden rounded-2xl border px-5 py-6 sm:px-8 sm:py-8"
                        aria-labelledby="calls-hero-title"
                    >
                        <div
                            class="relative grid items-center gap-6 md:grid-cols-[1.1fr_1fr] md:gap-10"
                        >
                            <div>
                                <h2
                                    id="calls-hero-title"
                                    class="font-display text-[26px] leading-tight font-semibold tracking-[-0.02em] sm:text-3xl"
                                >
                                    Talk it through.
                                </h2>
                                <p
                                    class="mt-1.5 max-w-sm text-[13.5px] text-muted-foreground"
                                >
                                    One click and you are in. Share the link
                                    with anyone; guests need no account.
                                </p>
                                <div class="mt-5">
                                    <StartMeetingButton
                                        size="lg"
                                        :defaults="settings.defaults"
                                    />
                                </div>
                            </div>
                            <div
                                class="rounded-xl border bg-background/70 p-4 backdrop-blur-sm"
                            >
                                <p class="text-[13px] font-medium">
                                    Have a code or a link?
                                </p>
                                <p
                                    class="mb-3 text-[12px] text-muted-foreground"
                                >
                                    Paste it here to join the meeting.
                                </p>
                                <JoinMeetingField />
                            </div>
                        </div>
                    </section>

                    <section
                        v-if="open.length > 0"
                        class="mt-7"
                        aria-labelledby="calls-open-title"
                    >
                        <h2
                            id="calls-open-title"
                            class="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Open now
                        </h2>
                        <ul class="space-y-2">
                            <li
                                v-for="call in open"
                                :key="call.uuid"
                                class="flex items-center gap-3 rounded-xl border bg-card px-3.5 py-3 shadow-xs"
                                data-test="open-call"
                            >
                                <span
                                    :class="[
                                        'flex size-9 shrink-0 items-center justify-center rounded-lg',
                                        call.status === 'live'
                                            ? 'bg-rose-500/10 text-rose-600 dark:text-rose-300'
                                            : 'bg-muted text-muted-foreground',
                                    ]"
                                    ><Video class="size-4.5"
                                /></span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <Link
                                            :href="call.url"
                                            class="truncate text-[14px] font-medium hover:underline"
                                            >{{ call.display_title }}</Link
                                        >
                                        <StatusPill
                                            :meta="CALL_STATUS[call.status]"
                                            :pulse="call.status === 'live'"
                                        />
                                    </div>
                                    <p
                                        class="mt-0.5 flex items-center gap-1.5 truncate text-[12px] text-muted-foreground"
                                    >
                                        <span class="font-mono">{{
                                            call.code
                                        }}</span>
                                        <template v-if="call.host"
                                            ><span aria-hidden="true">·</span>
                                            {{ call.host.name }}</template
                                        >
                                        <template v-if="call.status === 'live'"
                                            ><span aria-hidden="true">·</span>
                                            started
                                            {{
                                                fullTime(call.started_at)
                                            }}</template
                                        >
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-1.5">
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon-sm"
                                                :aria-label="`Copy link to ${call.display_title}`"
                                                @click="
                                                    copyText(
                                                        call.join_url,
                                                        'Meeting link copied',
                                                    )
                                                "
                                                ><Link2 class="size-4"
                                            /></Button>
                                        </TooltipTrigger>
                                        <TooltipContent
                                            >Copy meeting link</TooltipContent
                                        >
                                    </Tooltip>
                                    <Button
                                        as-child
                                        size="sm"
                                        class="h-8 px-4 text-brand-foreground brand-button-surface"
                                    >
                                        <a :href="call.join_url">{{
                                            call.status === 'live'
                                                ? 'Join'
                                                : 'Open'
                                        }}</a>
                                    </Button>
                                </div>
                            </li>
                        </ul>
                    </section>

                    <section class="mt-7" aria-labelledby="calls-recent-title">
                        <h2
                            id="calls-recent-title"
                            class="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Recent meetings
                        </h2>

                        <div
                            v-if="recent.data.length === 0"
                            class="flex flex-col items-center rounded-xl border border-dashed px-6 py-12 text-center"
                        >
                            <span
                                class="mb-3 flex size-11 items-center justify-center rounded-2xl bg-brand-soft text-brand-soft-foreground"
                                ><Clock class="size-5"
                            /></span>
                            <p class="font-display text-xl">
                                {{
                                    meetingsEmpty
                                        ? 'No meetings yet.'
                                        : 'Nothing has finished yet.'
                                }}
                            </p>
                            <p
                                class="mt-1 max-w-sm text-[13px] text-muted-foreground"
                            >
                                Meetings you hosted or joined show up here when
                                they end, with their chat, files and recordings.
                            </p>
                        </div>

                        <ul v-else class="-mx-2 space-y-px">
                            <li v-for="call in recent.data" :key="call.uuid">
                                <Link
                                    :href="call.url"
                                    class="issue-row h-auto min-h-12 gap-3 py-2"
                                    data-test="recent-call"
                                >
                                    <UserAvatar
                                        :name="call.host?.name ?? '?'"
                                        :src="call.host?.avatar ?? null"
                                        :initials="call.host?.initials ?? null"
                                        size="sm"
                                    />
                                    <span class="min-w-0 flex-1">
                                        <span
                                            class="block truncate text-[13.5px] font-medium"
                                            >{{ call.display_title }}</span
                                        >
                                        <span
                                            class="block truncate text-[12px] text-muted-foreground"
                                        >
                                            {{
                                                fullTime(
                                                    call.started_at ??
                                                        call.ended_at ??
                                                        call.created_at,
                                                )
                                            }}
                                            <template
                                                v-if="
                                                    call.started_at &&
                                                    call.duration_seconds !==
                                                        null
                                                "
                                            >
                                                ·
                                                {{
                                                    formatDuration(
                                                        call.duration_seconds,
                                                    )
                                                }}</template
                                            >
                                            <template v-else>
                                                · nobody joined</template
                                            >
                                        </span>
                                    </span>
                                    <span
                                        class="flex shrink-0 items-center gap-3 text-[12px] text-muted-foreground tabular-nums"
                                    >
                                        <span
                                            v-if="call.recording_count > 0"
                                            class="flex items-center gap-1 text-brand"
                                            :title="
                                                plural(
                                                    call.recording_count,
                                                    'recording',
                                                )
                                            "
                                            ><Clapperboard
                                                class="size-3.5"
                                            /><span class="sr-only"
                                                >Recordings:</span
                                            >{{ call.recording_count }}</span
                                        >
                                        <span
                                            v-if="call.message_count > 0"
                                            class="hidden items-center gap-1 sm:flex"
                                            :title="
                                                plural(
                                                    call.message_count,
                                                    'message',
                                                )
                                            "
                                            ><MessageSquareText
                                                class="size-3.5"
                                            /><span class="sr-only"
                                                >Messages:</span
                                            >{{ call.message_count }}</span
                                        >
                                        <span
                                            class="flex w-9 items-center justify-end gap-1"
                                            :title="
                                                plural(
                                                    call.attendee_count,
                                                    'attendee',
                                                )
                                            "
                                            ><Users class="size-3.5" /><span
                                                class="sr-only"
                                                >Attendees:</span
                                            >{{ call.attendee_count }}</span
                                        >
                                    </span>
                                </Link>
                            </li>
                        </ul>

                        <Pagination
                            :page="recent"
                            :only="['recent']"
                            label="Recent meetings"
                        />
                    </section>
                </template>

                <!-- Recordings -->
                <template v-else>
                    <div
                        v-if="!recordings"
                        class="space-y-1"
                        aria-busy="true"
                        aria-label="Loading recordings"
                    >
                        <div
                            v-for="i in 6"
                            :key="i"
                            class="h-12 shimmer rounded-lg"
                        />
                    </div>

                    <div
                        v-else-if="recordings.data.length === 0"
                        class="mx-auto flex max-w-md flex-col items-center py-16 text-center"
                    >
                        <span
                            class="mb-3 flex size-11 items-center justify-center rounded-2xl bg-brand-soft text-brand-soft-foreground"
                            ><Clapperboard class="size-5"
                        /></span>
                        <p class="font-display text-2xl">No recordings yet.</p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Press Record in a meeting and it is captured on the
                            server, whether or not you stay. It shows up here
                            when the meeting ends.
                        </p>
                    </div>

                    <template v-else>
                        <ul class="-mx-2 space-y-px">
                            <li
                                v-for="recording in recordings.data"
                                :key="recording.uuid"
                            >
                                <Link
                                    :href="recordingHref(recording)"
                                    class="issue-row h-auto min-h-12 gap-3 py-2"
                                    data-test="recording-row"
                                >
                                    <span
                                        class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand-soft text-brand-soft-foreground"
                                        ><component
                                            :is="recordingIcon(recording)"
                                            class="size-4"
                                    /></span>
                                    <span class="min-w-0 flex-1">
                                        <span
                                            class="block truncate text-[13.5px] font-medium"
                                            >{{ recording.call.title }}</span
                                        >
                                        <span
                                            v-if="
                                                recording.status === 'failed' &&
                                                recording.failure_reason
                                            "
                                            class="block truncate text-[12px] text-destructive"
                                            >{{
                                                recording.failure_reason
                                            }}</span
                                        >
                                        <span
                                            v-else
                                            class="block truncate text-[12px] text-muted-foreground"
                                        >
                                            {{ recording.mode_label }} ·
                                            {{ fullTime(recording.started_at)
                                            }}<template
                                                v-if="recording.started_by"
                                            >
                                                · by
                                                {{
                                                    recording.started_by
                                                }}</template
                                            >
                                        </span>
                                    </span>
                                    <span
                                        class="flex shrink-0 items-center gap-3 text-[12px] text-muted-foreground tabular-nums"
                                    >
                                        <span
                                            v-if="recording.size_bytes > 0"
                                            class="hidden sm:inline"
                                            >{{
                                                formatSize(recording.size_bytes)
                                            }}</span
                                        >
                                        <span
                                            v-if="recording.duration_ms > 0"
                                            class="w-12 text-right"
                                            >{{
                                                formatClock(
                                                    recording.duration_ms,
                                                )
                                            }}</span
                                        >
                                        <StatusPill
                                            :meta="
                                                RECORDING_STATUS[
                                                    recording.status
                                                ]
                                            "
                                            :pulse="
                                                recording.is_active ||
                                                recording.status ===
                                                    'processing'
                                            "
                                        />
                                    </span>
                                </Link>
                            </li>
                        </ul>
                        <Pagination
                            :page="recordings"
                            :only="['recordings']"
                            label="Recordings"
                        />
                    </template>
                </template>
            </div>
        </div>

        <template v-if="can.manage">
            <CallSettingsDialog
                v-model:open="settingsOpen"
                :settings="settings"
            />
            <DiagnosticsDialog v-model:open="diagnosticsOpen" />
        </template>
    </div>
</template>

<style scoped>
.calls-hero {
    background:
        radial-gradient(
            60rem 18rem at 0% 0%,
            color-mix(in oklch, var(--brand) 14%, transparent),
            transparent 60%
        ),
        radial-gradient(
            40rem 16rem at 100% 100%,
            color-mix(in oklch, var(--highlight) 16%, transparent),
            transparent 65%
        ),
        var(--card);
}
</style>
