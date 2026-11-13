<script lang="ts">
export type ShareToIssuePayload = {
    identifier: string;
    message_uuid?: string | null;
    selection_html?: string | null;
    attachment_uuids?: string[];
};
</script>

<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Loader2, Paperclip, Quote, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import IssueIdentifier from '@/components/projects/shared/IssueIdentifier.vue';
import StatusIcon from '@/components/projects/shared/StatusIcon.vue';
import AttachmentChip from '@/components/shared/AttachmentChip.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { api } from '@/lib/http';
import { messageText } from '@/lib/projects/issuePrefill';
import { cn } from '@/lib/utils';
import type { Attachment, IssueListItem, Message } from '@/types';

/**
 * Copy part of a conversation into an issue: an excerpt of one message (the reader's selection
 * or the whole message) and/or attachments. The target is one of the already linked issues or
 * any visible issue found by search, which is linked first. Sharing is always this explicit.
 */
const props = withDefaults(
    defineProps<{
        linkedIssues: IssueListItem[];
        message?: Message | null;
        selectionHtml?: string | null;
        attachments?: Attachment[];
        busy?: boolean;
    }>(),
    { message: null, selectionHtml: null, attachments: () => [], busy: false },
);
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ confirm: [payload: ShareToIssuePayload] }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');

const target = ref<IssueListItem | null>(null);
const includeExcerpt = ref(true);
const selectedAttachments = ref<Set<string>>(new Set());
const query = ref('');
const results = ref<IssueListItem[]>([]);
const searching = ref(false);

const candidateAttachments = computed(() =>
    (props.message?.attachments ?? props.attachments).filter(
        (a) => !a.is_inline || !a.content_id,
    ),
);

const excerptPreview = computed(() => {
    if (props.selectionHtml) {
        return props.selectionHtml
            .replace(/<[^>]+>/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    return props.message ? messageText(props.message) : '';
});

const excerptLabel = computed(() =>
    props.selectionHtml ? 'Selected text' : 'Whole message',
);

const candidates = computed(() => {
    const linked = props.linkedIssues;
    const extra = results.value.filter(
        (r) => !linked.some((l) => l.uuid === r.uuid),
    );

    return { linked, extra };
});

const canConfirm = computed(
    () =>
        target.value !== null &&
        ((includeExcerpt.value && props.message !== null) ||
            selectedAttachments.value.size > 0),
);

const search = useDebounceFn(async (term: string) => {
    if (!term.trim()) {
        results.value = [];

        return;
    }

    searching.value = true;

    try {
        const data = await api.get<{ issues: IssueListItem[] }>(
            `/${slug.value}/issues/search`,
            { q: term, limit: 8 },
        );

        if (query.value === term) {
            results.value = data.issues;
        }
    } catch {
        results.value = [];
    } finally {
        searching.value = false;
    }
}, 180);

watch(query, (value) => void search(value));

watch(open, (value) => {
    if (value) {
        target.value = props.linkedIssues[0] ?? null;
        includeExcerpt.value = props.message !== null;
        selectedAttachments.value = new Set();
        query.value = '';
        results.value = [];
    }
});

const toggleAttachment = (uuid: string, checked: boolean) => {
    const next = new Set(selectedAttachments.value);

    if (checked) {
        next.add(uuid);
    } else {
        next.delete(uuid);
    }

    selectedAttachments.value = next;
};

const confirm = () => {
    if (!target.value || !canConfirm.value) {
        return;
    }

    emit('confirm', {
        identifier: target.value.identifier,
        message_uuid:
            includeExcerpt.value && props.message ? props.message.uuid : null,
        selection_html:
            includeExcerpt.value && props.selectionHtml
                ? props.selectionHtml
                : null,
        attachment_uuids: [...selectedAttachments.value],
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Share into issue</DialogTitle>
                <DialogDescription
                    >Copies the chosen content onto the issue so teammates
                    without mailbox access can read it. The rest of the
                    conversation stays private.</DialogDescription
                >
            </DialogHeader>

            <div class="space-y-4">
                <section>
                    <p
                        class="mb-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        Issue
                    </p>
                    <div
                        v-if="candidates.linked.length"
                        class="flex flex-wrap gap-1.5"
                    >
                        <button
                            v-for="issue in candidates.linked"
                            :key="issue.uuid"
                            type="button"
                            :class="
                                cn(
                                    'flex max-w-full items-center gap-1.5 rounded-md border px-2 py-1 text-[12.5px] transition-colors',
                                    target?.uuid === issue.uuid
                                        ? 'border-brand bg-brand-soft/50'
                                        : 'hover:bg-accent',
                                )
                            "
                            @click="target = issue"
                        >
                            <StatusIcon :status="issue.status" :size="12" />
                            <IssueIdentifier :identifier="issue.identifier" />
                            <span class="truncate">{{ issue.title }}</span>
                        </button>
                    </div>
                    <div class="relative mt-2">
                        <Search
                            class="absolute top-2 left-2.5 size-3.5 text-muted-foreground"
                        />
                        <input
                            v-model="query"
                            type="search"
                            class="h-8 w-full rounded-md border bg-transparent pr-8 pl-8 text-[13px] outline-none placeholder:text-muted-foreground/60 focus:border-brand"
                            :placeholder="
                                candidates.linked.length
                                    ? 'Or search another issue…'
                                    : 'Search an issue by identifier or title…'
                            "
                        />
                        <Loader2
                            v-if="searching"
                            class="absolute top-2 right-2.5 size-3.5 animate-spin text-muted-foreground"
                        />
                    </div>
                    <ul
                        v-if="candidates.extra.length"
                        class="mt-1.5 max-h-40 divide-y overflow-y-auto rounded-md border"
                    >
                        <li v-for="issue in candidates.extra" :key="issue.uuid">
                            <button
                                type="button"
                                :class="
                                    cn(
                                        'flex w-full items-center gap-2 px-2.5 py-1.5 text-left text-[12.5px] hover:bg-accent',
                                        target?.uuid === issue.uuid &&
                                            'bg-brand-soft/50',
                                    )
                                "
                                @click="target = issue"
                            >
                                <StatusIcon :status="issue.status" :size="12" />
                                <IssueIdentifier
                                    :identifier="issue.identifier"
                                />
                                <span class="truncate">{{ issue.title }}</span>
                                <span
                                    class="ml-auto shrink-0 text-[10.5px] text-muted-foreground"
                                    >will be linked</span
                                >
                            </button>
                        </li>
                    </ul>
                </section>

                <section v-if="message">
                    <label
                        class="flex cursor-pointer items-start gap-2.5 rounded-lg border p-3"
                    >
                        <Checkbox
                            :model-value="includeExcerpt"
                            class="mt-0.5"
                            @update:model-value="
                                (v) => (includeExcerpt = v === true)
                            "
                        />
                        <span class="min-w-0 flex-1">
                            <span
                                class="flex items-center gap-1.5 text-[12.5px] font-medium"
                                ><Quote class="size-3.5" />
                                {{ excerptLabel }}</span
                            >
                            <span
                                class="mt-1 line-clamp-3 block text-[12px] whitespace-pre-line text-muted-foreground"
                                >{{ excerptPreview || '(empty message)' }}</span
                            >
                        </span>
                    </label>
                </section>

                <section v-if="candidateAttachments.length">
                    <p
                        class="mb-1.5 flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        <Paperclip class="size-3" /> Attachments
                    </p>
                    <ul class="space-y-1">
                        <li
                            v-for="attachment in candidateAttachments"
                            :key="attachment.uuid"
                        >
                            <label
                                class="flex cursor-pointer items-center gap-2.5"
                            >
                                <Checkbox
                                    :model-value="
                                        selectedAttachments.has(attachment.uuid)
                                    "
                                    @update:model-value="
                                        (v) =>
                                            toggleAttachment(
                                                attachment.uuid,
                                                v === true,
                                            )
                                    "
                                />
                                <AttachmentChip
                                    :filename="attachment.filename"
                                    :mime-type="attachment.mime_type"
                                    :size="attachment.size_bytes"
                                />
                            </label>
                        </li>
                    </ul>
                </section>
            </div>

            <DialogFooter>
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="busy"
                    @click="open = false"
                    >Cancel</Button
                >
                <Button
                    size="sm"
                    :disabled="busy || !canConfirm"
                    @click="confirm"
                >
                    <Loader2 v-if="busy" class="size-3.5 animate-spin" />
                    Share{{ target ? ` into ${target.identifier}` : '' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
