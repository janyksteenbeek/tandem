<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ChevronDown, Copy, History, Loader2, Plus, Save, Trash2 } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import ActionRow from '@/components/automations/ActionRow.vue';
import ConditionRow from '@/components/automations/ConditionRow.vue';
import DryRunPanel from '@/components/automations/DryRunPanel.vue';
import RuleSummary from '@/components/automations/RuleSummary.vue';
import TriggerPicker from '@/components/automations/TriggerPicker.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Kbd } from '@/components/ui/kbd';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useKeyboard } from '@/composables/useKeyboard';
import { actionLabel, describeAction, describeCondition, emptyAction, emptyCondition, emptyRuleForm, formatMinutes, starterRule } from '@/lib/automations';
import { api, reportError } from '@/lib/http';
import { fullTime, relative } from '@/lib/time';
import { cn } from '@/lib/utils';
import { create, destroy, index, runs as runsRoute, store, update } from '@/routes/automations';
import type {
    AutomationCatalog,
    AutomationInboxOption,
    AutomationLookups,
    AutomationRuleDetail,
    AutomationRuleForm,
    AutomationRun,
    AutomationRunPage,
    AutomationRunStatus,
    AutomationTagOption,
    AutomationTeamOption,
    AutomationTemplateOption,
    AutomationUserOption,
} from '@/types/automations';
import type { Organization } from '@/types';

const props = defineProps<{
    organization: Pick<Organization, 'id' | 'name' | 'slug'>;
    rule: AutomationRuleDetail | null;
    recentRuns: AutomationRun[];
    inboxes: AutomationInboxOption[];
    tags: AutomationTagOption[];
    users: AutomationUserOption[];
    teams: AutomationTeamOption[];
    templates: AutomationTemplateOption[];
    catalog: AutomationCatalog;
}>();

defineOptions({
    layout: (props: { organization: { slug: string }; rule: AutomationRuleDetail | null }) => ({
        breadcrumbs: [
            { title: 'Automations', href: index(props.organization.slug) },
            {
                title: props.rule ? props.rule.name : 'New rule',
                href: props.rule ? `/${props.organization.slug}/settings/automations/${props.rule.uuid}/edit` : create(props.organization.slug),
            },
        ],
    }),
});

const page = usePage();
const slug = computed(() => props.organization.slug);
const lookups = computed<AutomationLookups>(() => ({
    inboxes: props.inboxes,
    tags: props.tags,
    users: props.users,
    teams: props.teams,
    templates: props.templates,
}));

const query = new URLSearchParams(page.url.split('?')[1] ?? '');
const starterKey = query.get('starter');

const initialForm = (): AutomationRuleForm => {
    if (props.rule) {
        return {
            name: props.rule.name,
            description: props.rule.description ?? '',
            inbox_uuid: props.rule.inbox_uuid,
            trigger: props.rule.trigger.value,
            match: props.rule.match,
            is_active: props.rule.is_active,
            cooldown_minutes: props.rule.cooldown_minutes,
            conditions: props.rule.conditions.map((condition) => ({ ...condition })),
            actions: props.rule.actions.map((action) => ({ type: action.type, params: { ...action.params } })),
        };
    }

    return starterRule(starterKey, props.catalog, lookups.value) ?? emptyRuleForm(props.catalog);
};

const form = useForm<AutomationRuleForm>(initialForm());
const startedFrom = ref<string | null>(starterKey ? 'starter' : null);

onMounted(() => {
    if (props.rule || query.get('from') !== 'duplicate') {
        return;
    }

    try {
        const raw = sessionStorage.getItem('tandem:automation-duplicate');

        if (raw) {
            const draft = JSON.parse(raw) as AutomationRuleForm;
            form.defaults(draft);
            form.reset();
            startedFrom.value = 'duplicate';
            sessionStorage.removeItem('tandem:automation-duplicate');
        }
    } catch {
        // A broken draft just means we start empty.
    }
});

const isEdit = computed(() => props.rule !== null);
const title = computed(() => (isEdit.value ? props.rule!.name : 'New rule'));

const submit = () => {
    const options = { preserveScroll: true };

    if (props.rule) {
        form.put(update.url({ current_organization: slug.value, rule: props.rule.uuid }), options);
    } else {
        form.post(store.url(slug.value), options);
    }
};

useKeyboard(() => [{ keys: 'mod+s', description: 'Save rule', handler: (event) => { event.preventDefault(); submit(); }, allowInInput: true }]);

const errors = computed(() => form.errors as Record<string, string | undefined>);

const conditionErrors = (i: number) => ({
    field: errors.value[`conditions.${i}.field`] ?? errors.value[`conditions.${i}`],
    operator: errors.value[`conditions.${i}.operator`],
    value: errors.value[`conditions.${i}.value`] ?? Object.entries(errors.value).find(([key]) => key.startsWith(`conditions.${i}.value.`))?.[1],
});

const actionErrors = (i: number): Record<string, string | undefined> => {
    const result: Record<string, string | undefined> = { type: errors.value[`actions.${i}.type`] ?? errors.value[`actions.${i}`] };
    const prefix = `actions.${i}.params.`;

    Object.entries(errors.value).forEach(([key, message]) => {
        if (key.startsWith(prefix)) {
            result[key.slice(prefix.length).split('.')[0]] = message;
        }
    });

    return result;
};

const addCondition = () => form.conditions.push(emptyCondition(props.catalog));
const removeCondition = (i: number) => form.conditions.splice(i, 1);
const addAction = (type: string) => form.actions.push(emptyAction(props.catalog, type));
const removeAction = (i: number) => form.actions.splice(i, 1);

const cooldownModel = computed({
    get: () => (form.cooldown_minutes === null ? '' : String(form.cooldown_minutes)),
    set: (value: string | number) => (form.cooldown_minutes = value === '' ? null : Math.max(0, Number(value))),
});

const conjunction = computed(() => (form.match === 'any' ? 'or' : 'and'));

/* Delete */
const confirmingDelete = ref(false);
const deleteRule = () => {
    if (!props.rule) {
        return;
    }

    router.delete(destroy.url({ current_organization: slug.value, rule: props.rule.uuid }));
};

/* Duplicate */
const duplicate = () => {
    sessionStorage.setItem('tandem:automation-duplicate', JSON.stringify({ ...form.data(), name: `${form.name} (copy)`, is_active: false }));
    router.visit(create.url(slug.value, { query: { from: 'duplicate' } }));
};

/* Recent runs */
const runs = ref<AutomationRun[]>(props.recentRuns);
const runFilter = ref<AutomationRunStatus | ''>('');
const runsLoading = ref(false);
const runsMeta = ref<AutomationRunPage['meta'] | null>(null);

const loadRuns = async (status: AutomationRunStatus | '' = runFilter.value, pageNumber = 1) => {
    if (!props.rule) {
        return;
    }

    runFilter.value = status;
    runsLoading.value = true;

    try {
        const response = await api.get<AutomationRunPage>(runsRoute.url({ current_organization: slug.value, rule: props.rule.uuid }), {
            status: status || undefined,
            per_page: 20,
            page: pageNumber,
        });
        runs.value = pageNumber === 1 ? response.data : [...runs.value, ...response.data];
        runsMeta.value = response.meta;
    } catch (error) {
        reportError(error, 'Could not load the run history.');
    } finally {
        runsLoading.value = false;
    }
};

const RUN_META: Record<AutomationRunStatus, { label: string; dot: string }> = {
    matched: { label: 'Matched', dot: 'bg-success' },
    skipped: { label: 'Skipped', dot: 'bg-muted-foreground/60' },
    failed: { label: 'Failed', dot: 'bg-destructive' },
};

const runReason = (run: AutomationRun): string | null => {
    const details = run.details ?? {};

    if (run.status === 'failed') {
        return details.error ?? 'Something went wrong.';
    }

    if (run.status === 'skipped') {
        return details.reason === 'cooldown' ? 'Cooling down' : details.reason === 'conditions_not_met' ? 'Conditions not met' : (details.reason ?? null);
    }

    return null;
};
</script>

<template>
    <Head :title="title" />

    <form class="w-full space-y-6" @submit.prevent="submit">
        <!-- Header -->
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <Link :href="index(slug)" class="mb-2 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><ArrowLeft class="size-3.5" /> All automations</Link>
                <h1 class="truncate font-display text-3xl leading-none">{{ form.name.trim() || title }}</h1>
                <p class="mt-1.5 text-sm text-muted-foreground">
                    <template v-if="startedFrom === 'starter'">Prefilled from a starter. Change anything, then save.</template>
                    <template v-else-if="startedFrom === 'duplicate'">A copy, paused until you say otherwise.</template>
                    <template v-else-if="isEdit">Changes apply to the next event. Past runs are untouched.</template>
                    <template v-else>Describe when, check if, and say what should happen.</template>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span v-if="form.isDirty" class="hidden text-xs text-muted-foreground sm:inline">Unsaved changes</span>
                <Button v-if="isEdit" type="button" variant="ghost" size="sm" @click="duplicate"><Copy /> Duplicate</Button>
                <Button v-if="isEdit" type="button" variant="ghost" size="sm" class="text-muted-foreground hover:text-destructive" @click="confirmingDelete = true"><Trash2 /> Delete</Button>
                <Button type="submit" class="bg-brand text-brand-foreground hover:bg-brand/90" :disabled="form.processing">
                    <Loader2 v-if="form.processing" class="animate-spin" />
                    <Save v-else />
                    {{ isEdit ? 'Save changes' : 'Create rule' }}
                    <Kbd class="hidden bg-brand-foreground/15 text-brand-foreground sm:inline-flex">⌘S</Kbd>
                </Button>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_21rem] xl:items-start">
            <!-- Builder -->
            <div class="space-y-5">
                <!-- When -->
                <section class="rounded-2xl border bg-surface p-4 sm:p-5">
                    <header class="mb-4 flex items-baseline gap-3">
                        <span class="font-display text-2xl text-brand">When</span>
                        <span class="text-sm text-muted-foreground">this happens</span>
                    </header>
                    <TriggerPicker v-model:trigger="form.trigger" v-model:inbox="form.inbox_uuid" :catalog="catalog" :inboxes="inboxes" :errors="{ trigger: errors.trigger, inbox_uuid: errors.inbox_uuid }" />
                </section>

                <!-- If -->
                <section class="rounded-2xl border bg-surface p-4 sm:p-5">
                    <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-baseline gap-3">
                            <span class="font-display text-2xl text-brand">If</span>
                            <span class="text-sm text-muted-foreground">{{ form.conditions.length === 0 ? 'always (no conditions)' : 'these checks pass' }}</span>
                        </div>
                        <div v-if="form.conditions.length > 1" class="inline-flex rounded-lg border bg-card p-0.5 text-xs" role="radiogroup" aria-label="How conditions combine">
                            <button
                                v-for="option in catalog.match"
                                :key="option.value"
                                type="button"
                                role="radio"
                                :aria-checked="form.match === option.value"
                                :class="cn('rounded-md px-2.5 py-1 transition', form.match === option.value ? 'bg-brand text-brand-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground')"
                                @click="form.match = option.value as 'all' | 'any'"
                            >
                                {{ option.value === 'all' ? 'All must match' : 'Any may match' }}
                            </button>
                        </div>
                    </header>

                    <div class="space-y-2">
                        <ConditionRow
                            v-for="(condition, i) in form.conditions"
                            :key="i"
                            v-model="form.conditions[i]"
                            :index="i"
                            :catalog="catalog"
                            :lookups="lookups"
                            :conjunction="conjunction"
                            :errors="conditionErrors(i)"
                            @remove="removeCondition(i)"
                        />
                        <p v-if="form.conditions.length === 0" class="rounded-xl border border-dashed bg-card/50 p-4 text-sm text-muted-foreground">
                            No conditions means every event runs the actions. Add one to narrow it down, e.g. “sender ends with @acme.com”.
                        </p>
                    </div>
                    <InputError class="mt-2" :message="errors.conditions" />
                    <Button type="button" variant="outline" size="sm" class="mt-3" :disabled="form.conditions.length >= 25" @click="addCondition"><Plus /> Add condition</Button>
                </section>

                <!-- Then -->
                <section class="rounded-2xl border bg-surface p-4 sm:p-5">
                    <header class="mb-4 flex items-baseline gap-3">
                        <span class="font-display text-2xl text-brand">Then</span>
                        <span class="text-sm text-muted-foreground">do this, in order</span>
                    </header>

                    <div class="space-y-2">
                        <ActionRow v-for="(action, i) in form.actions" :key="i" v-model="form.actions[i]" :index="i" :catalog="catalog" :lookups="lookups" :errors="actionErrors(i)" @remove="removeAction(i)" />
                        <p v-if="form.actions.length === 0" class="rounded-xl border border-dashed bg-card/50 p-4 text-sm text-muted-foreground">A rule without actions is just a thought. Add at least one.</p>
                    </div>
                    <InputError class="mt-2" :message="errors.actions" />

                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button type="button" variant="outline" size="sm" class="mt-3" :disabled="form.actions.length >= 15"><Plus /> Add action <ChevronDown class="opacity-60" /></Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" class="max-h-80 overflow-y-auto">
                            <DropdownMenuItem v-for="option in catalog.actions" :key="option.value" @select="addAction(option.value)">{{ option.label }}</DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </section>
            </div>

            <!-- Sidebar -->
            <aside class="space-y-4 xl:sticky xl:top-4">
                <section class="space-y-4 rounded-xl border bg-card p-4 shadow-pane">
                    <div class="grid gap-1.5">
                        <Label for="rule-name">Name</Label>
                        <Input id="rule-name" v-model="form.name" placeholder="e.g. Route billing to Finance" maxlength="255" required :aria-invalid="errors.name ? true : undefined" />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="rule-description">Description <span class="font-normal text-muted-foreground">(optional)</span></Label>
                        <Textarea id="rule-description" v-model="form.description" rows="2" maxlength="255" placeholder="Why this rule exists, for future you." :aria-invalid="errors.description ? true : undefined" />
                        <InputError :message="errors.description" />
                    </div>
                    <div class="flex items-center justify-between gap-3 rounded-lg bg-surface px-3 py-2">
                        <div>
                            <Label for="rule-active" class="cursor-pointer">{{ form.is_active ? 'Active' : 'Paused' }}</Label>
                            <p class="text-xs text-muted-foreground">{{ form.is_active ? 'Runs on the next event.' : 'Saved, but sitting out.' }}</p>
                        </div>
                        <Switch id="rule-active" v-model="form.is_active" class="data-[state=checked]:bg-brand" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="rule-cooldown">Per-conversation cooldown</Label>
                        <div class="flex items-center gap-2">
                            <Input id="rule-cooldown" v-model="cooldownModel" type="number" min="0" max="525600" step="1" class="w-28" placeholder="none" :aria-invalid="errors.cooldown_minutes ? true : undefined" />
                            <span class="text-sm text-muted-foreground">minutes · {{ formatMinutes(form.cooldown_minutes) }}</span>
                        </div>
                        <p class="text-xs text-muted-foreground">Skip the same conversation if this rule already matched it within the window.</p>
                        <InputError :message="errors.cooldown_minutes" />
                    </div>
                </section>

                <RuleSummary :rule="form" :catalog="catalog" :lookups="lookups" :active="form.is_active" />

                <DryRunPanel :slug="slug" :rule-uuid="rule?.uuid ?? null" :conditions="form.conditions" :match="form.match" :catalog="catalog" :lookups="lookups" />

                <!-- Recent runs -->
                <section v-if="isEdit" class="rounded-xl border bg-card p-4 shadow-pane">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <History class="size-4 text-brand" />
                            <h3 class="text-sm font-semibold">Recent runs</h3>
                        </div>
                        <Loader2 v-if="runsLoading" class="size-4 animate-spin text-muted-foreground" />
                    </div>

                    <div class="mb-3 flex flex-wrap gap-1 text-xs">
                        <button
                            v-for="option in [{ value: '', label: 'All' }, { value: 'matched', label: 'Matched' }, { value: 'skipped', label: 'Skipped' }, { value: 'failed', label: 'Failed' }]"
                            :key="option.value"
                            type="button"
                            :class="cn('rounded-full border px-2.5 py-0.5 transition', runFilter === option.value ? 'border-brand bg-brand-soft text-brand-soft-foreground' : 'text-muted-foreground hover:text-foreground')"
                            @click="loadRuns(option.value as AutomationRunStatus | '')"
                        >
                            {{ option.label }}
                        </button>
                    </div>

                    <p v-if="runs.length === 0" class="rounded-lg border border-dashed p-3 text-xs text-muted-foreground">
                        {{ runFilter ? 'No runs with that outcome.' : 'Not a single run yet. The next matching event will show up here.' }}
                    </p>

                    <ul v-else class="divide-y text-sm">
                        <li v-for="run in runs" :key="run.id">
                            <Collapsible>
                                <CollapsibleTrigger class="group flex w-full items-start gap-2 py-2 text-left">
                                    <span :class="cn('mt-1.5 size-2 shrink-0 rounded-full', RUN_META[run.status]?.dot)" />
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-center justify-between gap-2">
                                            <span class="font-medium">{{ RUN_META[run.status]?.label ?? run.status }}</span>
                                            <span class="shrink-0 text-[11px] text-muted-foreground" :title="fullTime(run.created_at)">{{ relative(run.created_at) }}</span>
                                        </span>
                                        <span class="block truncate text-xs text-muted-foreground">{{ run.conversation?.subject || 'Conversation' }}<template v-if="runReason(run)"> · {{ runReason(run) }}</template></span>
                                    </span>
                                    <ChevronDown class="mt-1 size-3.5 shrink-0 text-muted-foreground transition group-data-[state=open]:rotate-180" />
                                </CollapsibleTrigger>
                                <CollapsibleContent>
                                    <div class="mb-2 ml-4 space-y-2 rounded-lg bg-surface p-3 text-xs">
                                        <p class="text-muted-foreground">Trigger: {{ run.trigger.replace(/_/g, ' ') }} · {{ fullTime(run.created_at) }}</p>
                                        <div v-if="run.details?.conditions?.length">
                                            <p class="mb-1 font-medium">Conditions</p>
                                            <ul class="space-y-0.5">
                                                <li v-for="(check, i) in run.details.conditions" :key="i" class="flex items-start gap-1.5">
                                                    <span :class="cn('mt-1 size-1.5 shrink-0 rounded-full', check.passed ? 'bg-success' : 'bg-destructive')" />
                                                    <span>{{ describeCondition(check, catalog, lookups) }} <span class="text-muted-foreground">(actual: {{ check.actual === null || check.actual === '' ? 'empty' : Array.isArray(check.actual) ? check.actual.join(', ') : check.actual }})</span></span>
                                                </li>
                                            </ul>
                                        </div>
                                        <div v-if="run.details?.actions?.length">
                                            <p class="mb-1 font-medium">Actions</p>
                                            <ul class="space-y-0.5">
                                                <li v-for="(done, i) in run.details.actions" :key="i" class="flex items-start gap-1.5">
                                                    <span :class="cn('mt-1 size-1.5 shrink-0 rounded-full', done.status === 'skipped' ? 'bg-muted-foreground/60' : 'bg-success')" />
                                                    <span>
                                                        {{ actionLabel(catalog, done.type) }}
                                                        <span class="text-muted-foreground">· {{ done.status }}<template v-if="done.reason"> ({{ String(done.reason).replace(/_/g, ' ') }})</template></span>
                                                    </span>
                                                </li>
                                            </ul>
                                        </div>
                                        <p v-if="run.details?.error" class="rounded bg-destructive/10 p-2 font-mono text-[11px] text-destructive">{{ run.details.error }}</p>
                                    </div>
                                </CollapsibleContent>
                            </Collapsible>
                        </li>
                    </ul>

                    <Button v-if="runsMeta && runsMeta.current_page < runsMeta.last_page" type="button" variant="ghost" size="sm" class="mt-2 w-full" :disabled="runsLoading" @click="loadRuns(runFilter, runsMeta.current_page + 1)">Load older runs</Button>
                    <Button v-else-if="!runsMeta && runs.length >= 20" type="button" variant="ghost" size="sm" class="mt-2 w-full" :disabled="runsLoading" @click="loadRuns(runFilter, 1)">Browse all runs</Button>
                </section>

                <!-- What this does, as a list (handy at narrow widths) -->
                <section v-if="form.actions.length > 0" class="hidden rounded-xl border bg-card p-4 text-xs shadow-pane xl:block">
                    <p class="mb-2 font-semibold">Order of operations</p>
                    <ol class="list-decimal space-y-1 pl-4 text-muted-foreground">
                        <li v-for="(action, i) in form.actions" :key="i">{{ describeAction(action, catalog, lookups) }}</li>
                    </ol>
                </section>
            </aside>
        </div>

        <Dialog v-model:open="confirmingDelete">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>Delete “{{ rule?.name }}”?</DialogTitle>
                    <DialogDescription>The rule stops immediately. Its run history stays around, and nothing that already happened is undone.</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="ghost" @click="confirmingDelete = false">Keep it</Button>
                    <Button type="button" variant="destructive" @click="deleteRule">Delete rule</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </form>
</template>
