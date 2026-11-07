<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Unlink } from '@lucide/vue';
import AvatarStack from '@/components/projects/shared/AvatarStack.vue';
import IssueIdentifier from '@/components/projects/shared/IssueIdentifier.vue';
import PriorityIcon from '@/components/projects/shared/PriorityIcon.vue';
import StatusIcon from '@/components/projects/shared/StatusIcon.vue';
import type { IssueListItem } from '@/types';

/**
 * A compact card for an issue linked to a conversation. Shows only what the server returned
 * for this viewer; the unlink button is offered when the caller allows it.
 */
withDefaults(
    defineProps<{
        issue: IssueListItem;
        orgSlug: string;
        canUnlink?: boolean;
    }>(),
    { canUnlink: false },
);

const emit = defineEmits<{ unlink: [identifier: string] }>();
</script>

<template>
    <div
        class="group relative rounded-lg border bg-card px-2.5 py-2 text-[12.5px] shadow-xs transition-colors hover:bg-accent/50"
    >
        <div class="flex items-start gap-2">
            <PriorityIcon
                :priority="issue.priority"
                :size="13"
                class="mt-0.5 shrink-0 text-muted-foreground"
            />
            <div class="min-w-0 flex-1">
                <Link
                    :href="`/${orgSlug}/issues/${issue.identifier}`"
                    class="flex items-baseline gap-1.5"
                >
                    <IssueIdentifier
                        :identifier="issue.identifier"
                        class="shrink-0"
                    />
                    <span class="truncate font-medium hover:underline">{{
                        issue.title
                    }}</span>
                </Link>
                <div
                    class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-muted-foreground"
                >
                    <span class="flex items-center gap-1">
                        <StatusIcon :status="issue.status" :size="11" />
                        {{ issue.status.name }}
                    </span>
                    <span
                        v-for="team in issue.teams"
                        :key="team.id"
                        class="rounded-full border px-1.5 leading-4"
                        :style="
                            team.color
                                ? { borderColor: team.color, color: team.color }
                                : undefined
                        "
                        >{{ team.name }}</span
                    >
                    <AvatarStack
                        v-if="issue.assignees.length"
                        :users="issue.assignees"
                        :max="3"
                        class="ml-auto"
                    />
                </div>
            </div>
            <button
                v-if="canUnlink"
                type="button"
                class="absolute top-1.5 right-1.5 rounded-md p-1 text-muted-foreground opacity-0 group-hover:opacity-100 hover:bg-accent hover:text-destructive focus-visible:opacity-100"
                :aria-label="`Unlink ${issue.identifier}`"
                @click.stop="emit('unlink', issue.identifier)"
            >
                <Unlink class="size-3.5" />
            </button>
        </div>
    </div>
</template>
