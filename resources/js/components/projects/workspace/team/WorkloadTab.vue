<script lang="ts">
import type { UserSummary } from '@/types';

export type WorkloadRow = UserSummary & {
    role: string;
    open: number;
    started: number;
    points: number;
};
</script>

<script setup lang="ts">
import { computed } from 'vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import type { Team } from '@/types';

const props = defineProps<{
    team: Team;
    workload: WorkloadRow[] | null | undefined;
}>();

const max = computed(() =>
    Math.max(1, ...(props.workload ?? []).map((r) => r.open)),
);
</script>

<template>
    <div class="max-w-3xl">
        <p class="mb-3 text-[12.5px] text-muted-foreground">
            Open workstreams per person. Counted per team, so a shared issue
            appears once here per assignee.
        </p>
        <div
            v-if="workload === undefined || workload === null"
            class="space-y-2"
        >
            <div v-for="i in 4" :key="i" class="h-9 shimmer rounded-lg" />
        </div>
        <ul v-else class="space-y-px">
            <li
                v-for="row in workload"
                :key="row.id"
                class="flex h-10 items-center gap-3 rounded-lg px-2 text-[13px] hover:bg-accent/60"
            >
                <UserAvatar
                    :name="row.name"
                    :email="row.email"
                    :src="row.avatar"
                    :initials="row.initials"
                    size="sm"
                />
                <span class="w-40 truncate"
                    >{{ row.name
                    }}<span
                        v-if="row.role === 'lead'"
                        class="ml-1.5 rounded-full bg-brand-soft px-1.5 text-[10px] font-medium text-brand-soft-foreground"
                        >Lead</span
                    ></span
                >
                <span
                    class="flex h-2 flex-1 overflow-hidden rounded-full bg-muted"
                >
                    <span
                        class="h-full bg-status-started"
                        :style="{ width: `${(row.started / max) * 100}%` }"
                    />
                    <span
                        class="h-full bg-status-unstarted/60"
                        :style="{
                            width: `${((row.open - row.started) / max) * 100}%`,
                        }"
                    />
                </span>
                <span
                    class="w-20 text-right text-[11.5px] text-muted-foreground tabular-nums"
                    >{{ row.open }} open · {{ row.started }} active</span
                >
                <span
                    class="w-12 text-right text-[11.5px] text-muted-foreground tabular-nums"
                    >{{ row.points }} pt</span
                >
            </li>
        </ul>
    </div>
</template>
