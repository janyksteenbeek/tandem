<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Paperclip, Upload, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/projects/dialogs/ConfirmDialog.vue';
import SectionLabel from '@/components/projects/detail/SectionLabel.vue';
import AttachmentChip from '@/components/shared/AttachmentChip.vue';
import { api, reportError } from '@/lib/http';
import { cn } from '@/lib/utils';
import type { Issue, IssueAttachment } from '@/types';

const props = defineProps<{ issue: Issue }>();
const emit = defineEmits<{ change: [attachments: IssueAttachment[]] }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const dragging = ref(false);
const uploading = ref(0);
const input = ref<HTMLInputElement | null>(null);
const pendingDelete = ref<IssueAttachment | null>(null);

const images = computed(() =>
    props.issue.attachments.filter((a) => a.is_image),
);
const files = computed(() =>
    props.issue.attachments.filter((a) => !a.is_image),
);

const upload = async (list: FileList | File[]) => {
    for (const file of Array.from(list)) {
        uploading.value++;
        const body = new FormData();
        body.append('file', file);

        try {
            const data = await api.post<{ attachment: IssueAttachment }>(
                `/${slug.value}/issues/${props.issue.identifier}/attachments`,
                body,
            );
            emit('change', [...props.issue.attachments, data.attachment]);
        } catch (error) {
            reportError(error, `Could not upload ${file.name}`);
        } finally {
            uploading.value--;
        }
    }
};

const onDrop = (event: DragEvent) => {
    dragging.value = false;

    if (event.dataTransfer?.files.length) {
        void upload(event.dataTransfer.files);
    }
};

const remove = async () => {
    const target = pendingDelete.value;

    if (!target) {
        return;
    }

    try {
        await api.delete(`/${slug.value}/issue-attachments/${target.uuid}`);
        emit(
            'change',
            props.issue.attachments.filter((a) => a.uuid !== target.uuid),
        );
    } catch (error) {
        reportError(error);
    } finally {
        pendingDelete.value = null;
    }
};
</script>

<template>
    <section
        v-if="issue.attachments.length > 0 || issue.can.comment"
        :class="
            cn(
                'rounded-lg transition-colors',
                dragging &&
                    'bg-brand-soft/40 ring-2 ring-brand/30 ring-offset-2',
            )
        "
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop.prevent="onDrop"
    >
        <SectionLabel label="Attachments">
            <template #meta>
                <span
                    v-if="issue.attachments.length"
                    class="font-normal normal-case"
                    >{{ issue.attachments.length }}</span
                >
            </template>
            <template #actions>
                <button
                    v-if="issue.can.comment"
                    type="button"
                    class="flex h-6 items-center gap-1 rounded-md px-1.5 text-[11.5px] text-muted-foreground hover:bg-accent hover:text-foreground"
                    @click="input?.click()"
                >
                    <Upload class="size-3" />
                    {{ uploading > 0 ? 'Uploading…' : 'Upload' }}
                </button>
                <input
                    ref="input"
                    type="file"
                    multiple
                    class="hidden"
                    @change="
                        (e) =>
                            (e.target as HTMLInputElement).files &&
                            upload((e.target as HTMLInputElement).files!)
                    "
                />
            </template>
        </SectionLabel>

        <div
            v-if="images.length"
            class="mb-2 grid grid-cols-3 gap-2 sm:grid-cols-4"
        >
            <div
                v-for="image in images"
                :key="image.uuid"
                class="group/img relative overflow-hidden rounded-lg border bg-muted"
            >
                <a :href="image.url" target="_blank" rel="noopener">
                    <img
                        :src="image.url"
                        :alt="image.filename"
                        class="aspect-square w-full object-cover"
                        loading="lazy"
                    />
                </a>
                <button
                    v-if="issue.can.comment"
                    type="button"
                    class="absolute top-1 right-1 rounded-full bg-background/90 p-0.5 opacity-0 shadow transition-opacity group-hover/img:opacity-100"
                    aria-label="Delete image"
                    @click="pendingDelete = image"
                >
                    <X class="size-3" />
                </button>
            </div>
        </div>

        <div v-if="files.length" class="flex flex-wrap gap-2">
            <AttachmentChip
                v-for="file in files"
                :key="file.uuid"
                :filename="file.filename"
                :mime-type="file.mime_type"
                :size="file.size_bytes"
                :url="file.url"
                :download-url="file.download_url"
                :removable="issue.can.comment"
                @remove="pendingDelete = file"
            />
        </div>

        <p
            v-if="issue.attachments.length === 0"
            class="flex items-center gap-2 rounded-lg border border-dashed px-3 py-2.5 text-[12.5px] text-muted-foreground"
        >
            <Paperclip class="size-3.5" /> Drop files here or upload.
        </p>

        <ConfirmDialog
            :open="pendingDelete !== null"
            :title="`Delete ${pendingDelete?.filename ?? 'attachment'}?`"
            description="The file is removed from this issue."
            confirm-label="Delete"
            destructive
            @update:open="(v) => !v && (pendingDelete = null)"
            @confirm="remove"
        />
    </section>
</template>
