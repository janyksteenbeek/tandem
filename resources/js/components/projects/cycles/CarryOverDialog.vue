<script setup lang="ts">
import { ref } from 'vue';
import CompactIssueRow from '@/components/projects/workspace/CompactIssueRow.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Switch } from '@/components/ui/switch';
import type { CycleSummary, IssueListItem } from '@/types';

/** Completing a cycle: what is still open and where it goes. */
defineProps<{
    cycle: CycleSummary;
    open_issues: IssueListItem[];
    teamId: number;
    defaultCarryOver: boolean;
    busy?: boolean;
}>();

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ confirm: [carryOver: boolean] }>();
const carryOver = ref(true);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-lg">
            <DialogHeader>
                <DialogTitle class="font-display text-2xl"
                    >Complete {{ cycle.name }}</DialogTitle
                >
                <DialogDescription>
                    <template v-if="open_issues.length === 0"
                        >Everything in this cycle is finished. Nice.</template
                    >
                    <template v-else
                        >{{ open_issues.length }} item{{
                            open_issues.length === 1 ? ' is' : 's are'
                        }}
                        still open.</template
                    >
                </DialogDescription>
            </DialogHeader>
            <div v-if="open_issues.length > 0" class="space-y-3">
                <div
                    class="scrollbar-thin max-h-60 space-y-px overflow-y-auto rounded-lg border px-2 py-1"
                >
                    <CompactIssueRow
                        v-for="issue in open_issues"
                        :key="issue.uuid"
                        :issue="issue"
                        :focus-team-id="teamId"
                        :show-teams="false"
                    />
                </div>
                <label
                    class="flex items-center justify-between gap-3 rounded-lg border bg-surface px-3 py-2 text-[12.5px]"
                >
                    <span>
                        <span class="block font-medium"
                            >Move unfinished work to the next cycle</span
                        >
                        <span class="block text-muted-foreground"
                            >Off: it returns to the backlog instead.</span
                        >
                    </span>
                    <Switch v-model="carryOver" />
                </label>
            </div>
            <DialogFooter class="gap-2">
                <Button variant="ghost" :disabled="busy" @click="open = false"
                    >Cancel</Button
                >
                <Button
                    :disabled="busy"
                    @click="
                        emit('confirm', open_issues.length > 0 && carryOver)
                    "
                    >Complete cycle</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
