<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import BrandMark from '@/components/BrandMark.vue';
import RecordingPlayer from '@/components/calls/RecordingPlayer.vue';
import { useBranding } from '@/composables/useBranding';
import { formatClock } from '@/lib/calls/format';
import { fullTime } from '@/lib/time';
import type { RecordingPlayback } from '@/types';

/**
 * A recording opened through a secure link, by someone who may not have an account. It shows
 * this one recording and nothing else of the organization: no navigation, no downloads.
 */
const props = defineProps<{
    recording: RecordingPlayback;
    organization: string;
}>();

const branding = useBranding();

const subtitle = computed(() =>
    [
        props.organization,
        fullTime(props.recording.started_at),
        props.recording.duration_ms > 0
            ? formatClock(props.recording.duration_ms)
            : null,
    ]
        .filter(Boolean)
        .join(' · '),
);
</script>

<template>
    <Head :title="`${recording.title} · Recording`" />

    <div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
        <header
            class="flex h-14 shrink-0 items-center gap-3 border-b px-4 sm:px-6"
        >
            <BrandMark :size="24" />
            <span class="text-[13px] font-semibold">{{ branding.name }}</span>
            <span
                class="ml-auto hidden items-center gap-1.5 text-[12px] text-muted-foreground sm:flex"
            >
                <ShieldCheck class="size-3.5" /> Shared privately with you
            </span>
        </header>

        <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-5 sm:px-6 sm:py-8">
            <h1
                class="font-display text-xl leading-tight font-semibold tracking-[-0.01em] sm:text-2xl"
            >
                {{ recording.title }}
            </h1>
            <p class="mt-1 mb-4 text-[13px] text-muted-foreground">
                {{ subtitle }}
            </p>

            <RecordingPlayer :recording="recording" />

            <p
                class="mt-4 max-w-2xl text-[12px] leading-relaxed text-muted-foreground"
            >
                This link opens this recording only and stops working after a
                while. If it no longer plays, ask the person who sent it for a
                new one.
            </p>
        </main>
    </div>
</template>
