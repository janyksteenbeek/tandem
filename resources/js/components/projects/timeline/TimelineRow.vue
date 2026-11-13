<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ChevronRight,
    Diamond,
    FolderKanban,
    OctagonAlert,
    Rocket,
} from '@lucide/vue';
import ProgressRing from '@/components/projects/charts/ProgressRing.vue';
import IssueIdentifier from '@/components/projects/shared/IssueIdentifier.vue';
import StatusIcon from '@/components/projects/shared/StatusIcon.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import { cn } from '@/lib/utils';
import type { TimelineRow } from '@/lib/projects/timelineScale';

/** One line of the left pane: indentation, chevron, icon, label, progress. */
defineProps<{
    row: TimelineRow & { depth: number; hasChildren: boolean };
    collapsed: boolean;
}>();

const emit = defineEmits<{ toggle: [id: string]; open: [row: TimelineRow] }>();
</script>

<template>
    <div
        class="flex h-9 items-center gap-1.5 border-b border-border/60 pr-2 text-[12.5px]"
        :style="{ paddingLeft: `${8 + row.depth * 16}px` }"
    >
        <button
            v-if="row.hasChildren"
            type="button"
            class="flex size-5 shrink-0 items-center justify-center rounded text-muted-foreground hover:bg-accent hover:text-foreground"
            :aria-expanded="!collapsed"
            :aria-label="collapsed ? 'Expand' : 'Collapse'"
            @click="emit('toggle', row.id)"
        >
            <ChevronRight
                :class="
                    cn(
                        'size-3.5 transition-transform',
                        !collapsed && 'rotate-90',
                    )
                "
            />
        </button>
        <span v-else class="size-5 shrink-0" />

        <StatusIcon
            v-if="row.kind === 'issue' || row.kind === 'workstream'"
            :status="row.status ?? null"
            :size="13"
        />
        <Diamond
            v-else-if="row.kind === 'milestone'"
            class="size-3.5 shrink-0 text-brand"
        />
        <Rocket
            v-else-if="row.kind === 'release'"
            class="size-3.5 shrink-0 text-success"
        />
        <FolderKanban
            v-else-if="row.kind === 'project'"
            class="size-3.5 shrink-0 text-muted-foreground"
        />
        <InboxDot v-else :color="row.color ?? 'slate'" size="sm" />

        <IssueIdentifier v-if="row.identifier" :identifier="row.identifier" />
        <component
            :is="row.url ? Link : 'span'"
            :href="row.url"
            :class="
                cn(
                    'min-w-0 flex-1 truncate',
                    row.kind === 'project' && 'font-medium',
                    row.url && 'hover:underline',
                )
            "
            @click="row.url ? undefined : emit('open', row)"
            >{{ row.label }}</component
        >
        <OctagonAlert
            v-if="row.blocked"
            class="size-3 shrink-0 text-destructive"
            aria-label="Blocked"
        />
        <ProgressRing
            v-if="row.kind !== 'milestone'"
            :value="row.progress"
            :size="12"
            :tone="row.kind === 'release' ? 'success' : 'brand'"
        />
    </div>
</template>
