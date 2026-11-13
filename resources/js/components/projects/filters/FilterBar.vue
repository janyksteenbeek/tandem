<script setup lang="ts">
import { Bookmark, ListFilter, Search, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FilterChip from '@/components/projects/filters/FilterChip.vue';
import { FIELD_DEFINITIONS } from '@/components/projects/filters/FilterValueEditor.vue';
import ListDisplayMenu from '@/components/projects/list/ListDisplayMenu.vue';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useKeyboard } from '@/composables/useKeyboard';
import { PRIORITY } from '@/lib/keyboard';
import { clauseCount, normalizeFilters } from '@/lib/projects/issueFilters';
import { cn } from '@/lib/utils';
import type {
    FilterClause,
    FilterField,
    IssueContainer,
    IssueDisplay,
    IssueFilters,
    IssueLookups,
    IssueViewMode,
    WorkflowStatus,
} from '@/types';

/**
 * Search, filter chips, match mode and the display menu. Filters are AND/OR groups; the bar
 * edits the first group (the common case) and shows additional groups as bracketed clusters.
 */
const props = withDefaults(
    defineProps<{
        filters: IssueFilters;
        display: IssueDisplay;
        lookups: IssueLookups | null;
        workflowStatuses: WorkflowStatus[];
        total?: number | null;
        allowModes?: IssueViewMode[];
        container: IssueContainer;
        focusTeamId?: number | null;
        canSave?: boolean;
    }>(),
    {
        total: null,
        allowModes: () => ['list', 'board'],
        focusTeamId: null,
        canSave: true,
    },
);

const emit = defineEmits<{
    'update:filters': [filters: IssueFilters];
    'update:display': [display: IssueDisplay];
    save: [];
}>();

const q = ref(props.filters.q ?? '');
const searchEl = ref<HTMLInputElement | null>(null);
const adding = ref(false);
const justAdded = ref<string | null>(null);

watch(
    () => props.filters.q,
    (value) => (q.value = value ?? ''),
);

let timer: ReturnType<typeof setTimeout> | null = null;

watch(q, (value) => {
    if (timer) {
        clearTimeout(timer);
    }

    timer = setTimeout(() => {
        if ((props.filters.q ?? '') !== value) {
            emit('update:filters', {
                ...normalizeFilters(props.filters),
                q: value || null,
            });
        }
    }, 300);
});

const normalized = computed(() => normalizeFilters(props.filters));
const count = computed(() => clauseCount(normalized.value));
/** Fields already used in a group can't be added twice through the quick menu. */
const availableFields = computed(() =>
    FIELD_DEFINITIONS.filter(
        (d) =>
            !normalized.value.groups.some((g) =>
                g.clauses.some((c) => c.field === d.field),
            ),
    ),
);

const defaultClause = (field: FilterField): FilterClause => {
    const def = FIELD_DEFINITIONS.find((d) => d.field === field);

    switch (def?.kind) {
        case 'boolean':
            return { field, op: 'is_true', value: null };
        case 'date':
            return { field, op: 'overdue', value: null };
        case 'text':
            return { field, op: 'contains', value: '' };
        case 'number':
            return { field, op: 'eq', value: '' };
        default:
            return { field, op: 'in', value: [] };
    }
};

const add = (field: FilterField) => {
    adding.value = false;
    const groups =
        normalized.value.groups.length > 0
            ? normalized.value.groups.map((g) => ({
                  ...g,
                  clauses: [...g.clauses],
              }))
            : [{ match: 'all' as const, clauses: [] as FilterClause[] }];
    groups[0].clauses.push(defaultClause(field));
    justAdded.value = `0:${groups[0].clauses.length - 1}`;
    emit('update:filters', { ...normalized.value, groups });
};

const updateClause = (
    groupIndex: number,
    clauseIndex: number,
    clause: FilterClause,
) => {
    const groups = normalized.value.groups.map((g, gi) =>
        gi === groupIndex
            ? {
                  ...g,
                  clauses: g.clauses.map((c, ci) =>
                      ci === clauseIndex ? clause : c,
                  ),
              }
            : g,
    );
    emit('update:filters', { ...normalized.value, groups });
};

const removeClause = (groupIndex: number, clauseIndex: number) => {
    const groups = normalized.value.groups
        .map((g, gi) =>
            gi === groupIndex
                ? {
                      ...g,
                      clauses: g.clauses.filter((_, ci) => ci !== clauseIndex),
                  }
                : g,
        )
        .filter((g) => g.clauses.length > 0);
    emit('update:filters', { ...normalized.value, groups });
};

const clearAll = () => {
    q.value = '';
    emit('update:filters', { q: null, match: 'all', groups: [] });
};

const toggleMatch = () =>
    emit('update:filters', {
        ...normalized.value,
        match: normalized.value.match === 'all' ? 'any' : 'all',
    });

const groupMatch = (index: number) =>
    emit('update:filters', {
        ...normalized.value,
        groups: normalized.value.groups.map((g, gi) =>
            gi === index
                ? { ...g, match: g.match === 'all' ? 'any' : 'all' }
                : g,
        ),
    });

useKeyboard(
    () => [
        {
            keys: '/',
            description: 'Search issues',
            group: 'List',
            handler: () => searchEl.value?.focus(),
        },
        {
            keys: 'f',
            description: 'Add filter',
            group: 'List',
            handler: () => (adding.value = true),
        },
    ],
    { scope: 'Filters', priority: PRIORITY.page },
);

defineExpose({ focusSearch: () => searchEl.value?.focus() });
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5 border-b px-3 py-1.5">
        <label class="relative flex h-7 items-center">
            <Search
                class="pointer-events-none absolute left-2 size-3.5 text-muted-foreground"
            />
            <input
                ref="searchEl"
                v-model="q"
                type="search"
                placeholder="Search…"
                aria-label="Search issues"
                class="h-7 w-44 rounded-md border border-transparent bg-transparent pr-6 pl-7 text-[12.5px] transition-[width,background-color] outline-none placeholder:text-muted-foreground/70 hover:bg-accent/60 focus:w-60 focus:border-border focus:bg-background"
                @keydown.escape="($event.target as HTMLInputElement).blur()"
            />
            <kbd
                v-if="!q"
                class="pointer-events-none absolute right-2 rounded border px-1 text-[10px] text-muted-foreground"
                >/</kbd
            >
        </label>

        <template v-for="(group, gi) in normalized.groups" :key="gi">
            <span
                v-if="normalized.groups.length > 1"
                class="flex items-center gap-1 rounded-lg border border-dashed px-1 py-0.5"
            >
                <button
                    type="button"
                    class="rounded px-1 text-[10.5px] font-semibold text-muted-foreground uppercase hover:bg-accent"
                    @click="groupMatch(gi)"
                >
                    {{ group.match === 'all' ? 'and' : 'or' }}
                </button>
                <FilterChip
                    v-for="(clause, ci) in group.clauses"
                    :key="`${gi}-${ci}-${clause.field}`"
                    :clause="clause"
                    :lookups="lookups"
                    :workflow-statuses="workflowStatuses"
                    :open="justAdded === `${gi}:${ci}`"
                    @update:clause="updateClause(gi, ci, $event)"
                    @remove="removeClause(gi, ci)"
                />
            </span>
            <template v-else>
                <FilterChip
                    v-for="(clause, ci) in group.clauses"
                    :key="`${gi}-${ci}-${clause.field}`"
                    :clause="clause"
                    :lookups="lookups"
                    :workflow-statuses="workflowStatuses"
                    :open="justAdded === `${gi}:${ci}`"
                    @update:clause="updateClause(gi, ci, $event)"
                    @remove="removeClause(gi, ci)"
                />
            </template>
        </template>

        <button
            v-if="normalized.groups.length > 1"
            type="button"
            class="h-7 rounded-md border px-2 text-[11px] text-muted-foreground hover:bg-accent"
            @click="toggleMatch"
        >
            Match {{ normalized.match === 'all' ? 'all groups' : 'any group' }}
        </button>

        <Popover v-model:open="adding">
            <PopoverTrigger as-child>
                <Button
                    variant="ghost"
                    size="sm"
                    :class="
                        cn(
                            'h-7 gap-1 px-2 text-[12px] text-muted-foreground',
                            count > 0 && 'text-foreground',
                        )
                    "
                >
                    <ListFilter class="size-3.5" />
                    {{ count === 0 ? 'Filter' : '' }}
                    <kbd
                        v-if="count === 0"
                        class="rounded border px-1 text-[10px]"
                        >F</kbd
                    >
                </Button>
            </PopoverTrigger>
            <PopoverContent class="w-56 p-0" align="start">
                <Command>
                    <CommandInput placeholder="Filter by…" auto-focus />
                    <CommandList class="max-h-72">
                        <CommandEmpty>No such field.</CommandEmpty>
                        <CommandGroup>
                            <CommandItem
                                v-for="def in availableFields"
                                :key="def.field"
                                :value="def.field"
                                @select="add(def.field)"
                                >{{ def.label }}</CommandItem
                            >
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>

        <Button
            v-if="count > 0 || q"
            variant="ghost"
            size="sm"
            class="h-7 gap-1 px-2 text-[12px] text-muted-foreground"
            @click="clearAll"
            ><X class="size-3.5" /> Clear</Button
        >

        <span class="ml-auto flex items-center gap-1.5">
            <span
                v-if="total !== null"
                class="text-[11.5px] text-muted-foreground tabular-nums"
                >{{ total }} issue{{ total === 1 ? '' : 's' }}</span
            >
            <Button
                v-if="canSave && (count > 0 || q)"
                variant="outline"
                size="sm"
                class="h-7 gap-1.5 px-2 text-xs"
                @click="emit('save')"
                ><Bookmark class="size-3.5" /> Save view</Button
            >
            <ListDisplayMenu
                :display="display"
                :allow-modes="allowModes"
                :focus-team-id="focusTeamId"
                @update:display="emit('update:display', $event)"
            />
        </span>
    </div>
</template>
