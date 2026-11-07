<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProgressRing from '@/components/projects/charts/ProgressRing.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import { Kbd } from '@/components/ui/kbd';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { PROJECT_HEALTH_META, PROJECT_STATUS_META } from '@/lib/projects/meta';
import { cn } from '@/lib/utils';
import type { ProjectSummary } from '@/types';

/** The project list rows shared by the projects index and the product's Projects tab. */
withDefaults(
    defineProps<{
        projects: ProjectSummary[];
        showProduct?: boolean;
        emptyText?: string;
    }>(),
    { showProduct: true, emptyText: 'No projects here.' },
);

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');

const target = (date: string | null) => {
    if (!date) {
        return null;
    }

    const d = new Date(date + 'T00:00:00');

    return d.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        ...(d.getFullYear() !== new Date().getFullYear()
            ? { year: 'numeric' }
            : {}),
    });
};

const overdue = (project: ProjectSummary) =>
    project.target_date !== null &&
    project.target_date < new Date().toISOString().slice(0, 10) &&
    !['completed', 'canceled'].includes(project.status);
</script>

<template>
    <p
        v-if="projects.length === 0"
        class="rounded-lg border border-dashed px-3 py-6 text-center text-[12.5px] text-muted-foreground"
    >
        {{ emptyText }}
    </p>
    <ul v-else class="space-y-px">
        <li v-for="project in projects" :key="project.uuid">
            <Link
                :href="`/${slug}/projects/${project.slug}`"
                class="-mx-2 issue-row h-10"
            >
                <InboxDot :color="project.color" size="md" />
                <span
                    class="min-w-0 flex-1 truncate text-[13.5px] font-medium"
                    >{{ project.name }}</span
                >
                <Kbd
                    v-if="showProduct && project.product"
                    class="hidden h-5 px-1.5 font-mono text-[10px] sm:inline-flex"
                    >{{ project.product.key }}</Kbd
                >
                <Tooltip>
                    <TooltipTrigger as-child>
                        <span
                            class="hidden items-center gap-1.5 text-[11.5px] text-muted-foreground md:flex"
                            ><span
                                :class="
                                    cn(
                                        'size-1.5 rounded-full',
                                        PROJECT_STATUS_META[project.status].dot,
                                    )
                                "
                            />{{
                                PROJECT_STATUS_META[project.status].label
                            }}</span
                        >
                    </TooltipTrigger>
                    <TooltipContent
                        >Health:
                        {{
                            PROJECT_HEALTH_META[project.health].label
                        }}</TooltipContent
                    >
                </Tooltip>
                <span
                    v-if="project.health !== 'unknown'"
                    :class="
                        cn(
                            'hidden text-[11.5px] lg:block',
                            PROJECT_HEALTH_META[project.health].className,
                        )
                    "
                    >{{ PROJECT_HEALTH_META[project.health].label }}</span
                >
                <span
                    v-if="project.target_date"
                    :class="
                        cn(
                            'w-16 text-right text-[11.5px] tabular-nums',
                            overdue(project)
                                ? 'font-medium text-destructive'
                                : 'text-muted-foreground',
                        )
                    "
                    >{{ target(project.target_date) }}</span
                >
                <span
                    class="flex w-14 items-center justify-end gap-1.5 text-[11.5px] text-muted-foreground tabular-nums"
                    ><ProgressRing :value="project.progress" :size="14" />{{
                        Math.round(project.progress * 100)
                    }}%</span
                >
                <span class="hidden items-center -space-x-0.5 sm:flex"
                    ><InboxDot
                        v-for="team in project.teams.slice(0, 4)"
                        :key="team.id"
                        :color="team.color"
                        size="sm"
                /></span>
                <UserAvatar
                    v-if="project.lead"
                    :name="project.lead.name"
                    :email="project.lead.email"
                    :src="project.lead.avatar"
                    :initials="project.lead.initials"
                    size="xs"
                />
                <span v-else class="size-5" />
            </Link>
        </li>
    </ul>
</template>
