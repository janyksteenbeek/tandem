<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { MoreHorizontal } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import WorkspaceHeader from '@/components/projects/workspace/WorkspaceHeader.vue';
import ProgressRing from '@/components/projects/charts/ProgressRing.vue';
import ConfirmDialog from '@/components/projects/dialogs/ConfirmDialog.vue';
import InitiativeDialog from '@/components/projects/dialogs/InitiativeDialog.vue';
import type { Initiative } from '@/components/projects/dialogs/InitiativeDialog.vue';
import { INITIATIVE_STATUS } from '@/components/projects/dialogs/InitiativeDialog.vue';
import OverviewSection from '@/components/projects/workspace/OverviewSection.vue';
import ProjectRows from '@/components/projects/workspace/ProjectRows.vue';
import StatPill from '@/components/projects/workspace/StatPill.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useIssueLookups } from '@/composables/projects/useIssueLookups';
import { api, reportError } from '@/lib/http';
import { cn } from '@/lib/utils';
import type { IssueLookups } from '@/types';

const props = defineProps<{ initiative: Initiative; lookups: IssueLookups }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const { lookups } = useIssueLookups(() => props.lookups);

const initiative = ref<Initiative>(props.initiative);
watch(
    () => props.initiative,
    (value) => (initiative.value = value),
);

const editOpen = ref(false);
const deleteOpen = ref(false);
const busy = ref(false);

const date = (iso: string | null) =>
    iso
        ? new Date(iso + 'T00:00:00').toLocaleDateString(undefined, {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
          })
        : null;

const progress = computed(() =>
    initiative.value.progress.total > 0
        ? initiative.value.progress.completed / initiative.value.progress.total
        : 0,
);

const destroy = async () => {
    busy.value = true;

    try {
        await api.delete(`/${slug.value}/initiatives/${initiative.value.slug}`);
        toast.success('Initiative deleted');
        router.visit(`/${slug.value}/initiatives`);
    } catch (error) {
        reportError(error);
        busy.value = false;
    }
};

const onSaved = (saved: Initiative) => {
    initiative.value = { ...initiative.value, ...saved };
    router.reload({ only: ['initiative'] });
};

const MILESTONE_DOT: Record<string, string> = {
    planned: 'bg-info',
    active: 'bg-warning',
    completed: 'bg-brand',
    missed: 'bg-destructive/70',
};
</script>

<template>
    <Head :title="initiative.name" />

    <div class="flex h-full min-h-0 flex-col">
        <WorkspaceHeader
            :breadcrumbs="[
                { label: 'Initiatives', href: `/${slug}/initiatives` },
                { label: initiative.name },
            ]"
            :title="initiative.name"
            :color="initiative.color"
        >
            <template #meta>
                <span
                    class="flex items-center gap-1.5 text-[12px] text-muted-foreground"
                >
                    <span
                        :class="
                            cn(
                                'size-1.5 rounded-full',
                                INITIATIVE_STATUS[initiative.status].dot,
                            )
                        "
                    />{{ INITIATIVE_STATUS[initiative.status].label }}
                </span>
                <span
                    class="flex items-center gap-1.5 text-[12px] text-muted-foreground"
                    ><ProgressRing :value="progress" :size="14" />{{
                        Math.round(progress * 100)
                    }}%</span
                >
                <span
                    v-if="initiative.owner"
                    class="flex items-center gap-1.5 text-[12px] text-muted-foreground"
                >
                    <UserAvatar
                        :name="initiative.owner.name"
                        :email="initiative.owner.email"
                        :src="initiative.owner.avatar"
                        :initials="initiative.owner.initials"
                        size="xs"
                    />{{ initiative.owner.name }}
                </span>
                <span
                    v-if="initiative.target_date"
                    class="text-[12px] text-muted-foreground"
                    >Target {{ date(initiative.target_date) }}</span
                >
                <DropdownMenu v-if="initiative.can?.update">
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-8"
                            aria-label="Initiative actions"
                            ><MoreHorizontal class="size-4"
                        /></Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-44">
                        <DropdownMenuItem @click="editOpen = true"
                            >Edit initiative</DropdownMenuItem
                        >
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            v-if="initiative.can?.delete"
                            class="text-destructive"
                            @click="deleteOpen = true"
                            >Delete</DropdownMenuItem
                        >
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
        </WorkspaceHeader>

        <div
            class="scrollbar-thin min-h-0 flex-1 overflow-y-auto px-4 py-6 sm:px-6"
        >
            <div
                class="mx-auto grid max-w-5xl gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]"
            >
                <div class="space-y-8">
                    <section>
                        <div
                            v-if="initiative.description_html"
                            class="prose-tandem text-[14px]"
                            v-html="initiative.description_html"
                        />
                        <button
                            v-else-if="initiative.can?.update"
                            type="button"
                            class="text-[13px] text-muted-foreground hover:text-foreground"
                            @click="editOpen = true"
                        >
                            What is this initiative trying to achieve?
                            <span class="text-brand">Add a description</span>
                        </button>
                        <p v-else class="text-[13px] text-muted-foreground">
                            No description.
                        </p>
                    </section>

                    <OverviewSection
                        label="Progress"
                        :hint="`${initiative.progress.completed} of ${initiative.progress.total} issues`"
                    >
                        <div class="space-y-2">
                            <div
                                class="h-2 w-full overflow-hidden rounded-full bg-muted"
                                role="img"
                                :aria-label="`${Math.round(progress * 100)}% complete`"
                            >
                                <div
                                    class="h-full rounded-full bg-brand transition-[width] duration-300"
                                    :style="{ width: `${progress * 100}%` }"
                                />
                            </div>
                            <StatPill
                                :items="[
                                    {
                                        label: 'projects',
                                        value: initiative.progress.projects,
                                    },
                                    {
                                        label: 'active',
                                        value: initiative.progress.active,
                                        tone: 'warning',
                                    },
                                    {
                                        label: 'issues done',
                                        value: initiative.progress.completed,
                                        tone: 'success',
                                    },
                                    {
                                        label: 'issues total',
                                        value: initiative.progress.total,
                                    },
                                ]"
                            />
                        </div>
                    </OverviewSection>

                    <OverviewSection
                        label="Projects"
                        :hint="`${initiative.projects.length}`"
                    >
                        <template v-if="initiative.can?.update" #action>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="h-6 px-2 text-[11.5px]"
                                @click="editOpen = true"
                                >Manage</Button
                            >
                        </template>
                        <ProjectRows
                            :projects="initiative.projects"
                            empty-text="No projects in this initiative yet."
                        />
                    </OverviewSection>
                </div>

                <div class="space-y-8">
                    <OverviewSection
                        label="Milestones"
                        :hint="
                            initiative.milestones?.length
                                ? `${initiative.milestones.length}`
                                : null
                        "
                    >
                        <p
                            v-if="
                                !initiative.milestones ||
                                initiative.milestones.length === 0
                            "
                            class="text-[12.5px] text-muted-foreground"
                        >
                            No initiative milestones.
                        </p>
                        <ul v-else class="space-y-1.5">
                            <li
                                v-for="milestone in initiative.milestones"
                                :key="milestone.uuid"
                                class="flex items-center gap-2 text-[12.5px]"
                            >
                                <span
                                    :class="
                                        cn(
                                            'size-1.5 rounded-full',
                                            MILESTONE_DOT[milestone.status],
                                        )
                                    "
                                />
                                <span class="min-w-0 flex-1 truncate">{{
                                    milestone.name
                                }}</span>
                                <span
                                    v-if="milestone.target_date"
                                    class="text-[11px] text-muted-foreground tabular-nums"
                                    >{{ date(milestone.target_date) }}</span
                                >
                            </li>
                        </ul>
                    </OverviewSection>
                    <OverviewSection label="Timeline">
                        <dl class="space-y-1.5 text-[12.5px]">
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">Start</dt>
                                <dd>
                                    {{ date(initiative.start_date) ?? '—' }}
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">Target</dt>
                                <dd>
                                    {{ date(initiative.target_date) ?? '—' }}
                                </dd>
                            </div>
                        </dl>
                    </OverviewSection>
                </div>
            </div>
        </div>

        <InitiativeDialog
            v-model:open="editOpen"
            :lookups="lookups"
            :initiative="initiative"
            @saved="onSaved"
        />
        <ConfirmDialog
            v-model:open="deleteOpen"
            title="Delete this initiative?"
            description="Projects keep existing; only the grouping disappears."
            confirm-label="Delete initiative"
            destructive
            :busy="busy"
            @confirm="destroy"
        />
    </div>
</template>
