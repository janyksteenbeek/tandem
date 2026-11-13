<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { RefreshCw } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { api, HttpError } from '@/lib/http';
import { formatDuration, formatSize, plural } from '@/lib/calls/format';
import { relative } from '@/lib/time';
import { cn } from '@/lib/utils';
import { diagnostics as diagnosticsRoute } from '@/routes/calls';
import type { CallDiagnostics } from '@/types';

/**
 * A live health view of the self-hosted media stack: is the media server answering, what is
 * the recorder doing, is there room to record, and what went wrong lately.
 */
const open = defineModel<boolean>('open', { default: false });

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');

const data = ref<CallDiagnostics | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const checkedAt = ref<string | null>(null);

const load = async () => {
    loading.value = true;
    error.value = null;

    try {
        data.value = await api.get<CallDiagnostics>(
            diagnosticsRoute.url(slug.value),
        );
        checkedAt.value = new Date().toISOString();
    } catch (failure) {
        error.value =
            failure instanceof HttpError
                ? failure.message
                : 'The diagnostics could not be loaded.';
    } finally {
        loading.value = false;
    }
};

watch(open, (value) => value && void load());

type Health = 'good' | 'warn' | 'bad' | 'off';

const dot: Record<Health, string> = {
    good: 'bg-emerald-500',
    warn: 'bg-amber-500',
    bad: 'bg-destructive',
    off: 'bg-muted-foreground/40',
};

const media = computed<{ health: Health; summary: string }>(() => {
    const info = data.value?.media;

    if (!info?.configured) {
        return {
            health: 'off',
            summary:
                'Not configured. Set LIVEKIT_URL, LIVEKIT_API_KEY and LIVEKIT_API_SECRET.',
        };
    }

    if (!info.reachable) {
        return {
            health: 'bad',
            summary: info.error ?? 'The media server is not answering.',
        };
    }

    const people = info.rooms.reduce((sum, r) => sum + r.participants, 0);

    return {
        health: 'good',
        summary:
            info.rooms.length === 0
                ? 'Reachable. No meetings are running.'
                : `Reachable. ${plural(info.rooms.length, 'room')} with ${plural(people, 'participant')}.`,
    };
});

const recorder = computed<{ health: Health; summary: string }>(() => {
    const info = data.value?.recorder;

    if (!info?.configured) {
        return {
            health: 'off',
            summary:
                'Not configured. Set CALLS_RECORDER_URL and CALLS_RECORDER_SECRET to enable recording.',
        };
    }

    if (!info.stats) {
        return {
            health: 'bad',
            summary:
                'The recorder is not answering. Meetings cannot be recorded right now.',
        };
    }

    return {
        health: 'good',
        summary: `Running. ${plural(info.stats.active_recordings ?? 0, 'active recording')}.`,
    };
});

const disk = computed(() => {
    const stats = data.value?.recorder.stats;
    const storage = data.value?.storage;
    const free = stats?.disk_free_bytes ?? storage?.free_bytes ?? null;
    const total = stats?.disk_total_bytes ?? storage?.total_bytes ?? null;

    if (free === null || total === null || total <= 0) {
        return null;
    }

    const used = 1 - free / total;

    return {
        free,
        total,
        used,
        health: (free < 1024 ** 3
            ? 'bad'
            : used > 0.9
              ? 'warn'
              : 'good') as Health,
    };
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>Calls diagnostics</DialogTitle>
                <DialogDescription>
                    The state of the media server, the recorder and recording
                    storage, straight from the services.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="!data && loading"
                class="space-y-2"
                aria-busy="true"
                aria-label="Loading diagnostics"
            >
                <div v-for="i in 4" :key="i" class="h-14 shimmer rounded-lg" />
            </div>

            <p
                v-else-if="error && !data"
                role="alert"
                class="rounded-lg border border-destructive/30 bg-destructive/5 px-3.5 py-3 text-[13px] text-destructive"
            >
                {{ error }}
            </p>

            <div
                v-else-if="data"
                class="-mr-2 scrollbar-thin max-h-[65vh] space-y-3 overflow-y-auto pr-2"
            >
                <section class="rounded-lg border">
                    <div class="flex items-start gap-3 px-3.5 py-3">
                        <span
                            :class="
                                cn(
                                    'mt-1.5 size-2 shrink-0 rounded-full',
                                    dot[media.health],
                                )
                            "
                        />
                        <div class="min-w-0 flex-1">
                            <p class="text-[13px] font-medium">Media server</p>
                            <p class="text-[12.5px] text-muted-foreground">
                                {{ media.summary }}
                            </p>
                            <p
                                v-if="data.media.configured"
                                class="mt-0.5 truncate font-mono text-[11px] text-muted-foreground/80"
                            >
                                {{ data.media.public_url }}
                            </p>
                        </div>
                    </div>
                    <ul
                        v-if="data.media.rooms.length > 0"
                        class="divide-y border-t text-[12.5px]"
                    >
                        <li
                            v-for="(room, index) in data.media.rooms"
                            :key="index"
                            class="flex items-center gap-3 px-3.5 py-2"
                        >
                            <span class="min-w-0 flex-1 truncate">{{
                                room.title ??
                                'A meeting in another organization'
                            }}</span>
                            <span
                                v-if="room.recording"
                                class="flex items-center gap-1 text-rose-600 dark:text-rose-300"
                                ><span
                                    class="size-1.5 rounded-full bg-rose-500"
                                />Recording</span
                            >
                            <span class="text-muted-foreground tabular-nums">{{
                                plural(room.participants, 'participant')
                            }}</span>
                        </li>
                    </ul>
                </section>

                <section class="rounded-lg border">
                    <div class="flex items-start gap-3 px-3.5 py-3">
                        <span
                            :class="
                                cn(
                                    'mt-1.5 size-2 shrink-0 rounded-full',
                                    dot[recorder.health],
                                )
                            "
                        />
                        <div class="min-w-0 flex-1">
                            <p class="text-[13px] font-medium">Recorder</p>
                            <p class="text-[12.5px] text-muted-foreground">
                                {{ recorder.summary }}
                            </p>
                        </div>
                    </div>
                    <dl
                        v-if="data.recorder.stats"
                        class="grid grid-cols-2 gap-px border-t bg-border text-[12.5px] sm:grid-cols-4"
                    >
                        <div class="bg-background px-3.5 py-2">
                            <dt class="text-[11px] text-muted-foreground">
                                Memory
                            </dt>
                            <dd class="font-medium tabular-nums">
                                {{
                                    formatSize(
                                        data.recorder.stats.mem_sys_bytes ??
                                            data.recorder.stats
                                                .mem_alloc_bytes ??
                                            0,
                                    )
                                }}
                            </dd>
                        </div>
                        <div class="bg-background px-3.5 py-2">
                            <dt class="text-[11px] text-muted-foreground">
                                Uptime
                            </dt>
                            <dd class="font-medium tabular-nums">
                                {{
                                    formatDuration(
                                        data.recorder.stats.uptime_s ?? 0,
                                    )
                                }}
                            </dd>
                        </div>
                        <div class="bg-background px-3.5 py-2">
                            <dt class="text-[11px] text-muted-foreground">
                                Goroutines
                            </dt>
                            <dd class="font-medium tabular-nums">
                                {{ data.recorder.stats.goroutines ?? '–' }}
                            </dd>
                        </div>
                        <div class="bg-background px-3.5 py-2">
                            <dt class="text-[11px] text-muted-foreground">
                                Version
                            </dt>
                            <dd class="truncate font-medium">
                                {{ data.recorder.stats.version ?? '–' }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-lg border px-3.5 py-3">
                    <div class="flex items-start gap-3">
                        <span
                            :class="
                                cn(
                                    'mt-1.5 size-2 shrink-0 rounded-full',
                                    dot[disk?.health ?? 'off'],
                                )
                            "
                        />
                        <div class="min-w-0 flex-1">
                            <p class="text-[13px] font-medium">
                                Recording storage
                            </p>
                            <template v-if="disk">
                                <p class="text-[12.5px] text-muted-foreground">
                                    {{ formatSize(disk.free) }} free of
                                    {{ formatSize(disk.total) }}.
                                    {{
                                        formatSize(data.recordings.stored_bytes)
                                    }}
                                    used by this organization's recordings.
                                </p>
                                <div
                                    class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted"
                                    role="progressbar"
                                    aria-label="Disk used"
                                    :aria-valuenow="Math.round(disk.used * 100)"
                                    aria-valuemin="0"
                                    aria-valuemax="100"
                                >
                                    <div
                                        :class="
                                            cn(
                                                'h-full rounded-full',
                                                dot[disk.health],
                                            )
                                        "
                                        :style="{
                                            width: `${Math.round(disk.used * 100)}%`,
                                        }"
                                    />
                                </div>
                            </template>
                            <p
                                v-else
                                class="text-[12.5px] text-muted-foreground"
                            >
                                Recordings are kept on object storage; free
                                space is not reported here.
                                {{ formatSize(data.recordings.stored_bytes) }}
                                used by this organization's recordings.
                            </p>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border">
                    <dl
                        class="grid grid-cols-3 gap-px overflow-hidden rounded-t-lg bg-border text-[12.5px]"
                    >
                        <div class="bg-background px-3.5 py-2.5">
                            <dt class="text-[11px] text-muted-foreground">
                                Recording now
                            </dt>
                            <dd class="text-base font-semibold tabular-nums">
                                {{ data.recordings.active }}
                            </dd>
                        </div>
                        <div class="bg-background px-3.5 py-2.5">
                            <dt class="text-[11px] text-muted-foreground">
                                Being finalised
                            </dt>
                            <dd class="text-base font-semibold tabular-nums">
                                {{ data.recordings.processing }}
                            </dd>
                        </div>
                        <div class="bg-background px-3.5 py-2.5">
                            <dt class="text-[11px] text-muted-foreground">
                                Failed this week
                            </dt>
                            <dd
                                :class="
                                    cn(
                                        'text-base font-semibold tabular-nums',
                                        data.recordings.failed_last_week > 0 &&
                                            'text-destructive',
                                    )
                                "
                            >
                                {{ data.recordings.failed_last_week }}
                            </dd>
                        </div>
                    </dl>
                    <div class="flex items-start gap-3 border-t px-3.5 py-3">
                        <span
                            :class="
                                cn(
                                    'mt-1.5 size-2 shrink-0 rounded-full',
                                    dot[data.exports.ffmpeg ? 'good' : 'warn'],
                                )
                            "
                        />
                        <div class="min-w-0 flex-1">
                            <p class="text-[13px] font-medium">
                                Exports (FFmpeg)
                            </p>
                            <p class="text-[12.5px] text-muted-foreground">
                                {{
                                    data.exports.ffmpeg
                                        ? 'Installed. Audio-only, screen and combined downloads can be built.'
                                        : 'Not found on the queue workers. Playback and per-track downloads work; single-file exports do not.'
                                }}
                            </p>
                        </div>
                    </div>
                </section>

                <section v-if="data.recent_errors.length > 0">
                    <p
                        class="mb-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        Recent recording errors
                    </p>
                    <ul class="divide-y rounded-lg border text-[12.5px]">
                        <li
                            v-for="(item, index) in data.recent_errors"
                            :key="index"
                            class="flex items-start gap-3 px-3.5 py-2"
                        >
                            <span class="min-w-0 flex-1">{{
                                item.reason ?? 'Recording failed'
                            }}</span>
                            <span
                                class="shrink-0 text-muted-foreground tabular-nums"
                                >{{ relative(item.at) }}</span
                            >
                        </li>
                    </ul>
                </section>
            </div>

            <div
                class="flex items-center justify-between gap-3 border-t pt-3 text-[12px] text-muted-foreground"
            >
                <span aria-live="polite">{{
                    error && data
                        ? error
                        : checkedAt
                          ? `Checked ${relative(checkedAt)}`
                          : ''
                }}</span>
                <Button
                    variant="outline"
                    size="sm"
                    class="gap-1.5"
                    :disabled="loading"
                    @click="load"
                >
                    <RefreshCw
                        :class="cn('size-3.5', loading && 'animate-spin')"
                    />
                    Check again
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
