<script setup lang="ts">
import { Download, File, FileImage, FileText, X } from '@lucide/vue';
import { computed } from 'vue';
import { bytes } from '@/lib/time';

const props = defineProps<{
    filename: string;
    mimeType: string;
    size: number;
    url?: string | null;
    downloadUrl?: string | null;
    removable?: boolean;
}>();

const emit = defineEmits<{ remove: [] }>();

const icon = computed(() => {
    if (props.mimeType.startsWith('image/')) {
        return FileImage;
    }

    if (props.mimeType.includes('pdf') || props.mimeType.startsWith('text/')) {
        return FileText;
    }

    return File;
});

const isImage = computed(
    () => props.mimeType.startsWith('image/') && props.url,
);
</script>

<template>
    <div
        class="group/att flex max-w-xs items-center gap-2 rounded-lg border bg-surface px-2.5 py-1.5 text-xs transition hover:border-brand/40 hover:shadow-float"
    >
        <img
            v-if="isImage"
            :src="url!"
            class="size-8 rounded object-cover"
            alt=""
        />
        <span
            v-else
            class="flex size-8 items-center justify-center rounded bg-muted text-muted-foreground"
            ><component :is="icon" class="size-4"
        /></span>
        <span class="min-w-0">
            <a
                v-if="url"
                :href="url"
                target="_blank"
                rel="noopener"
                class="block truncate font-medium hover:underline"
                >{{ filename }}</a
            >
            <span v-else class="block truncate font-medium">{{
                filename
            }}</span>
            <span class="text-muted-foreground">{{ bytes(size) }}</span>
        </span>
        <a
            v-if="downloadUrl"
            :href="downloadUrl"
            class="ml-auto rounded p-1 text-muted-foreground opacity-0 transition group-hover/att:opacity-100 hover:bg-accent hover:text-foreground"
            aria-label="Download"
        >
            <Download class="size-3.5" />
        </a>
        <button
            v-if="removable"
            type="button"
            class="ml-auto rounded p-1 text-muted-foreground hover:bg-accent hover:text-destructive"
            aria-label="Remove"
            @click="emit('remove')"
        >
            <X class="size-3.5" />
        </button>
    </div>
</template>
