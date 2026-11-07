<script setup lang="ts">
import {
    ArrowDownUp,
    Calendar as CalendarIcon,
    GanttChart,
    LayoutList,
    SlidersHorizontal,
    SquareKanban,
} from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type {
    IssueColumn,
    IssueDisplay,
    IssueGroupBy,
    IssueSortField,
    IssueViewMode,
} from '@/types';

/**
 * The "Display" popover: view mode, grouping, ordering, completed issues and visible columns.
 */
const props = withDefaults(
    defineProps<{
        display: IssueDisplay;
        allowModes?: IssueViewMode[];
        focusTeamId?: number | null;
    }>(),
    {
        allowModes: () => ['list', 'board'],
        focusTeamId: null,
    },
);
const emit = defineEmits<{ 'update:display': [display: IssueDisplay] }>();

const modes: {
    value: IssueViewMode;
    label: string;
    icon: typeof LayoutList;
}[] = [
    { value: 'list', label: 'List', icon: LayoutList },
    { value: 'board', label: 'Board', icon: SquareKanban },
    { value: 'calendar', label: 'Calendar', icon: CalendarIcon },
    { value: 'timeline', label: 'Timeline', icon: GanttChart },
];

const groupOptions: { value: IssueGroupBy; label: string }[] = [
    { value: 'none', label: 'No grouping' },
    { value: 'status', label: 'Status' },
    { value: 'status_category', label: 'Status category' },
    { value: 'priority', label: 'Priority' },
    { value: 'assignee', label: 'Assignee' },
    { value: 'owner', label: 'Owner' },
    { value: 'team', label: 'Team' },
    { value: 'project', label: 'Project' },
    { value: 'product', label: 'Product' },
    { value: 'module', label: 'Module' },
    { value: 'label', label: 'Label' },
    { value: 'cycle', label: 'Cycle' },
    { value: 'milestone', label: 'Milestone' },
    { value: 'due_date', label: 'Due date' },
];

const sortOptions: { value: IssueSortField; label: string }[] = [
    { value: 'updated_at', label: 'Last updated' },
    { value: 'created_at', label: 'Created' },
    { value: 'last_activity_at', label: 'Last activity' },
    { value: 'priority', label: 'Priority' },
    { value: 'status', label: 'Status' },
    { value: 'due_date', label: 'Due date' },
    { value: 'identifier', label: 'Identifier' },
    { value: 'title', label: 'Title' },
    { value: 'estimate', label: 'Estimate' },
    { value: 'manual', label: 'Manual' },
];

const columnOptions: { value: IssueColumn; label: string }[] = [
    { value: 'priority', label: 'Priority' },
    { value: 'identifier', label: 'ID' },
    { value: 'status', label: 'Status' },
    { value: 'labels', label: 'Labels' },
    { value: 'project', label: 'Project' },
    { value: 'module', label: 'Module' },
    { value: 'product', label: 'Product' },
    { value: 'teams', label: 'Teams' },
    { value: 'due_date', label: 'Due date' },
    { value: 'assignees', label: 'Assignees' },
];

const visibleModes = computed(() =>
    modes.filter((m) => props.allowModes.includes(m.value)),
);
const columns = computed(
    () => props.display.columns ?? columnOptions.map((c) => c.value),
);

const patch = (changes: Partial<IssueDisplay>) =>
    emit('update:display', { ...props.display, ...changes });

const toggleColumn = (column: IssueColumn, on: boolean) => {
    const next = on
        ? [...columns.value, column]
        : columns.value.filter((c) => c !== column);
    patch({ columns: next.length === columnOptions.length ? null : next });
};
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <Button
                variant="outline"
                size="sm"
                class="h-7 gap-1.5 px-2 text-xs"
            >
                <SlidersHorizontal class="size-3.5" /> Display
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-72 space-y-3 p-3" align="end">
            <div v-if="visibleModes.length > 1">
                <ToggleGroup
                    type="single"
                    :model-value="display.mode"
                    class="w-full justify-stretch rounded-lg border p-0.5"
                    @update:model-value="
                        (v) => v && patch({ mode: v as IssueViewMode })
                    "
                >
                    <ToggleGroupItem
                        v-for="mode in visibleModes"
                        :key="mode.value"
                        :value="mode.value"
                        :aria-label="mode.label"
                        class="h-7 flex-1 gap-1.5 text-xs data-[state=on]:bg-accent"
                    >
                        <component :is="mode.icon" class="size-3.5" />
                        {{ mode.label }}
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>

            <label class="flex items-center justify-between gap-3 text-xs">
                <span class="text-muted-foreground">Group by</span>
                <NativeSelect
                    :model-value="display.group_by"
                    class="h-7 w-36 text-xs"
                    @change="
                        (e: Event) =>
                            patch({
                                group_by: (e.target as HTMLSelectElement)
                                    .value as IssueGroupBy,
                            })
                    "
                >
                    <NativeSelectOption
                        v-for="option in groupOptions"
                        :key="option.value"
                        :value="option.value"
                        >{{ option.label }}</NativeSelectOption
                    >
                </NativeSelect>
            </label>

            <div class="flex items-center justify-between gap-3 text-xs">
                <span class="text-muted-foreground">Order by</span>
                <span class="flex items-center gap-1">
                    <NativeSelect
                        :model-value="display.sort.field"
                        class="h-7 w-28 text-xs"
                        @change="
                            (e: Event) =>
                                patch({
                                    sort: {
                                        field: (e.target as HTMLSelectElement)
                                            .value as IssueSortField,
                                        dir: display.sort.dir,
                                    },
                                })
                        "
                    >
                        <NativeSelectOption
                            v-for="option in sortOptions"
                            :key="option.value"
                            :value="option.value"
                            >{{ option.label }}</NativeSelectOption
                        >
                    </NativeSelect>
                    <Button
                        variant="outline"
                        size="icon"
                        class="size-7"
                        :aria-label="
                            display.sort.dir === 'asc'
                                ? 'Ascending'
                                : 'Descending'
                        "
                        @click="
                            patch({
                                sort: {
                                    field: display.sort.field,
                                    dir:
                                        display.sort.dir === 'asc'
                                            ? 'desc'
                                            : 'asc',
                                },
                            })
                        "
                    >
                        <ArrowDownUp
                            class="size-3.5"
                            :class="display.sort.dir === 'asc' && 'rotate-180'"
                        />
                    </Button>
                </span>
            </div>

            <label class="flex items-center justify-between gap-3 text-xs">
                <span class="text-muted-foreground">Completed issues</span>
                <NativeSelect
                    :model-value="display.show_completed"
                    class="h-7 w-36 text-xs"
                    @change="
                        (e: Event) =>
                            patch({
                                show_completed: (e.target as HTMLSelectElement)
                                    .value as IssueDisplay['show_completed'],
                            })
                    "
                >
                    <NativeSelectOption value="recent"
                        >Last two weeks</NativeSelectOption
                    >
                    <NativeSelectOption value="all">All</NativeSelectOption>
                    <NativeSelectOption value="none">Hide</NativeSelectOption>
                </NativeSelect>
            </label>

            <div v-if="display.mode === 'list'">
                <p
                    class="mb-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    Columns
                </p>
                <div class="grid grid-cols-2 gap-x-3 gap-y-1.5">
                    <label
                        v-for="column in columnOptions"
                        :key="column.value"
                        class="flex items-center gap-2 text-xs"
                    >
                        <Checkbox
                            :model-value="columns.includes(column.value)"
                            class="size-3.5"
                            @update:model-value="
                                (on) => toggleColumn(column.value, Boolean(on))
                            "
                        />
                        {{ column.label }}
                    </label>
                </div>
            </div>
        </PopoverContent>
    </Popover>
</template>
