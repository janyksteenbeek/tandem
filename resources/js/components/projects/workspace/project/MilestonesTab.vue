<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Check, Flag, MoreHorizontal, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ProgressRing from '@/components/projects/charts/ProgressRing.vue';
import MilestoneDialog from '@/components/projects/dialogs/MilestoneDialog.vue';
import ConfirmDialog from '@/components/settings/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { api, reportError } from '@/lib/http';
import { cn } from '@/lib/utils';
import type { Milestone, Project } from '@/types';

const props = defineProps<{
    project: Project;
    milestones: Milestone[] | null | undefined;
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const base = computed(() => `/${slug.value}/projects/${props.project.slug}`);

const dialogOpen = ref(false);
const editing = ref<Milestone | null>(null);
const removing = ref<Milestone | null>(null);
const busy = ref(false);

const reload = () => router.reload({ only: ['milestones', 'project'] });

const complete = async (milestone: Milestone) => {
    try {
        await api.patch(`${base.value}/milestones/${milestone.uuid}`, {
            status: milestone.status === 'completed' ? 'active' : 'completed',
        });
        reload();
    } catch (error) {
        reportError(error);
    }
};

const confirmRemove = async () => {
    if (!removing.value) {
        return;
    }

    busy.value = true;

    try {
        await api.delete(`${base.value}/milestones/${removing.value.uuid}`);
        toast.success('Milestone deleted');
        removing.value = null;
        reload();
    } catch (error) {
        reportError(error);
    } finally {
        busy.value = false;
    }
};

const STATUS: Record<Milestone['status'], string> = {
    planned: 'text-muted-foreground',
    active: 'text-info',
    completed: 'text-success',
    missed: 'text-warning',
};
</script>

<template>
    <div class="max-w-3xl">
        <div class="mb-3 flex items-center justify-between">
            <p class="text-[12.5px] text-muted-foreground">
                Checkpoints on the way to done. Each issue can belong to one
                milestone.
            </p>
            <Button
                v-if="project.can.update"
                size="sm"
                class="gap-1.5"
                @click="
                    editing = null;
                    dialogOpen = true;
                "
                ><Plus class="size-3.5" /> New milestone</Button
            >
        </div>

        <div
            v-if="milestones === undefined || milestones === null"
            class="space-y-2"
        >
            <div v-for="i in 3" :key="i" class="h-10 shimmer rounded-lg" />
        </div>
        <div
            v-else-if="milestones.length === 0"
            class="flex flex-col items-center rounded-2xl border border-dashed bg-surface px-6 py-10 text-center"
        >
            <span
                class="mb-3 flex size-11 items-center justify-center rounded-2xl bg-brand-soft text-brand-soft-foreground"
                ><Flag class="size-5"
            /></span>
            <p class="font-display text-2xl">No milestones yet.</p>
            <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                Design sign-off, beta, GA — the moments that tell you where the
                project is.
            </p>
        </div>
        <ol v-else class="space-y-px">
            <li
                v-for="milestone in milestones"
                :key="milestone.uuid"
                class="group/ms flex h-11 items-center gap-3 rounded-lg px-2 hover:bg-accent/60"
            >
                <ProgressRing
                    :value="milestone.progress"
                    :size="18"
                    :tone="
                        milestone.status === 'completed'
                            ? 'success'
                            : milestone.status === 'missed'
                              ? 'warning'
                              : 'brand'
                    "
                />
                <span class="min-w-0 flex-1">
                    <span
                        :class="
                            cn(
                                'block truncate text-[13.5px] font-medium',
                                milestone.status === 'completed' &&
                                    'text-muted-foreground line-through',
                            )
                        "
                        >{{ milestone.name }}</span
                    >
                    <span
                        v-if="milestone.description"
                        class="block truncate text-[11.5px] text-muted-foreground"
                        >{{ milestone.description }}</span
                    >
                </span>
                <span
                    :class="
                        cn('text-[11.5px] capitalize', STATUS[milestone.status])
                    "
                    >{{ milestone.status }}</span
                >
                <span
                    class="w-24 text-right text-[11.5px] text-muted-foreground tabular-nums"
                    >{{ milestone.completed_issues_count }}/{{
                        milestone.issues_count
                    }}
                    · {{ milestone.target_date ?? '—' }}</span
                >
                <template v-if="project.can.update">
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-6 text-muted-foreground/0 group-hover/ms:text-muted-foreground"
                        :aria-label="
                            milestone.status === 'completed'
                                ? 'Reopen'
                                : 'Mark complete'
                        "
                        @click="complete(milestone)"
                        ><Check class="size-3.5"
                    /></Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child
                            ><Button
                                variant="ghost"
                                size="icon"
                                class="size-6 text-muted-foreground"
                                aria-label="Milestone actions"
                                ><MoreHorizontal class="size-3.5" /></Button
                        ></DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-40">
                            <DropdownMenuItem
                                class="gap-2"
                                @click="
                                    editing = milestone;
                                    dialogOpen = true;
                                "
                                ><Pencil class="size-3.5" />
                                Edit</DropdownMenuItem
                            >
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                class="gap-2 text-destructive"
                                @click="removing = milestone"
                                ><Trash2 class="size-3.5" />
                                Delete</DropdownMenuItem
                            >
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
            </li>
        </ol>

        <MilestoneDialog
            v-model:open="dialogOpen"
            :project="project"
            :milestone="editing"
            @saved="reload"
        />
        <ConfirmDialog
            :open="removing !== null"
            title="Delete this milestone?"
            description="Issues attached to it stay in the project."
            confirm-label="Delete milestone"
            destructive
            :busy="busy"
            @update:open="(v) => !v && (removing = null)"
            @confirm="confirmRemove"
        />
    </div>
</template>
