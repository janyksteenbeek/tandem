<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed, ref } from 'vue';
import FilterValueEditor, {
    FIELD_DEFINITIONS,
} from '@/components/projects/filters/FilterValueEditor.vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { CATEGORY_META, PRIORITY_META } from '@/lib/projects/meta';
import type {
    FilterClause,
    IssueLookups,
    StatusCategory,
    IssuePriority,
    WorkflowStatus,
} from '@/types';

/** One active filter as a chip: "Status is Todo, In progress". Click to edit, × to remove. */
const props = defineProps<{
    clause: FilterClause;
    lookups: IssueLookups | null;
    workflowStatuses: WorkflowStatus[];
    open?: boolean;
}>();
const emit = defineEmits<{
    'update:clause': [clause: FilterClause];
    remove: [];
}>();

const editing = ref(props.open ?? false);

const definition = computed(() =>
    FIELD_DEFINITIONS.find((d) => d.field === props.clause.field),
);

const names = computed<string[]>(() => {
    const values =
        props.clause.value === null || props.clause.value === undefined
            ? []
            : (Array.isArray(props.clause.value)
                  ? props.clause.value
                  : [props.clause.value]
              ).map(String);
    const l = props.lookups;
    const byId = (list: { id: number; name: string }[] | undefined) =>
        values.map((v) => list?.find((x) => String(x.id) === v)?.name ?? v);

    switch (props.clause.field) {
        case 'status':
            return values.map(
                (v) =>
                    props.workflowStatuses.find((s) => String(s.id) === v)
                        ?.name ?? v,
            );
        case 'status_category':
            return values.map(
                (v) => CATEGORY_META[v as StatusCategory]?.label ?? v,
            );
        case 'priority':
            return values.map(
                (v) => PRIORITY_META[v as IssuePriority]?.label ?? v,
            );
        case 'assignee':
        case 'owner':
        case 'created_by':
            return values.map((v) =>
                v === 'me'
                    ? 'me'
                    : (l?.members.find((m) => String(m.id) === v)?.name ?? v),
            );
        case 'team':
            return byId(l?.teams);
        case 'product':
            return byId(l?.products);
        case 'module':
            return byId(l?.modules);
        case 'project':
            return byId(l?.projects);
        case 'label':
            return byId(l?.labels);
        case 'cycle':
            return byId(l?.cycles);
        case 'milestone':
            return byId(l?.milestones);
        default:
            return values;
    }
});

const operatorLabel = computed(() => {
    switch (props.clause.op) {
        case 'not_in':
            return 'is not';
        case 'is_empty':
            return 'is empty';
        case 'is_not_empty':
            return 'is set';
        case 'is_true':
            return 'yes';
        case 'is_false':
            return 'no';
        case 'before':
            return 'before';
        case 'after':
            return 'after';
        case 'between':
            return 'between';
        case 'overdue':
            return 'overdue';
        case 'gt':
            return '>';
        case 'lt':
            return '<';
        case 'eq':
            return '=';
        case 'contains':
            return 'contains';
        case 'not_contains':
            return "doesn't contain";
        default:
            return 'is';
    }
});

const valueLabel = computed(() => {
    if (
        ['is_empty', 'is_not_empty', 'is_true', 'is_false', 'overdue'].includes(
            props.clause.op,
        )
    ) {
        return '';
    }

    const list = names.value.map(
        (n) => ({ today: 'today', week: 'a week', month: 'a month' })[n] ?? n,
    );

    return list.length > 3
        ? `${list.slice(0, 3).join(', ')} +${list.length - 3}`
        : list.join(', ');
});
</script>

<template>
    <Popover v-model:open="editing">
        <span
            class="inline-flex h-7 items-stretch overflow-hidden rounded-md border bg-surface text-[11.5px]"
        >
            <PopoverTrigger as-child>
                <button
                    type="button"
                    class="flex items-center gap-1 px-2 hover:bg-accent"
                >
                    <span class="text-muted-foreground">{{
                        definition?.label ?? clause.field
                    }}</span>
                    <span class="text-muted-foreground/70">{{
                        operatorLabel
                    }}</span>
                    <span
                        v-if="valueLabel"
                        class="max-w-48 truncate font-medium text-foreground"
                        >{{ valueLabel }}</span
                    >
                </button>
            </PopoverTrigger>
            <button
                type="button"
                class="flex items-center border-l px-1.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                aria-label="Remove filter"
                @click="emit('remove')"
            >
                <X class="size-3" />
            </button>
        </span>
        <PopoverContent class="w-auto p-0" align="start">
            <FilterValueEditor
                :clause="clause"
                :lookups="lookups"
                :workflow-statuses="workflowStatuses"
                @update:clause="emit('update:clause', $event)"
                @done="editing = false"
            />
        </PopoverContent>
    </Popover>
</template>
