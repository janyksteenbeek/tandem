<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    AudioLines,
    CircleAlert,
    Clapperboard,
    Link2,
    MonitorPlay,
    Play,
    Trash2,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import RecordingDownloads from '@/components/calls/RecordingDownloads.vue';
import ShareRecordingDialog from '@/components/calls/ShareRecordingDialog.vue';
import StatusPill from '@/components/calls/StatusPill.vue';
import ConfirmDialog from '@/components/projects/dialogs/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useRecordingShare } from '@/composables/calls/useRecordingShare';
import {
    RECORDING_STATUS,
    formatClock,
    formatSize,
    plural,
} from '@/lib/calls/format';
import { fullTime } from '@/lib/time';
import { destroy, show } from '@/routes/calls/recordings';
import type { CallRecordingWithExports } from '@/types';

/**
 * One recording on a meeting's history page: what state it is in, and everything you can do
 * with it once it is ready.
 */
const props = defineProps<{
    slug: string;
    callUuid: string;
    recording: CallRecordingWithExports;
    exportsAvailable: boolean;
    can: { share: boolean; delete: boolean };
}>();

const scope = () => ({
    slug: props.slug,
    call: props.callUuid,
    recording: props.recording.uuid,
});

const href = computed(() =>
    show.url([props.slug, props.callUuid, props.recording.uuid]),
);
const share = useRecordingShare(scope, props.recording.has_share_link);
const shareOpen = ref(false);
const deleteOpen = ref(false);
const deleting = ref(false);

const icon = computed(() =>
    props.recording.mode === 'audio'
        ? AudioLines
        : props.recording.has_screen_share || props.recording.mode === 'screen'
          ? MonitorPlay
          : Clapperboard,
);

const remove = () => {
    router.delete(
        destroy.url([props.slug, props.callUuid, props.recording.uuid]),
        {
            preserveScroll: true,
            onStart: () => (deleting.value = true),
            onFinish: () => {
                deleting.value = false;
                deleteOpen.value = false;
            },
        },
    );
};
</script>

<template>
    <article
        class="rounded-xl border bg-card shadow-xs"
        :data-test="`recording-${recording.status}`"
    >
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-3.5 py-3">
            <component
                :is="recording.is_playable ? Link : 'span'"
                :href="recording.is_playable ? href : undefined"
                class="group relative flex size-11 shrink-0 items-center justify-center rounded-lg bg-brand-soft text-brand-soft-foreground"
                :aria-label="
                    recording.is_playable ? 'Play recording' : undefined
                "
            >
                <Spinner
                    v-if="
                        recording.status === 'processing' ||
                        recording.status === 'starting'
                    "
                    class="size-4.5"
                />
                <template v-else>
                    <component
                        :is="icon"
                        class="size-5 transition-opacity"
                        :class="
                            recording.is_playable && 'group-hover:opacity-0'
                        "
                    />
                    <Play
                        v-if="recording.is_playable"
                        class="absolute size-5 fill-current opacity-0 transition-opacity group-hover:opacity-100"
                    />
                </template>
            </component>

            <div class="min-w-0 flex-1 basis-52">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-[13.5px] font-medium">
                        {{ recording.mode_label }}
                    </p>
                    <StatusPill
                        :meta="RECORDING_STATUS[recording.status]"
                        :pulse="
                            recording.is_active ||
                            recording.status === 'processing'
                        "
                    />
                    <span
                        v-if="share.shared.value"
                        class="flex items-center gap-1 text-[11px] text-muted-foreground"
                        ><Link2 class="size-3" /> Link active</span
                    >
                </div>
                <p class="mt-0.5 text-[12px] text-muted-foreground">
                    <template v-if="recording.duration_ms > 0"
                        >{{ formatClock(recording.duration_ms) }} ·
                    </template>
                    <template v-if="recording.size_bytes > 0"
                        >{{ formatSize(recording.size_bytes) }} ·
                    </template>
                    <template v-if="recording.track_count > 0"
                        >{{ plural(recording.track_count, 'track') }} ·
                    </template>
                    {{ fullTime(recording.started_at)
                    }}<template v-if="recording.started_by">
                        · started by {{ recording.started_by }}</template
                    >
                </p>
            </div>

            <div
                v-if="recording.is_playable"
                class="flex shrink-0 flex-wrap items-center gap-1.5"
            >
                <Button
                    as-child
                    size="sm"
                    class="h-8 gap-1.5 px-3 text-brand-foreground brand-button-surface"
                >
                    <Link :href="href"
                        ><Play class="size-3.5 fill-current" /> Play</Link
                    >
                </Button>
                <RecordingDownloads
                    :slug="slug"
                    :call-uuid="callUuid"
                    :recording="recording"
                    :initial="recording.exports"
                    :available="exportsAvailable"
                    :tracks-href="`${href}#tracks`"
                />
                <Tooltip v-if="can.share">
                    <TooltipTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label="Copy secure link"
                            @click="shareOpen = true"
                            ><Link2 class="size-4"
                        /></Button>
                    </TooltipTrigger>
                    <TooltipContent>Copy secure link</TooltipContent>
                </Tooltip>
                <Tooltip v-if="can.delete">
                    <TooltipTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            class="text-muted-foreground hover:text-destructive"
                            aria-label="Delete recording"
                            @click="deleteOpen = true"
                            ><Trash2 class="size-4"
                        /></Button>
                    </TooltipTrigger>
                    <TooltipContent>Delete recording</TooltipContent>
                </Tooltip>
            </div>
            <Button
                v-else-if="can.delete && recording.status === 'failed'"
                variant="ghost"
                size="sm"
                class="h-8 gap-1.5 text-muted-foreground hover:text-destructive"
                @click="deleteOpen = true"
            >
                <Trash2 class="size-3.5" /> Remove
            </Button>
        </div>

        <p
            v-if="recording.status === 'failed'"
            role="alert"
            class="flex items-start gap-2 border-t border-destructive/20 bg-destructive/5 px-3.5 py-2 text-[12.5px] text-destructive"
        >
            <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
            {{
                recording.failure_reason ??
                'This recording failed and nothing could be saved.'
            }}
        </p>
        <p
            v-else-if="recording.warning || recording.status === 'partial'"
            class="flex items-start gap-2 border-t border-amber-500/20 bg-amber-500/5 px-3.5 py-2 text-[12.5px] text-amber-800 dark:text-amber-200"
        >
            <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
            {{
                recording.warning ??
                recording.failure_reason ??
                'Part of this meeting could not be captured.'
            }}
        </p>
        <p
            v-else-if="recording.status === 'processing'"
            class="border-t px-3.5 py-2 text-[12.5px] text-muted-foreground"
        >
            The recorder is finalising the files. This page updates when it is
            ready.
        </p>
        <p
            v-else-if="recording.is_playable"
            class="border-t px-3.5 py-2 text-[12px] text-muted-foreground"
        >
            {{
                recording.expires_at
                    ? `Kept until ${fullTime(recording.expires_at)}, then deleted automatically.`
                    : 'Kept until someone deletes it.'
            }}
        </p>

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
    </article>
</template>
