<script setup lang="ts">
import { Plus, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import IssueIdentifier from '@/components/projects/shared/IssueIdentifier.vue';
import PriorityIcon from '@/components/projects/shared/PriorityIcon.vue';
import StatusIcon from '@/components/projects/shared/StatusIcon.vue';
import AvatarStack from '@/components/projects/shared/AvatarStack.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { IssueListItem } from '@/types';

/** The team's backlog (workstreams without a cycle) with a quick "add" per row. */
const props = defineProps<{
    backlog: IssueListItem[] | null | undefined;
    teamName: string;
    adding: Set<string>;
}>();

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ add: [issue: IssueListItem] }>();

const query = ref('');
const rows = computed(() => {
    const term = query.value.trim().toLowerCase();
    const list = props.backlog ?? [];

    return term
        ? list.filter((i) =>
              `${i.identifier} ${i.title}`.toLowerCase().includes(term),
          )
        : list;
});
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            side="right"
            class="flex w-full flex-col gap-0 p-0 sm:max-w-lg"
        >
            <SheetHeader class="border-b px-4 py-3">
                <SheetTitle class="font-display text-xl"
                    >Add to cycle</SheetTitle
                >
                <SheetDescription
                    >Open {{ teamName }} work that isn't planned
                    yet.</SheetDescription
                >
                <div class="relative mt-2">
                    <Search
                        class="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="query"
                        placeholder="Filter backlog…"
                        class="h-8 pl-8 text-[13px]"
                    />
                </div>
            </SheetHeader>
            <div
                class="scrollbar-thin min-h-0 flex-1 overflow-y-auto px-2 py-2"
            >
                <div
                    v-if="backlog === undefined || backlog === null"
                    class="space-y-1 px-2"
                >
                    <div
                        v-for="i in 6"
                        :key="i"
                        class="h-9 shimmer rounded-lg"
                    />
                </div>
                <p
                    v-else-if="rows.length === 0"
                    class="px-3 py-8 text-center text-[12.5px] text-muted-foreground"
                >
                    {{
                        query
                            ? 'Nothing matches.'
                            : 'The backlog is empty — everything is planned.'
                    }}
                </p>
                <div v-else class="space-y-px">
                    <div
                        v-for="issue in rows"
                        :key="issue.uuid"
                        class="issue-row"
                    >
                        <PriorityIcon :priority="issue.priority" :size="13" />
                        <IssueIdentifier :identifier="issue.identifier" />
                        <StatusIcon
                            :status="issue.workstream?.status ?? issue.status"
                            :size="13"
                        />
                        <span class="min-w-0 flex-1 truncate">{{
                            issue.title
                        }}</span>
                        <AvatarStack
                            v-if="issue.assignees.length"
                            :users="issue.assignees"
                            :max="2"
                        />
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-6 gap-1 px-2 text-[11px]"
                            :disabled="adding.has(issue.uuid)"
                            @click="emit('add', issue)"
                            ><Plus class="size-3" /> Add</Button
                        >
                    </div>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
