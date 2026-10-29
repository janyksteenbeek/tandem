<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Copy, GripVertical, Inbox, MoreHorizontal, Pencil, Plus, Sparkles, Trash2, Zap } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/components/mail/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { STARTER_RULES, actionLabel } from '@/lib/automations';
import { api, reportError } from '@/lib/http';
import { relative } from '@/lib/time';
import { cn } from '@/lib/utils';
import { create, destroy, edit, index, reorder, toggle } from '@/routes/automations';
import type { AutomationCatalog, AutomationRuleDetail, AutomationRuleSummary } from '@/types/automations';
import type { Organization } from '@/types';
import { toast } from 'vue-sonner';

const props = defineProps<{
    organization: Pick<Organization, 'id' | 'name' | 'slug'>;
    rules: AutomationRuleSummary[];
    catalog: AutomationCatalog;
}>();

defineOptions({
    layout: (props: { organization: { slug: string } }) => ({
        breadcrumbs: [{ title: 'Automations', href: index(props.organization.slug) }],
    }),
});

const page = usePage();
const slug = computed(() => props.organization.slug);
const items = ref<AutomationRuleSummary[]>([...props.rules]);
const showStarters = ref(false);
const deleting = ref<AutomationRuleSummary | null>(null);
const busy = ref<Set<string>>(new Set());
const dragging = ref<string | null>(null);
const dragOver = ref<string | null>(null);

watch(
    () => props.rules,
    (value) => (items.value = [...value]),
);

const activeCount = computed(() => items.value.filter((rule) => rule.is_active).length);

const routeArgs = (rule: AutomationRuleSummary) => ({ current_organization: slug.value, rule: rule.uuid });

const mark = (uuid: string, on: boolean) => {
    const next = new Set(busy.value);

    if (on) {
        next.add(uuid);
    } else {
        next.delete(uuid);
    }

    busy.value = next;
};

const setActive = async (rule: AutomationRuleSummary, value: boolean) => {
    const previous = rule.is_active;
    rule.is_active = value;
    mark(rule.uuid, true);

    try {
        const response = await api.patch<{ is_active: boolean }>(toggle.url(routeArgs(rule)), { is_active: value });
        rule.is_active = response.is_active;
        toast.success(response.is_active ? `${rule.name} is on.` : `${rule.name} is paused.`);
    } catch (error) {
        rule.is_active = previous;
        reportError(error, 'Could not update the rule.');
    } finally {
        mark(rule.uuid, false);
    }
};

const persistOrder = async () => {
    try {
        await api.post(reorder.url(slug.value), { rules: items.value.map((rule) => rule.uuid) });
        items.value.forEach((rule, i) => (rule.position = i + 1));
    } catch (error) {
        reportError(error, 'Could not save the new order.');
        router.reload({ only: ['rules'] });
    }
};

const move = (from: number, to: number) => {
    if (to < 0 || to >= items.value.length || from === to) {
        return;
    }

    const next = [...items.value];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    items.value = next;
    void persistOrder();
};

const onDragStart = (event: DragEvent, rule: AutomationRuleSummary) => {
    dragging.value = rule.uuid;
    event.dataTransfer?.setData('text/plain', rule.uuid);

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
    }
};

const onDrop = (target: AutomationRuleSummary) => {
    const from = items.value.findIndex((rule) => rule.uuid === dragging.value);
    const to = items.value.findIndex((rule) => rule.uuid === target.uuid);

    dragging.value = null;
    dragOver.value = null;

    if (from >= 0 && to >= 0) {
        move(from, to);
    }
};

const confirmDelete = () => {
    if (!deleting.value) {
        return;
    }

    router.delete(destroy.url(routeArgs(deleting.value)), {
        preserveScroll: true,
        onFinish: () => (deleting.value = null),
    });
};

/**
 * Duplicate needs the full rule, which only the edit page has. Fetch it the Inertia way and
 * hand it to the create page through session storage.
 */
const duplicate = async (rule: AutomationRuleSummary) => {
    mark(rule.uuid, true);

    try {
        const response = await fetch(edit.url(routeArgs(rule)), {
            headers: {
                'X-Inertia': 'true',
                'X-Inertia-Version': page.version ?? '',
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'text/html, application/xhtml+xml',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error('Could not load the rule.');
        }

        const data = (await response.json()) as { props: { rule: AutomationRuleDetail } };
        const detail = data.props.rule;

        sessionStorage.setItem(
            'tandem:automation-duplicate',
            JSON.stringify({
                name: `${detail.name} (copy)`,
                description: detail.description ?? '',
                inbox_uuid: detail.inbox_uuid,
                trigger: detail.trigger.value,
                match: detail.match,
                is_active: false,
                cooldown_minutes: detail.cooldown_minutes,
                conditions: detail.conditions,
                actions: detail.actions,
            }),
        );

        router.visit(create.url(slug.value, { query: { from: 'duplicate' } }));
    } catch (error) {
        reportError(error, 'Could not duplicate the rule.');
    } finally {
        mark(rule.uuid, false);
    }
};

const conditionLabel = (rule: AutomationRuleSummary) => {
    if (rule.condition_count === 0) {
        return 'Always';
    }

    if (rule.condition_count === 1) {
        return '1 condition';
    }

    return `${rule.match === 'any' ? 'Any' : 'All'} of ${rule.condition_count} conditions`;
};
</script>

<template>
    <Head title="Automations" />

    <div class="w-full space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="font-display text-3xl leading-none">Automations</h1>
                <p class="mt-1.5 text-sm text-muted-foreground">
                    Rules run top to bottom whenever something happens in a shared inbox.
                    <span v-if="items.length > 0">{{ activeCount }} of {{ items.length }} on.</span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button v-if="items.length > 0" variant="ghost" size="sm" @click="showStarters = !showStarters">
                    <Sparkles /> Starters
                </Button>
                <Button as-child class="bg-brand text-brand-foreground hover:bg-brand/90">
                    <Link :href="create(slug)"><Plus /> New rule</Link>
                </Button>
            </div>
        </div>

        <!-- Starters -->
        <div v-if="items.length === 0 || showStarters" class="animate-rise">
            <EmptyState v-if="items.length === 0" view="automations" title="Nothing runs on its own yet." body="Pick a starter below or build a rule from scratch. Most teams begin with an out-of-office reply." compact />
            <Heading v-else variant="small" title="Start from a template" description="Prefilled rules you can tweak before saving." />
            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <Link
                    v-for="starter in STARTER_RULES"
                    :key="starter.key"
                    :href="create(slug, { query: { starter: starter.key } })"
                    class="group flex flex-col rounded-xl border bg-card p-4 text-left shadow-pane transition hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-float"
                >
                    <span class="mb-3 inline-flex size-8 items-center justify-center rounded-lg bg-brand-soft text-brand-soft-foreground"><Zap class="size-4" /></span>
                    <span class="text-sm font-semibold">{{ starter.title }}</span>
                    <span class="mt-1 text-xs leading-relaxed text-muted-foreground">{{ starter.blurb }}</span>
                    <span class="mt-3 text-xs font-medium text-brand opacity-0 transition group-hover:opacity-100">Use this starter →</span>
                </Link>
            </div>
        </div>

        <!-- Rule list -->
        <TooltipProvider v-if="items.length > 0" :delay-duration="200">
            <ol class="space-y-2">
                <li
                    v-for="(rule, i) in items"
                    :key="rule.uuid"
                    :class="
                        cn(
                            'group relative flex gap-2 rounded-xl border bg-card p-3 shadow-pane transition sm:gap-3 sm:p-4',
                            !rule.is_active && 'bg-surface',
                            dragging === rule.uuid && 'opacity-50',
                            dragOver === rule.uuid && dragging !== rule.uuid && 'border-brand ring-2 ring-brand/30',
                        )
                    "
                    data-test="automation-row"
                    @dragover.prevent="dragOver = rule.uuid"
                    @dragleave="dragOver = dragOver === rule.uuid ? null : dragOver"
                    @drop.prevent="onDrop(rule)"
                >
                    <!-- Reorder -->
                    <div class="flex flex-col items-center gap-0.5 pt-0.5 text-muted-foreground">
                        <button
                            type="button"
                            class="cursor-grab rounded p-0.5 hover:text-foreground active:cursor-grabbing"
                            draggable="true"
                            aria-label="Drag to reorder"
                            @dragstart="onDragStart($event, rule)"
                            @dragend="dragging = null; dragOver = null"
                        >
                            <GripVertical class="size-4" />
                        </button>
                        <Button variant="ghost" size="icon-xs" aria-label="Move up" :disabled="i === 0" @click="move(i, i - 1)"><ArrowUp /></Button>
                        <Button variant="ghost" size="icon-xs" aria-label="Move down" :disabled="i === items.length - 1" @click="move(i, i + 1)"><ArrowDown /></Button>
                    </div>

                    <!-- Body -->
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <Link :href="edit(routeArgs(rule))" class="block truncate font-medium hover:text-brand" :class="!rule.is_active && 'text-muted-foreground'">
                                    {{ rule.name }}
                                </Link>
                                <p v-if="rule.description" class="truncate text-xs text-muted-foreground">{{ rule.description }}</p>
                            </div>

                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <span class="flex items-center">
                                        <Switch
                                            :model-value="rule.is_active"
                                            :disabled="busy.has(rule.uuid)"
                                            class="data-[state=checked]:bg-brand"
                                            :aria-label="rule.is_active ? `Pause ${rule.name}` : `Enable ${rule.name}`"
                                            @update:model-value="(v) => setActive(rule, Boolean(v))"
                                        />
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent>{{ rule.is_active ? 'On. Click to pause.' : 'Paused. Click to enable.' }}</TooltipContent>
                            </Tooltip>

                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button variant="ghost" size="icon-sm" aria-label="More"><MoreHorizontal /></Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem as-child>
                                        <Link :href="edit(routeArgs(rule))"><Pencil /> Edit</Link>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem :disabled="busy.has(rule.uuid)" @select="duplicate(rule)"><Copy /> Duplicate</DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem variant="destructive" @select="deleting = rule"><Trash2 /> Delete</DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>

                        <!-- Chips -->
                        <div class="mt-2.5 flex flex-wrap items-center gap-1.5 text-xs">
                            <span class="inline-flex items-center gap-1 rounded-full bg-brand-soft px-2 py-0.5 font-medium text-brand-soft-foreground">
                                <Zap class="size-3" /> {{ rule.trigger.label }}
                            </span>
                            <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-muted-foreground">
                                <Inbox class="size-3" /> {{ rule.inbox?.name ?? 'All inboxes' }}
                            </span>
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-muted-foreground">{{ conditionLabel(rule) }}</span>
                            <span class="text-muted-foreground/60">→</span>
                            <span v-for="(type, j) in rule.action_types" :key="`${type}-${j}`" class="inline-flex items-center rounded-full bg-secondary px-2 py-0.5 text-secondary-foreground">
                                {{ actionLabel(catalog, type) }}
                            </span>
                        </div>

                        <p class="mt-2 text-[11px] text-muted-foreground">
                            <template v-if="rule.run_count === 0">Has not run yet.</template>
                            <template v-else>Ran {{ rule.run_count }} {{ rule.run_count === 1 ? 'time' : 'times' }}, last {{ relative(rule.last_run_at) }}.</template>
                        </p>
                    </div>
                </li>
            </ol>
        </TooltipProvider>

        <Dialog :open="deleting !== null" @update:open="(v) => !v && (deleting = null)">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>Delete “{{ deleting?.name }}”?</DialogTitle>
                    <DialogDescription>The rule stops immediately. Its run history stays around, and nothing that already happened is undone.</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="deleting = null">Keep it</Button>
                    <Button variant="destructive" @click="confirmDelete">Delete rule</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
