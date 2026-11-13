<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import WorkflowStatusRow from '@/components/projects/workflow/WorkflowStatusRow.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import { api, reportError } from '@/lib/http';
import { CATEGORY_META, CATEGORY_ORDER } from '@/lib/projects/meta';
import type { StatusCategory, Workflow, WorkflowStatus } from '@/types';

/**
 * Edits one workflow's statuses per category: rename, recolour, reorder, default, add, delete
 * (with remapping). Every change round-trips to the server and emits the returned workflow.
 */
const props = defineProps<{ workflow: Workflow; canManage: boolean }>();
const emit = defineEmits<{ 'update:workflow': [workflow: Workflow] }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const base = computed(() => `/${slug.value}/workflows/${props.workflow.uuid}`);

const byCategory = computed(() =>
    CATEGORY_ORDER.map((category) => ({
        category,
        meta: CATEGORY_META[category],
        statuses: [...props.workflow.statuses]
            .filter((s) => s.category === category)
            .sort((a, b) => a.position - b.position),
    })),
);

const required: StatusCategory[] = [
    'unstarted',
    'started',
    'completed',
    'canceled',
];
const canDelete = (status: WorkflowStatus) =>
    props.workflow.statuses.length > 1 &&
    (!required.includes(status.category) ||
        props.workflow.statuses.filter((s) => s.category === status.category)
            .length > 1);

const busy = ref(false);

const call = async (
    request: () => Promise<{ workflow: Workflow; remapped?: number }>,
    success?: string,
) => {
    busy.value = true;

    try {
        const data = await request();
        emit('update:workflow', data.workflow);

        if (success) {
            toast.success(success);
        }

        return data;
    } catch (error) {
        reportError(error);

        return null;
    } finally {
        busy.value = false;
    }
};

const rename = (status: WorkflowStatus, name: string) =>
    call(() => api.patch(`${base.value}/statuses/${status.uuid}`, { name }));
const recolor = (status: WorkflowStatus, color: string) =>
    call(() => api.patch(`${base.value}/statuses/${status.uuid}`, { color }));
const makeDefault = (status: WorkflowStatus) =>
    call(() => api.post(`${base.value}/statuses/${status.uuid}/default`));

const move = (status: WorkflowStatus, direction: -1 | 1) => {
    const ordered = [...props.workflow.statuses].sort(
        (a, b) => a.position - b.position,
    );
    const index = ordered.findIndex((s) => s.id === status.id);
    const siblings = ordered.filter((s) => s.category === status.category);
    const siblingIndex = siblings.findIndex((s) => s.id === status.id);
    const target = siblings[siblingIndex + direction];

    if (!target || index === -1) {
        return;
    }

    const targetIndex = ordered.findIndex((s) => s.id === target.id);
    ordered.splice(index, 1);
    ordered.splice(targetIndex, 0, status);

    return call(() =>
        api.post(`${base.value}/statuses/reorder`, {
            ids: ordered.map((s) => s.id),
        }),
    );
};

const adding = ref<StatusCategory | null>(null);
const newName = ref('');

const add = async () => {
    if (!adding.value || !newName.value.trim()) {
        return;
    }

    const category = adding.value;
    const name = newName.value.trim();
    adding.value = null;
    newName.value = '';
    await call(
        () => api.post(`${base.value}/statuses`, { name, category }),
        `Added ${name}`,
    );
};

const removing = ref<WorkflowStatus | null>(null);
const replacement = ref<number | null>(null);

watch(removing, (status) => {
    replacement.value = status
        ? ((
              props.workflow.statuses.find(
                  (s) => s.id !== status.id && s.category === status.category,
              ) ?? props.workflow.statuses.find((s) => s.id !== status.id)
          )?.id ?? null)
        : null;
});

const confirmRemove = async () => {
    if (!removing.value || !replacement.value) {
        return;
    }

    const status = removing.value;
    const data = await call(() =>
        api.delete(`${base.value}/statuses/${status.uuid}`, {
            replacement_status_id: replacement.value,
        }),
    );

    if (data) {
        toast.success(
            data.remapped
                ? `Deleted ${status.name} and remapped ${data.remapped} item${data.remapped === 1 ? '' : 's'}`
                : `Deleted ${status.name}`,
        );
    }

    removing.value = null;
};

const updateFlag = (flag: 'auto_start' | 'auto_complete', value: boolean) =>
    call(() => api.patch(base.value, { [flag]: value }));

const lists = ref<Record<string, HTMLElement | null>>({});

onMounted(async () => {
    if (!props.canManage) {
        return;
    }

    const { useSortable } = await import('@vueuse/integrations/useSortable');

    for (const category of CATEGORY_ORDER) {
        const el = lists.value[category];

        if (!el) {
            continue;
        }

        useSortable(el, [], {
            handle: '.drag-handle',
            animation: 150,
            onEnd: (event) => {
                const { oldIndex, newIndex } = event;

                if (
                    oldIndex === undefined ||
                    newIndex === undefined ||
                    oldIndex === newIndex
                ) {
                    return;
                }

                const ordered = [...props.workflow.statuses].sort(
                    (a, b) => a.position - b.position,
                );
                const siblings = ordered.filter((s) => s.category === category);
                const moved = siblings[oldIndex];
                const target = siblings[newIndex];

                if (!moved || !target) {
                    return;
                }

                const from = ordered.findIndex((s) => s.id === moved.id);
                ordered.splice(from, 1);
                const to = ordered.findIndex((s) => s.id === target.id);
                ordered.splice(newIndex > oldIndex ? to + 1 : to, 0, moved);
                void call(() =>
                    api.post(`${base.value}/statuses/reorder`, {
                        ids: ordered.map((s) => s.id),
                    }),
                );
            },
        });
    }
});
</script>

<template>
    <div class="space-y-5">
        <div
            v-if="workflow.kind === 'issue'"
            class="flex flex-wrap gap-x-6 gap-y-2 rounded-lg border bg-surface px-4 py-3"
        >
            <label class="flex items-center gap-2 text-[13px]">
                <Switch
                    :model-value="workflow.auto_start"
                    :disabled="!canManage || busy"
                    @update:model-value="(v) => updateFlag('auto_start', v)"
                />
                <span>Start the issue when any team starts</span>
            </label>
            <label class="flex items-center gap-2 text-[13px]">
                <Switch
                    :model-value="workflow.auto_complete"
                    :disabled="!canManage || busy"
                    @update:model-value="(v) => updateFlag('auto_complete', v)"
                />
                <span
                    >Complete the issue when every required team finishes</span
                >
            </label>
        </div>

        <div
            v-for="group in byCategory"
            :key="group.category"
            class="space-y-1"
        >
            <div class="flex items-center justify-between px-2">
                <p
                    class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ group.meta.label }}
                </p>
                <Button
                    v-if="canManage"
                    variant="ghost"
                    size="sm"
                    class="h-6 gap-1 px-1.5 text-[11.5px] text-muted-foreground"
                    @click="
                        adding = group.category;
                        newName = '';
                    "
                >
                    <Plus class="size-3" /> Add
                </Button>
            </div>
            <div
                :ref="
                    (el) => (lists[group.category] = el as HTMLElement | null)
                "
                class="space-y-px"
            >
                <WorkflowStatusRow
                    v-for="(status, index) in group.statuses"
                    :key="status.id"
                    :status="status"
                    :can-manage="canManage"
                    :can-delete="canDelete(status)"
                    :is-first="index === 0"
                    :is-last="index === group.statuses.length - 1"
                    @rename="(name) => rename(status, name)"
                    @recolor="(color) => recolor(status, color)"
                    @make-default="makeDefault(status)"
                    @move="(direction) => move(status, direction)"
                    @remove="removing = status"
                />
                <p
                    v-if="group.statuses.length === 0"
                    class="px-2 py-1.5 text-[12px] text-muted-foreground/70"
                >
                    No {{ group.meta.label.toLowerCase() }} status.
                </p>
            </div>
            <form
                v-if="adding === group.category"
                class="flex items-center gap-2 px-2 pt-1"
                @submit.prevent="add"
            >
                <Input
                    v-model="newName"
                    v-focus
                    placeholder="Status name"
                    class="h-8 max-w-xs text-[13px]"
                    @keydown.escape="adding = null"
                />
                <Button
                    type="submit"
                    size="sm"
                    class="h-8"
                    :disabled="!newName.trim() || busy"
                    >Add</Button
                >
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    class="h-8"
                    @click="adding = null"
                    >Cancel</Button
                >
            </form>
        </div>

        <Dialog
            :open="removing !== null"
            @update:open="(v) => !v && (removing = null)"
        >
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle class="font-display text-2xl"
                        >Delete {{ removing?.name }}?</DialogTitle
                    >
                    <DialogDescription
                        >Everything currently in this status moves to the status
                        you pick. Nothing is lost.</DialogDescription
                    >
                </DialogHeader>
                <label class="block space-y-1.5 text-sm">
                    <span class="text-muted-foreground">Move items to</span>
                    <NativeSelect v-model="replacement" class="w-full">
                        <NativeSelectOption
                            v-for="s in workflow.statuses.filter(
                                (s) => s.id !== removing?.id,
                            )"
                            :key="s.id"
                            :value="s.id"
                            >{{ s.name }} ·
                            {{
                                CATEGORY_META[s.category].label
                            }}</NativeSelectOption
                        >
                    </NativeSelect>
                </label>
                <DialogFooter class="gap-2">
                    <Button variant="ghost" @click="removing = null"
                        >Cancel</Button
                    >
                    <Button
                        variant="destructive"
                        :disabled="!replacement || busy"
                        @click="confirmRemove"
                        >Delete status</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
