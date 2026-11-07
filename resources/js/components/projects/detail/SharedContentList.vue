<script setup lang="ts">
import { Mail } from '@lucide/vue';
import DOMPurify from 'dompurify';
import SectionLabel from '@/components/projects/detail/SectionLabel.vue';
import AttachmentChip from '@/components/shared/AttachmentChip.vue';
import { relative } from '@/lib/time';
import type { SharedContent } from '@/types';

defineProps<{ items: SharedContent[] }>();

const clean = (html: string | null) =>
    DOMPurify.sanitize(html ?? '', { USE_PROFILES: { html: true } });
</script>

<template>
    <section v-if="items.length > 0">
        <SectionLabel label="Shared from email" />
        <div class="space-y-2">
            <article
                v-for="item in items"
                :key="item.uuid"
                class="rounded-lg border bg-surface px-3 py-2.5"
            >
                <p
                    class="mb-1.5 flex items-center gap-1.5 text-[11px] text-muted-foreground"
                >
                    <Mail class="size-3" />
                    {{ item.kind === 'excerpt' ? 'Excerpt' : 'Attachment' }}
                    shared {{ relative(item.created_at) }}
                </p>
                <div
                    v-if="item.kind === 'excerpt'"
                    class="prose-tandem scrollbar-thin max-h-72 overflow-y-auto text-[13px]"
                    v-html="clean(item.body_html)"
                />
                <AttachmentChip
                    v-else-if="item.attachment"
                    :filename="item.attachment.filename"
                    :mime-type="item.attachment.mime_type"
                    :size="item.attachment.size_bytes"
                    :url="item.attachment.url"
                    :download-url="item.attachment.download_url"
                />
            </article>
        </div>
    </section>
</template>
