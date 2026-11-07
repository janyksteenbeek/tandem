<script setup lang="ts">
import { ref } from 'vue';
import IssueTitleInline from '@/components/projects/detail/IssueTitleInline.vue';
import { PriorityPicker, StatusPicker } from '@/components/projects/pickers';
import IssueIdentifier from '@/components/projects/shared/IssueIdentifier.vue';
import type { Issue, IssuePriority, WorkflowStatus } from '@/types';

defineProps<{ issue: Issue; statuses: WorkflowStatus[] }>();
const emit = defineEmits<{
    rename: [title: string];
    status: [status: WorkflowStatus];
    priority: [priority: IssuePriority];
    editing: [active: boolean];
}>();

const title = ref<InstanceType<typeof IssueTitleInline> | null>(null);

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
