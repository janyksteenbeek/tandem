<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { FileText } from '@lucide/vue';
import { computed } from 'vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { index as templatesIndex } from '@/routes/templates';
import type { AutomationTemplateOption } from '@/types/automations';

const props = defineProps<{ templates: AutomationTemplateOption[]; invalid?: boolean }>();
const model = defineModel<number | string | null>({ default: null });

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');

const selected = computed({
    get: () => (model.value === null || model.value === undefined ? '' : String(model.value)),
    set: (value: string) => (model.value = value === '' ? null : Number(value)),
});

const current = computed(() => props.templates.find((template) => String(template.id) === selected.value));
</script>

<template>
    <div class="grid gap-1.5">
        <div v-if="templates.length === 0" class="flex items-start gap-3 rounded-lg border border-dashed p-3 text-sm">
            <FileText class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
            <div>
                <p class="font-medium">No templates yet.</p>
                <p class="text-xs text-muted-foreground">
                    Write one first, then come back here.
                    <Link :href="templatesIndex(slug)" class="text-brand underline underline-offset-2">Open templates</Link>
                </p>
            </div>
        </div>

        <template v-else>
            <Select v-model="selected">
                <SelectTrigger class="w-full" :aria-invalid="invalid || undefined" aria-label="Template">
                    <SelectValue placeholder="Choose a template" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem v-for="template in templates" :key="template.id" :value="String(template.id)">
                        <span class="flex items-center gap-2">
                            <span>{{ template.name }}</span>
                            <span v-if="template.category" class="rounded-full bg-secondary px-1.5 py-px text-[10px] text-secondary-foreground">{{ template.category }}</span>
                        </span>
                    </SelectItem>
                </SelectContent>
            </Select>
            <p v-if="current" class="truncate text-xs text-muted-foreground">
                <template v-if="current.subject">Subject: {{ current.subject }}</template>
                <template v-else>Replies keep the conversation subject.</template>
                <template v-if="current.inbox"> · Scoped to {{ current.inbox.name }}</template>
            </p>
            <Link :href="templatesIndex(slug)" class="text-xs text-muted-foreground underline-offset-2 hover:text-brand hover:underline">Manage templates</Link>
        </template>
    </div>
</template>
