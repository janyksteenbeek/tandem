<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    AudioLines,
    CircleAlert,
    Download,
    Link2,
    MonitorUp,
    Trash2,
    TriangleAlert,
    Video,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import RecordingDownloads from '@/components/calls/RecordingDownloads.vue';
import RecordingPlayer from '@/components/calls/RecordingPlayer.vue';
import ShareRecordingDialog from '@/components/calls/ShareRecordingDialog.vue';
import StatusPill from '@/components/calls/StatusPill.vue';
import ConfirmDialog from '@/components/projects/dialogs/ConfirmDialog.vue';
import WorkspaceHeader from '@/components/projects/workspace/WorkspaceHeader.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useRecordingShare } from '@/composables/calls/useRecordingShare';
import {
    RECORDING_STATUS,
    formatClock,
    formatSize,
    plural,
} from '@/lib/calls/format';
import { fullTime } from '@/lib/time';
import { index, show } from '@/routes/calls';
import { destroy } from '@/routes/calls/recordings';
import type {
    RecordingExport,
    RecordingPlayback,
    RecordingTrack,
} from '@/types';

const props = defineProps<{
    call: { uuid: string; title: string };
    recording: RecordingPlayback & {
        has_share_link: boolean;
        exports: RecordingExport[];
    };
    /** Whether FFmpeg is present, so the mixed and combined downloads can be built. */
    exportsAvailable: boolean;
    can: { share: boolean; delete: boolean };
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');

const share = useRecordingShare(
    () => ({
        slug: slug.value,
        call: props.call.uuid,
        recording: props.recording.uuid,
    }),
    props.recording.has_share_link,
);
const shareOpen = ref(false);
const deleteOpen = ref(false);
const deleting = ref(false);

const SOURCE: Record<
    RecordingTrack['source'],
    { label: string; icon: typeof Video }
> = {
    microphone: { label: 'Microphone', icon: AudioLines },
    camera: { label: 'Camera', icon: Video },
    screen_share: { label: 'Screen', icon: MonitorUp },
    screen_share_audio: { label: 'Screen audio', icon: AudioLines },
};

const downloadUrl = (track: RecordingTrack) =>
    `${track.url}${track.url.includes('?') ? '&' : '?'}download=1`;

const subtitle = computed(() =>
    [
        props.recording.mode_label,
        fullTime(props.recording.started_at),
        props.recording.duration_ms > 0
            ? formatClock(props.recording.duration_ms)
            : null,
        props.recording.size_bytes > 0
            ? formatSize(props.recording.size_bytes)
            : null,
    ]
        .filter(Boolean)
        .join(' · '),
);

const remove = () => {
    router.delete(
        destroy.url([slug.value, props.call.uuid, props.recording.uuid]),
        {
            onStart: () => (deleting.value = true),
            onFinish: () => (deleting.value = false),
        },
    );
};
</script>

<template>
    <Head :title="`Recording · ${call.title}`" />

    <div class="flex h-full min-h-0 flex-col">
        <WorkspaceHeader
            :breadcrumbs="[
                { label: 'Calls', href: index.url(slug) },
                { label: call.title, href: show.url([slug, call.uuid]) },
                { label: 'Recording' },
            ]"
            :subtitle="subtitle"
        >
            <template #meta>
                <StatusPill
                    v-if="recording.status !== 'ready'"
                    :meta="RECORDING_STATUS[recording.status]"
                    :pulse="
                        recording.is_active || recording.status === 'processing'
                    "
                />
            </template>
            <template v-if="recording.is_playable" #actions>
                <RecordingDownloads
                    :slug="slug"
                    :call-uuid="call.uuid"
                    :recording="recording"
                    :initial="recording.exports"
                    :available="exportsAvailable"
                />
                <Button
                    v-if="can.share"
                    variant="outline"
                    size="sm"
                    class="hidden h-8 gap-1.5 sm:inline-flex"
                    @click="shareOpen = true"
                >
                    <Link2 class="size-3.5" />
                    {{
                        share.shared.value ? 'Shared link' : 'Copy secure link'
                    }}
                </Button>
                <Button
                    v-if="can.share"
                    variant="outline"
                    size="icon-sm"
                    class="sm:hidden"
                    aria-label="Copy secure link"
                    @click="shareOpen = true"
                    ><Link2 class="size-4"
                /></Button>
                <Button
                    v-if="can.delete"
                    variant="ghost"
                    size="icon-sm"
                    class="text-muted-foreground hover:text-destructive"
                    aria-label="Delete recording"
                    @click="deleteOpen = true"
                    ><Trash2 class="size-4"
                /></Button>
            </template>
        </WorkspaceHeader>

        <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto">
            <div class="mx-auto max-w-6xl space-y-6 px-4 py-5 sm:px-6">
                <template v-if="recording.is_playable">
                    <p
                        v-if="recording.status === 'partial'"
                        class="flex items-start gap-2 rounded-lg border border-amber-500/25 bg-amber-500/5 px-3.5 py-2.5 text-[13px] text-amber-800 dark:text-amber-200"
                    >
                        <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                        {{
                            recording.warning ??
                            recording.failure_reason ??
                            'Part of this meeting could not be captured. What was saved plays normally.'
                        }}
                    </p>

                    <RecordingPlayer :recording="recording" />

                    <section id="tracks" aria-labelledby="recording-tracks">
                        <div class="mb-2 flex items-baseline justify-between">
                            <h2
                                id="recording-tracks"
                                class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                Original tracks
                                <span class="ml-1 font-normal tabular-nums">{{
                                    recording.tracks.length
                                }}</span>
                            </h2>
                            <p
                                class="hidden text-[12px] text-muted-foreground sm:block"
                            >
                                Exactly as captured: nothing was re-encoded.
                            </p>
                        </div>
                        <ul class="divide-y rounded-xl border">
                            <li
                                v-for="track in recording.tracks"
                                :key="track.file"
                                class="flex items-center gap-3 px-3.5 py-2.5"
                            >
                                <span
                                    class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                                    ><component
                                        :is="
                                            SOURCE[track.source]?.icon ?? Video
                                        "
                                        class="size-4"
                                /></span>
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block truncate text-[13px] font-medium"
                                        >{{ track.participant.name }}
                                        <span
                                            class="font-normal text-muted-foreground"
                                            >·
                                            {{
                                                SOURCE[track.source]?.label ??
                                                track.source
                                            }}</span
                                        ></span
                                    >
                                    <span
                                        class="block truncate text-[11.5px] text-muted-foreground tabular-nums"
                                    >
                                        {{ track.codec.toUpperCase()
                                        }}<template
                                            v-if="track.width && track.height"
                                        >
                                            · {{ track.width }}×{{
                                                track.height
                                            }}</template
                                        >
                                        · from
                                        {{ formatClock(track.start_ms) }} ·
                                        {{ formatClock(track.duration_ms) }}
                                        long
                                    </span>
                                </span>
                                <span
                                    class="hidden shrink-0 text-[12px] text-muted-foreground tabular-nums sm:block"
                                    >{{ formatSize(track.size_bytes) }}</span
                                >
                                <Button
                                    as-child
                                    variant="ghost"
                                    size="icon-sm"
                                    class="shrink-0"
                                >
                                    <a
                                        :href="downloadUrl(track)"
                                        :download="track.file"
                                        :aria-label="`Download ${track.participant.name}'s ${SOURCE[track.source]?.label.toLowerCase() ?? 'track'}`"
                                        ><Download class="size-4"
                                    /></a>
                                </Button>
                            </li>
                        </ul>
                        <p class="mt-2 text-[12px] text-muted-foreground">
                            {{ plural(recording.tracks.length, 'file') }} in
                            WebM. For one file with everything, use Download
                            above.
                            <template v-if="recording.expires_at">
                                This recording is kept until
                                {{ fullTime(recording.expires_at) }}.</template
                            >
                        </p>
                    </section>
                </template>

                <div
                    v-else
                    class="mx-auto flex max-w-md flex-col items-center py-16 text-center"
                >
                    <span
                        class="mb-3 flex size-11 items-center justify-center rounded-2xl"
                        :class="
                            recording.status === 'failed'
                                ? 'bg-destructive/10 text-destructive'
                                : 'bg-brand-soft text-brand-soft-foreground'
                        "
                    >
                        <CircleAlert
                            v-if="recording.status === 'failed'"
                            class="size-5"
                        />
                        <Spinner v-else class="size-5" />
                    </span>
                    <p class="font-display text-2xl">
                        {{
                            recording.status === 'failed'
                                ? 'This recording failed.'
                                : recording.status === 'processing'
                                  ? 'Almost ready.'
                                  : 'Still recording.'
                        }}
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{
                            recording.status === 'failed'
                                ? (recording.failure_reason ??
                                  'Nothing could be saved.')
                                : recording.status === 'processing'
                                  ? 'The recorder is finalising the files. Reload in a moment.'
                                  : 'It can be played once the recording has stopped.'
                        }}
                    </p>
                </div>
            </div>
        </div>

        <ShareRecordingDialog
            v-model:open="shareOpen"
            :link="share.link.value"
            :shared="share.shared.value"
            :busy="share.busy.value"
            @create="share.create()"
            @revoke="share.revoke()"
        />
        <ConfirmDialog
            v-model:open="deleteOpen"
            title="Delete this recording?"
            description="Every track, every export and any shared link are removed for good. This cannot be undone."
            confirm-label="Delete recording"
            destructive
            :busy="deleting"
            @confirm="remove"
        />
    </div>
</template>
