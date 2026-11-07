<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    Bell,
    BellOff,
    Copy,
    FolderInput,
    GitBranchPlus,
    Link as LinkIcon,
    MoreHorizontal,
    Trash2,
} from '@lucide/vue';
import { computed, inject, ref } from 'vue';
import { toast } from 'vue-sonner';
import IssueTitleInline from '@/components/projects/detail/IssueTitleInline.vue';
import { PriorityPicker, StatusPicker } from '@/components/projects/pickers';
import IssueIdentifier from '@/components/projects/shared/IssueIdentifier.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type {
    CreateIssuePrefill,
    Issue,
    IssuePriority,
    WorkflowStatus,
} from '@/types';

const props = defineProps<{ issue: Issue; statuses: WorkflowStatus[] }>();
const emit = defineEmits<{
    rename: [title: string];
    status: [status: WorkflowStatus];
    priority: [priority: IssuePriority];
    follow: [following: boolean];
    move: [];
    delete: [];
    editing: [active: boolean];
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const openCreateIssue = inject<(prefill?: CreateIssuePrefill) => void>(
    'openCreateIssue',
    () => {},
);
const title = ref<InstanceType<typeof IssueTitleInline> | null>(null);
const origin = computed(() =>
    typeof window !== 'undefined' ? window.location.origin : '',
);

const copy = async (text: string, label: string) => {
    await navigator.clipboard.writeText(text);
    toast.success(`${label} copied`);
};

const createSubIssue = () =>
    openCreateIssue({
        parent_identifier: props.issue.identifier,
        product_id: props.issue.product?.id ?? null,
        project_id: props.issue.project?.id ?? null,
        team_ids: props.issue.teams.map((t) => t.id),
    });

defineExpose({ editTitle: () => title.value?.start() });
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-start gap-3">
            <div class="min-w-0 flex-1">
                <IssueTitleInline
                    ref="title"
                    :title="issue.title"
                    :editable="issue.can.update"
                    @save="(t) => emit('rename', t)"
                    @editing="(a) => emit('editing', a)"
                />
            </div>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-7 shrink-0"
                        aria-label="More actions"
                    >
                        <MoreHorizontal class="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-56">
                    <DropdownMenuItem
                        class="gap-2"
                        @click="emit('follow', !issue.is_following)"
                    >
                        <BellOff v-if="issue.is_following" class="size-4" />
                        <Bell v-else class="size-4" />
                        {{ issue.is_following ? 'Unfollow' : 'Follow' }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        class="gap-2"
                        @click="copy(issue.identifier, 'ID')"
                    >
                        <Copy class="size-4" /> Copy ID
                        <DropdownMenuShortcut>⌘⇧,</DropdownMenuShortcut>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        class="gap-2"
                        @click="
                            copy(
                                `${origin}/${slug}/issues/${issue.identifier}`,
                                'Link',
                            )
                        "
                    >
                        <LinkIcon class="size-4" /> Copy link
                        <DropdownMenuShortcut>⌘.</DropdownMenuShortcut>
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem class="gap-2" @click="createSubIssue">
                        <GitBranchPlus class="size-4" /> Create sub-issue
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="issue.can.move"
                        class="gap-2"
                        @click="emit('move')"
                    >
                        <FolderInput class="size-4" /> Move to project…
                    </DropdownMenuItem>
                    <template v-if="issue.can.delete">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            class="gap-2 text-destructive focus:text-destructive"
                            @click="emit('delete')"
                        >
                            <Trash2 class="size-4" /> Delete issue
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <div class="flex flex-wrap items-center gap-1 lg:hidden">
            <IssueIdentifier :identifier="issue.identifier" class="mr-1" />
            <StatusPicker
                :status="issue.status"
                :options="statuses"
                :disabled="!issue.can.set_status"
                @change="(s) => emit('status', s)"
            />
            <PriorityPicker
                :priority="issue.priority"
                :disabled="!issue.can.update"
                @change="(p) => emit('priority', p)"
            />
        </div>
    </div>
</template>
