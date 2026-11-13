<script setup lang="ts">
import {
    AudioLines,
    ChevronDown,
    Download,
    Film,
    MonitorPlay,
} from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { useRecordingExports } from '@/composables/calls/useRecordingExports';
import { formatSize } from '@/lib/calls/format';
import type { CallRecording, ExportKind, RecordingExport } from '@/types';

/**
 * The single-file downloads of a recording. Each is built on the server the first time it is
 * asked for and kept afterwards, so a second download is instant.
 */
const props = withDefaults(
    defineProps<{
        slug: string;
        callUuid: string;
        recording: CallRecording;
        initial: RecordingExport[];
        /** False when FFmpeg is missing on the server: exports cannot be built. */
        available?: boolean;
        /** Where the per-track originals are listed, when not on this page. */
        tracksHref?: string | null;
        variant?: 'outline' | 'ghost';
    }>(),
    { available: true, tracksHref: null, variant: 'outline' },
);

const { exports, request, isBusy } = useRecordingExports(
    () => ({
        slug: props.slug,
        call: props.callUuid,
        recording: props.recording.uuid,
    }),
    () => props.initial,
);

type Option = {
    kind: ExportKind;
    label: string;
    hint: string;
    icon: typeof Download;
};

const options = computed<Option[]>(() => {
    const list: Option[] = [
        {
            kind: 'audio',
            label: 'Audio only',
            hint: 'Every voice mixed into one .m4a',
            icon: AudioLines,
        },
    ];

    if (props.recording.has_screen_share) {
        list.push({
            kind: 'screen',
            label: 'Screen recording',
            hint: 'The shared screen at full quality, with sound (.webm)',
            icon: MonitorPlay,
        });
    }

    if (props.recording.mode !== 'audio') {
        list.push({
            kind: 'combined',
            label: 'Combined video',
            hint: 'Screen and cameras in one .mp4',
            icon: Film,
        });
    }

    return list;
});

const anyBusy = computed(() => options.value.some((o) => isBusy(o.kind)));

const state = (kind: ExportKind): string | null => {
    const item = exports.get(kind);

    if (!item) {
        return null;
    }

    if (item.status === 'ready') {
        return formatSize(item.size_bytes);
    }

    if (item.status === 'failed') {
        return 'Failed, try again';
    }

    return 'Building…';
};
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button :variant="variant" size="sm" class="h-8 gap-1.5">
                <Spinner v-if="anyBusy" class="size-3.5" />
                <Download v-else class="size-3.5" />
                Download
                <ChevronDown class="size-3.5 text-muted-foreground" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-80">
            <DropdownMenuLabel
                v-if="!available"
                class="text-[12px] leading-relaxed font-normal text-muted-foreground"
            >
                Single-file downloads need FFmpeg on the server, which was not
                found. The original tracks can still be downloaded.
            </DropdownMenuLabel>
            <DropdownMenuItem
                v-for="option in options"
                :key="option.kind"
                class="items-start gap-2.5 py-2"
                :disabled="!available"
                @select.prevent="request(option.kind)"
            >
                <component
                    :is="option.icon"
                    class="mt-0.5 size-4 text-muted-foreground"
                />
                <span class="min-w-0 flex-1">
                    <span class="block text-[13px] font-medium">{{
                        option.label
                    }}</span>
                    <span class="block text-[11.5px] text-muted-foreground">{{
                        option.hint
                    }}</span>
                </span>
                <span
                    v-if="state(option.kind)"
                    class="flex shrink-0 items-center gap-1 text-[11px] text-muted-foreground tabular-nums"
                >
                    <Spinner v-if="isBusy(option.kind)" class="size-3" />
                    {{ state(option.kind) }}
                </span>
            </DropdownMenuItem>
            <template v-if="tracksHref">
                <DropdownMenuSeparator />
                <DropdownMenuItem as-child>
                    <a :href="tracksHref" class="text-[13px]"
                        >Original tracks, one file per person…</a
                    >
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
