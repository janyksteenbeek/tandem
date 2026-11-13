<script setup lang="ts">
import { FolderKanban, Trash2, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    EntityPicker,
    LabelPicker,
    MemberPicker,
    PriorityPicker,
    StatusPicker,
} from '@/components/projects/pickers';
import { Button } from '@/components/ui/button';
import type { IssueLookups, UserSummary, WorkflowStatus } from '@/types';

/**
 * Appears above the list while rows are selected. Each control applies one change to every
 * selected issue through the bulk endpoint.
 */
const props = defineProps<{
    count: number;
    lookups: IssueLookups | null;
    workflow: WorkflowStatus[];
}>();
const emit = defineEmits<{
    clear: [];
    action: [action: string, params: Record<string, unknown>];
}>();

const owner = ref<UserSummary[]>([]);
const projects = computed(() =>
    (props.lookups?.projects ?? []).map((p) => ({
        id: p.id,
        name: p.name,
        color: p.color,
    })),
);

const remove = () => {
    if (
        window.confirm(
            `Delete ${props.count} issue${props.count === 1 ? '' : 's'}?`,
        )
    ) {
        emit('action', 'delete', {});
    }
};
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-1 border-b border-brand/20 bg-brand-soft/60 px-3 py-1.5 text-xs"
    >
        <span class="mr-1 font-medium text-brand-soft-foreground tabular-nums"
            >{{ count }} selected</span
        >
        <StatusPicker
            :status="null"
            :options="workflow"
            @change="(s) => emit('action', 'status', { status_id: s.id })"
        >
            <template #trigger>
                <Button variant="ghost" size="sm" class="h-7 gap-1 px-2"
                    >Status</Button
                >
            </template>
        </StatusPicker>
        <PriorityPicker
            priority="none"
            @change="(p) => emit('action', 'priority', { priority: p })"
        >
            <template #trigger>
                <Button variant="ghost" size="sm" class="h-7 gap-1 px-2"
                    >Priority</Button
                >
            </template>
        </PriorityPicker>
        <MemberPicker
            :selected="owner"
            :members="lookups?.members ?? []"
            placeholder="Owner…"
            @change="
                (users) => {
                    owner = users;
                    emit('action', 'owner', { owner_id: users[0]?.id ?? null });
                }
            "
        >
            <template #trigger>
                <Button variant="ghost" size="sm" class="h-7 gap-1 px-2"
                    >Owner</Button
                >
            </template>
        </MemberPicker>
        <LabelPicker
            :selected="[]"
            :available="lookups?.labels ?? []"
            :can-create="false"
            @toggle="
                (label, attached) =>
                    label &&
                    emit('action', 'label', { label_id: label.id, attached })
            "
        >
            <template #trigger>
                <Button variant="ghost" size="sm" class="h-7 gap-1 px-2"
                    >Label</Button
                >
            </template>
        </LabelPicker>
        <EntityPicker
            :selected="[]"
            :options="projects"
            :icon="FolderKanban"
            placeholder="Move to project…"
            empty-label="No project"
            @change="
                (list) =>
                    emit('action', 'project', {
                        project_id: list[0]?.id ?? null,
                    })
            "
        >
            <template #trigger>
                <Button variant="ghost" size="sm" class="h-7 gap-1 px-2"
                    >Project</Button
                >
            </template>
        </EntityPicker>
        <Button
            variant="ghost"
            size="sm"
            class="h-7 gap-1 px-2 text-destructive hover:text-destructive"
            @click="remove"
            ><Trash2 class="size-3.5" /> Delete</Button
        >
        <Button
            variant="ghost"
            size="icon"
            class="ml-auto size-7"
            aria-label="Clear selection"
            @click="emit('clear')"
            ><X class="size-3.5"
        /></Button>
    </div>
</template>
