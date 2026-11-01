<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import DOMPurify from 'dompurify';
import {
    Braces,
    FileText,
    Inbox,
    Loader2,
    Pencil,
    Plus,
    Trash2,
} from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import InboxDot from '@/components/mail/InboxDot.vue';
import EmptyState from '@/components/mail/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    htmlToText,
    insertAtCursor,
    textToHtml,
    variableToken,
} from '@/lib/automations';
import { api } from '@/lib/http';
import { relative } from '@/lib/time';
import { destroy, index, preview, store, update } from '@/routes/templates';
import type {
    AutomationInboxOption,
    MessageTemplate,
    TemplatePreview,
    TemplateVariable,
} from '@/types/automations';
import type { Organization } from '@/types';

const props = defineProps<{
    organization: Pick<Organization, 'id' | 'name' | 'slug'>;
    templates: MessageTemplate[];
    inboxes: AutomationInboxOption[];
    variables: TemplateVariable[];
}>();

const slug = computed(() => props.organization.slug);
const ALL = '__all__';

const open = ref(false);
const editing = ref<MessageTemplate | null>(null);
const deleting = ref<MessageTemplate | null>(null);
const bodyEl = ref<InstanceType<typeof Textarea> | null>(null);

const form = useForm({
    name: '',
    category: '',
    subject: '',
    body: '',
    inbox_uuid: null as string | null,
});

const inboxModel = computed({
    get: () => form.inbox_uuid ?? ALL,
    set: (value: string) => (form.inbox_uuid = value === ALL ? null : value),
});

const startCreate = () => {
    editing.value = null;
    form.defaults({
        name: '',
        category: '',
        subject: '',
        body: '',
        inbox_uuid: null,
    });
    form.reset();
    form.clearErrors();
    previewHtml.value = '';
    previewSubject.value = null;
    open.value = true;
};

const startEdit = (template: MessageTemplate) => {
    editing.value = template;
    form.defaults({
        name: template.name,
        category: template.category ?? '',
        subject: template.subject ?? '',
        body: htmlToText(template.body_html),
        inbox_uuid: template.inbox_uuid,
    });
    form.reset();
    form.clearErrors();
    open.value = true;
    void refreshPreview();
};

const submit = () => {
    const request = form.transform((data) => ({
        name: data.name,
        category: data.category || null,
        subject: data.subject || null,
        body_html: textToHtml(data.body),
        inbox_uuid: data.inbox_uuid,
    }));

    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };

    if (editing.value) {
        request.put(
            update.url({
                current_organization: slug.value,
                template: editing.value.id,
            }),
            options,
        );
    } else {
        request.post(store.url(slug.value), options);
    }
};

const confirmDelete = () => {
    if (!deleting.value) {
        return;
    }

    router.delete(
        destroy.url({
            current_organization: slug.value,
            template: deleting.value.id,
        }),
        {
            preserveScroll: true,
            onFinish: () => (deleting.value = null),
        },
    );
};

/* Variables */
const textareaOf = (): HTMLTextAreaElement | null => {
    const root = (bodyEl.value as unknown as { $el?: HTMLTextAreaElement })
        ?.$el;

    return root instanceof HTMLTextAreaElement ? root : null;
};

const insertVariable = (key: string) => {
    const textarea = textareaOf();
    const start = textarea?.selectionStart ?? form.body.length;
    const end = textarea?.selectionEnd ?? form.body.length;
    const result = insertAtCursor(form.body, variableToken(key), start, end);

    form.body = result.text;

    void nextTick(() => {
        textarea?.focus();
        textarea?.setSelectionRange(result.cursor, result.cursor);
    });
};

/* Preview */
const previewHtml = ref('');
const previewSubject = ref<string | null>(null);
const previewConversation = ref<string | null>(null);
const previewLoading = ref(false);

const refreshPreview = async () => {
    const html = textToHtml(form.body);

    if (html === '') {
        previewHtml.value = '';
        previewSubject.value = null;
        previewLoading.value = false;

        return;
    }

    previewLoading.value = true;

    try {
        const response = await api.post<TemplatePreview>(
            preview.url(slug.value),
            { body_html: html, subject: form.subject || null },
        );
        previewHtml.value = DOMPurify.sanitize(response.body_html);
        previewSubject.value = response.subject;
        previewConversation.value = response.conversation?.subject ?? null;
    } catch {
        previewHtml.value = DOMPurify.sanitize(html);
    } finally {
        previewLoading.value = false;
    }
};

const debouncedPreview = useDebounceFn(refreshPreview, 450);

watch(
    () => [form.body, form.subject],
    () => {
        if (open.value) {
            previewLoading.value = true;
            void debouncedPreview();
        }
    },
);

const snippet = (html: string) => {
    const text = html
        .replace(/<[^>]+>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

    return text.length > 140 ? `${text.slice(0, 140)}…` : text;
};

const bodyError = computed(
    () => (form.errors as Record<string, string | undefined>).body_html,
);

const categories = computed(() =>
    Array.from(
        new Set(
            props.templates
                .map((template) => template.category)
                .filter((category): category is string => Boolean(category)),
        ),
    ),
);
</script>

<template>
    <Head title="Message templates" />

    <div class="w-full space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="font-display text-3xl leading-none">Templates</h1>
            </div>
            <Button
                class="bg-brand text-brand-foreground hover:bg-brand/90"
                @click="startCreate"
                ><Plus /> New template</Button
            >
        </div>

        <EmptyState
            v-if="templates.length === 0"
            view="templates"
            title="No templates yet."
            body="Reusable replies for people and automations."
            compact
        >
            <Button
                variant="outline"
                size="sm"
                class="mt-4"
                @click="startCreate"
                ><Plus /> Write your first template</Button
            >
        </EmptyState>

        <ul v-else class="grid gap-3 sm:grid-cols-2">
            <li
                v-for="template in templates"
                :key="template.id"
                class="group flex flex-col rounded-xl border bg-card p-4 shadow-pane transition hover:shadow-float"
                data-test="template-card"
            >
                <div class="flex items-start gap-3">
                    <span
                        class="mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand-soft text-brand-soft-foreground"
                        ><FileText class="size-4"
                    /></span>
                    <div class="min-w-0 flex-1">
                        <button
                            type="button"
                            class="block w-full truncate text-left font-medium hover:text-brand"
                            @click="startEdit(template)"
                        >
                            {{ template.name }}
                        </button>
                        <p class="truncate text-xs text-muted-foreground">
                            {{
                                template.subject ||
                                'Keeps the conversation subject'
                            }}
                        </p>
                    </div>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                aria-label="More"
                                ><Pencil class="size-4"
                            /></Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem @select="startEdit(template)"
                                ><Pencil /> Edit</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                variant="destructive"
                                @select="deleting = template"
                                ><Trash2 /> Delete</DropdownMenuItem
                            >
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
                <p
                    class="mt-3 line-clamp-3 flex-1 text-sm leading-relaxed text-muted-foreground"
                >
                    {{ snippet(template.body_html) || 'Empty body.' }}
                </p>
                <div
                    class="mt-3 flex flex-wrap items-center gap-1.5 text-[11px]"
                >
                    <span
                        v-if="template.category"
                        class="rounded-full bg-secondary px-2 py-0.5 font-medium text-secondary-foreground"
                        >{{ template.category }}</span
                    >
                    <span
                        class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-muted-foreground"
                        ><Inbox class="size-3" />
                        {{ template.inbox?.name ?? 'All inboxes' }}</span
                    >
                    <span class="ml-auto text-muted-foreground"
                        >Updated {{ relative(template.updated_at) }}</span
                    >
                </div>
            </li>
        </ul>

        <!-- Editor -->
        <Dialog v-model:open="open">
            <DialogContent
                class="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-4xl"
            >
                <form class="space-y-4" @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle class="font-display text-2xl">{{
                            editing ? 'Edit template' : 'New template'
                        }}</DialogTitle>
                        <DialogDescription
                            >Variables are filled in per
                            conversation.</DialogDescription
                        >
                    </DialogHeader>

                    <div class="grid gap-5 md:grid-cols-2">
                        <!-- Fields -->
                        <div class="space-y-4">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="grid gap-1.5 sm:col-span-2">
                                    <Label for="template-name">Name</Label>
                                    <Input
                                        id="template-name"
                                        v-model="form.name"
                                        required
                                        maxlength="255"
                                        placeholder="Out of office"
                                        :aria-invalid="
                                            form.errors.name ? true : undefined
                                        "
                                    />
                                    <InputError :message="form.errors.name" />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="template-category"
                                        >Category
                                        <span
                                            class="font-normal text-muted-foreground"
                                            >(optional)</span
                                        ></Label
                                    >
                                    <Input
                                        id="template-category"
                                        v-model="form.category"
                                        maxlength="64"
                                        placeholder="Auto-replies"
                                        list="template-categories"
                                        :aria-invalid="
                                            form.errors.category
                                                ? true
                                                : undefined
                                        "
                                    />
                                    <datalist id="template-categories">
                                        <option
                                            v-for="category in categories"
                                            :key="category"
                                            :value="category"
                                        />
                                    </datalist>
                                    <InputError
                                        :message="form.errors.category"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="template-inbox">Inbox</Label>
                                    <Select v-model="inboxModel">
                                        <SelectTrigger
                                            class="w-full"
                                            :aria-invalid="
                                                form.errors.inbox_uuid
                                                    ? true
                                                    : undefined
                                            "
                                            ><SelectValue
                                        /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem :value="ALL"
                                                >All inboxes</SelectItem
                                            >
                                            <SelectItem
                                                v-for="inbox in inboxes"
                                                :key="inbox.uuid"
                                                :value="inbox.uuid"
                                            >
                                                <span
                                                    class="flex items-center gap-2"
                                                    ><InboxDot
                                                        :color="inbox.color"
                                                        size="sm"
                                                    />{{ inbox.name }}</span
                                                >
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        :message="form.errors.inbox_uuid"
                                    />
                                </div>
                            </div>

                            <div class="grid gap-1.5">
                                <Label for="template-subject"
                                    >Subject
                                    <span
                                        class="font-normal text-muted-foreground"
                                        >(optional)</span
                                    ></Label
                                >
                                <Input
                                    id="template-subject"
                                    v-model="form.subject"
                                    maxlength="255"
                                    placeholder="Re: {{ conversation.subject }}"
                                    :aria-invalid="
                                        form.errors.subject ? true : undefined
                                    "
                                />
                                <InputError :message="form.errors.subject" />
                            </div>

                            <div class="grid gap-1.5">
                                <div
                                    class="flex items-center justify-between gap-2"
                                >
                                    <Label for="template-body">Body</Label>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="xs"
                                                ><Braces /> Insert
                                                variable</Button
                                            >
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            class="max-h-72 overflow-y-auto"
                                        >
                                            <DropdownMenuItem
                                                v-for="variable in variables"
                                                :key="variable.key"
                                                @select="
                                                    insertVariable(variable.key)
                                                "
                                            >
                                                <code
                                                    class="font-mono text-[11px] text-brand"
                                                    >{{
                                                        variableToken(
                                                            variable.key,
                                                        )
                                                    }}</code
                                                >
                                                <span
                                                    class="ml-auto pl-3 text-xs text-muted-foreground"
                                                    >{{ variable.label }}</span
                                                >
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                                <Textarea
                                    id="template-body"
                                    ref="bodyEl"
                                    v-model="form.body"
                                    rows="10"
                                    required
                                    class="min-h-56 font-mono text-xs leading-relaxed"
                                    :placeholder="`Hi ${variableToken('contact.first_name')},\n\nThanks for reaching out to ${variableToken('organization.name')}. We are closed right now and will get back to you on ${variableToken('next_business_day')}.`"
                                    :aria-invalid="bodyError ? true : undefined"
                                />
                                <p class="text-xs text-muted-foreground">
                                    Plain text or HTML.
                                </p>
                                <InputError :message="bodyError" />
                            </div>
                        </div>

                        <!-- Preview -->
                        <div
                            class="flex min-h-64 flex-col rounded-xl border bg-surface"
                        >
                            <div
                                class="flex items-center justify-between border-b px-4 py-2 text-xs text-muted-foreground"
                            >
                                <span
                                    >Preview{{
                                        previewConversation
                                            ? ` · using “${previewConversation}”`
                                            : ''
                                    }}</span
                                >
                                <Loader2
                                    v-if="previewLoading"
                                    class="size-3.5 animate-spin"
                                />
                            </div>
                            <div class="flex-1 p-4">
                                <p
                                    v-if="previewSubject"
                                    class="mb-3 border-b pb-3 text-sm font-medium"
                                >
                                    {{ previewSubject }}
                                </p>
                                <div
                                    v-if="previewHtml"
                                    class="prose-tandem text-sm"
                                    v-html="previewHtml"
                                />
                                <p
                                    v-else
                                    class="text-sm text-muted-foreground italic"
                                >
                                    Start typing and the rendered reply shows up
                                    here, variables and all.
                                </p>
                            </div>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="open = false"
                            >Cancel</Button
                        >
                        <Button
                            type="submit"
                            class="bg-brand text-brand-foreground hover:bg-brand/90"
                            :disabled="form.processing"
                        >
                            <Loader2
                                v-if="form.processing"
                                class="animate-spin"
                            />
                            {{ editing ? 'Save template' : 'Create template' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="deleting !== null"
            @update:open="(v) => !v && (deleting = null)"
        >
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>Delete “{{ deleting?.name }}”?</DialogTitle>
                    <DialogDescription
                        >Automations using it will skip their
                        reply.</DialogDescription
                    >
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="deleting = null"
                        >Keep it</Button
                    >
                    <Button variant="destructive" @click="confirmDelete"
                        >Delete template</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
