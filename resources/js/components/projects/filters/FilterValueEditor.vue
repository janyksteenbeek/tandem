<script lang="ts">
import type { FilterField } from '@/types';

export type FieldDefinition = {
    field: FilterField;
    label: string;
    kind: 'list' | 'date' | 'boolean' | 'text' | 'number';
};

export const FIELD_DEFINITIONS: FieldDefinition[] = [
    { field: 'status', label: 'Status', kind: 'list' },
    { field: 'status_category', label: 'Status category', kind: 'list' },
    { field: 'priority', label: 'Priority', kind: 'list' },
    { field: 'assignee', label: 'Assignee', kind: 'list' },
    { field: 'owner', label: 'Owner', kind: 'list' },
    { field: 'team', label: 'Team', kind: 'list' },
    { field: 'product', label: 'Product', kind: 'list' },
    { field: 'module', label: 'Module', kind: 'list' },
    { field: 'project', label: 'Project', kind: 'list' },
    { field: 'label', label: 'Label', kind: 'list' },
    { field: 'cycle', label: 'Cycle', kind: 'list' },
    { field: 'milestone', label: 'Milestone', kind: 'list' },
    { field: 'due_date', label: 'Due date', kind: 'date' },
    { field: 'created_at', label: 'Created', kind: 'date' },
    { field: 'blocked', label: 'Blocked', kind: 'boolean' },
    { field: 'ready_to_complete', label: 'Ready to complete', kind: 'boolean' },
    { field: 'has_sub_issues', label: 'Has sub-issues', kind: 'boolean' },
    { field: 'created_by', label: 'Created by', kind: 'list' },
    { field: 'title', label: 'Title contains', kind: 'text' },
    { field: 'estimate', label: 'Estimate', kind: 'number' },
];
</script>

<script setup lang="ts">
import { computed } from 'vue';
import PriorityIcon from '@/components/projects/shared/PriorityIcon.vue';
import StatusIcon from '@/components/projects/shared/StatusIcon.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import {
    CATEGORY_META,
    CATEGORY_ORDER,
    PRIORITY_META,
    PRIORITY_ORDER,
} from '@/lib/projects/meta';
import { chipClass } from '@/lib/mail';
import { cn } from '@/lib/utils';
import type {
    FilterClause,
    FilterOperator,
    IssueLookups,
    WorkflowStatus,
} from '@/types';

type Option = {
    value: string;
    label: string;
    status?: WorkflowStatus;
    color?: string | null;
    avatar?: {
        name: string;
        email: string;
        src: string | null;
        initials: string;
    };
};

/**
 * Edits the operator and value of one clause. List fields get a multi-select; dates get
 * presets; booleans a toggle; text and numbers an input.
 */
const props = defineProps<{
    clause: FilterClause;
    lookups: IssueLookups | null;
    workflowStatuses: WorkflowStatus[];
    productId?: number | null;
}>();
const emit = defineEmits<{
    'update:clause': [clause: FilterClause];
    done: [];
}>();

const definition = computed(
    () =>
        FIELD_DEFINITIONS.find((d) => d.field === props.clause.field) ??
        FIELD_DEFINITIONS[0],
);

const options = computed<Option[]>(() => {
    const l = props.lookups;

    switch (props.clause.field) {
        case 'status':
            return props.workflowStatuses.map((s) => ({
                value: String(s.id),
                label: s.name,
                status: s,
            }));
        case 'status_category':
            return CATEGORY_ORDER.map((c) => ({
                value: c,
                label: CATEGORY_META[c].label,
            }));
        case 'priority':
            return PRIORITY_ORDER.map((p) => ({
                value: p,
                label: PRIORITY_META[p].label,
            }));
        case 'assignee':
        case 'owner':
        case 'created_by':
            return [
                { value: 'me', label: 'Me' },
                ...(l?.members ?? []).map((m) => ({
                    value: String(m.id),
                    label: m.name,
                    avatar: {
                        name: m.name,
                        email: m.email,
                        src: m.avatar,
                        initials: m.initials,
                    },
                })),
            ];
        case 'team':
            return (l?.teams ?? []).map((t) => ({
                value: String(t.id),
                label: t.name,
                color: t.color,
            }));
        case 'product':
            return (l?.products ?? []).map((p) => ({
                value: String(p.id),
                label: p.name,
                color: p.color,
            }));
        case 'module':
            return (l?.modules ?? [])
                .filter(
                    (m) => !props.productId || m.product_id === props.productId,
                )
                .map((m) => ({
                    value: String(m.id),
                    label: m.name,
                    color: m.color,
                }));
        case 'project':
            return (l?.projects ?? []).map((p) => ({
                value: String(p.id),
                label: p.name,
                color: p.color,
            }));
        case 'label':
            return (l?.labels ?? []).map((lb) => ({
                value: String(lb.id),
                label: lb.name,
                color: lb.color,
            }));
        case 'cycle':
            return (l?.cycles ?? []).map((c) => ({
                value: String(c.id),
                label: c.name,
            }));
        case 'milestone':
            return (l?.milestones ?? []).map((m) => ({
                value: String(m.id),
                label: m.name,
            }));
        default:
            return [];
    }
});

const values = computed<string[]>(() => {
    const v = props.clause.value;

    return v === null || v === undefined
        ? []
        : (Array.isArray(v) ? v : [v]).map(String);
});

const update = (changes: Partial<FilterClause>) =>
    emit('update:clause', { ...props.clause, ...changes });

const toggleValue = (value: string) => {
    const next = values.value.includes(value)
        ? values.value.filter((v) => v !== value)
        : [...values.value, value];
    update({ value: next, op: props.clause.op === 'not_in' ? 'not_in' : 'in' });
};

const datePresets: {
    label: string;
    op: FilterOperator;
    value: string | null;
}[] = [
    { label: 'Overdue', op: 'overdue', value: null },
    { label: 'Today or earlier', op: 'before', value: 'today' },
    { label: 'Within a week', op: 'before', value: 'week' },
    { label: 'Within a month', op: 'before', value: 'month' },
    { label: 'Has no date', op: 'is_empty', value: null },
    { label: 'Has a date', op: 'is_not_empty', value: null },
];

const negated = computed(() => props.clause.op === 'not_in');
</script>

<template>
    <div class="w-64">
        <template v-if="definition.kind === 'list'">
            <Command>
                <CommandInput
                    :placeholder="`Filter ${definition.label.toLowerCase()}…`"
                />
                <CommandList class="max-h-64">
                    <CommandEmpty>Nothing found.</CommandEmpty>
                    <CommandGroup>
                        <CommandItem
                            v-for="option in options"
                            :key="option.value"
                            :value="option.value"
                            class="gap-2"
                            @select="toggleValue(option.value)"
                        >
                            <Checkbox
                                :model-value="values.includes(option.value)"
                                class="size-3.5"
                                tabindex="-1"
                            />
                            <StatusIcon
                                v-if="option.status"
                                :status="option.status"
                                :size="13"
                            />
                            <PriorityIcon
                                v-else-if="clause.field === 'priority'"
                                :priority="option.value as never"
                                :size="13"
                            />
                            <UserAvatar
                                v-else-if="option.avatar"
                                :name="option.avatar.name"
                                :email="option.avatar.email"
                                :src="option.avatar.src"
                                :initials="option.avatar.initials"
                                size="xs"
                            />
                            <InboxDot
                                v-else-if="option.color"
                                :color="option.color"
                                size="sm"
                            />
                            <span class="truncate">{{ option.label }}</span>
                            <span v-if="option.label" class="sr-only">{{
                                option.label
                            }}</span>
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
            <div
                class="flex items-center justify-between border-t px-2 py-1.5 text-[11.5px]"
            >
                <label class="flex items-center gap-1.5 text-muted-foreground">
                    <Checkbox
                        :model-value="negated"
                        class="size-3.5"
                        @update:model-value="
                            (on) => update({ op: on ? 'not_in' : 'in' })
                        "
                    />
                    Exclude these
                </label>
                <Button
                    size="sm"
                    variant="ghost"
                    class="h-6 px-2 text-[11.5px]"
                    @click="emit('done')"
                    >Done</Button
                >
            </div>
        </template>

        <div v-else-if="definition.kind === 'date'" class="p-1.5">
            <button
                v-for="preset in datePresets"
                :key="preset.label"
                type="button"
                :class="
                    cn(
                        'flex w-full items-center rounded-md px-2 py-1.5 text-left text-[12.5px] hover:bg-accent',
                        clause.op === preset.op &&
                            (preset.value === null ||
                                values[0] === preset.value) &&
                            'bg-brand-soft/60 text-brand-soft-foreground',
                    )
                "
                @click="
                    update({ op: preset.op, value: preset.value });
                    emit('done');
                "
            >
                {{ preset.label }}
            </button>
            <div class="mt-1 flex items-center gap-1 border-t pt-1.5">
                <span class="text-[11.5px] text-muted-foreground">Before</span>
                <Input
                    type="date"
                    class="h-7 text-xs"
                    :model-value="
                        clause.op === 'before' &&
                        values[0] &&
                        values[0].includes('-')
                            ? values[0]
                            : ''
                    "
                    @update:model-value="
                        (v) => v && update({ op: 'before', value: String(v) })
                    "
                />
            </div>
        </div>

        <div v-else-if="definition.kind === 'boolean'" class="p-1.5">
            <button
                v-for="opt in [
                    ['is_true', 'Yes'],
                    ['is_false', 'No'],
                ]"
                :key="opt[0]"
                type="button"
                :class="
                    cn(
                        'flex w-full items-center rounded-md px-2 py-1.5 text-left text-[12.5px] hover:bg-accent',
                        clause.op === opt[0] &&
                            'bg-brand-soft/60 text-brand-soft-foreground',
                    )
                "
                @click="
                    update({ op: opt[0] as FilterOperator, value: null });
                    emit('done');
                "
            >
                {{ definition.label }}: {{ opt[1] }}
            </button>
        </div>

        <div v-else class="space-y-2 p-2">
            <div v-if="definition.kind === 'number'" class="flex gap-1">
                <button
                    v-for="op in ['eq', 'gt', 'lt'] as const"
                    :key="op"
                    type="button"
                    :class="
                        cn(
                            'rounded-md border px-2 py-0.5 text-[11.5px]',
                            clause.op === op && 'border-brand bg-brand-soft/60',
                        )
                    "
                    @click="update({ op })"
                >
                    {{ { eq: '=', gt: '>', lt: '<' }[op] }}
                </button>
            </div>
            <Input
                :type="definition.kind === 'number' ? 'number' : 'text'"
                class="h-7 text-xs"
                :model-value="values[0] ?? ''"
                :placeholder="definition.kind === 'number' ? '3' : 'Text…'"
                autofocus
                @update:model-value="
                    (v) =>
                        update({
                            op:
                                definition.kind === 'number'
                                    ? ['eq', 'gt', 'lt'].includes(clause.op)
                                        ? clause.op
                                        : 'eq'
                                    : clause.op === 'not_contains'
                                      ? 'not_contains'
                                      : 'contains',
                            value: String(v),
                        })
                "
                @keydown.enter="emit('done')"
            />
        </div>
    </div>
</template>
