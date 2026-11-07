<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Compass, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import WorkspaceHeader from '@/components/projects/workspace/WorkspaceHeader.vue';
import ProgressRing from '@/components/projects/charts/ProgressRing.vue';
import InitiativeDialog from '@/components/projects/dialogs/InitiativeDialog.vue';
import type { Initiative } from '@/components/projects/dialogs/InitiativeDialog.vue';
import { INITIATIVE_STATUS } from '@/components/projects/dialogs/InitiativeDialog.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useIssueLookups } from '@/composables/projects/useIssueLookups';
import { cn } from '@/lib/utils';
import type { IssueLookups } from '@/types';

const props = defineProps<{
    initiatives: Initiative[];
    can: { create: boolean };
    lookups: IssueLookups;
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const { lookups } = useIssueLookups(() => props.lookups);
const createOpen = ref(false);

const onCreated = (initiative: Initiative) =>
    router.visit(`/${slug.value}/initiatives/${initiative.slug}`);

const date = (iso: string | null) =>
    iso
        ? new Date(iso + 'T00:00:00').toLocaleDateString(undefined, {
              month: 'short',
              day: 'numeric',
          })
        : null;

const progress = (i: Initiative) => {
    const counted = i.progress.total;

    return counted > 0 ? i.progress.completed / counted : 0;
};
</script>

<template>
    <Head title="Initiatives" />

    <div class="flex h-full min-h-0 flex-col">
        <WorkspaceHeader
            :breadcrumbs="[{ label: 'Initiatives' }]"
            subtitle="Strategic groupings of projects, with progress rolled up."
        >
            <template #actions>
                <Button
                    v-if="can.create"
                    size="sm"
                    class="h-8 gap-1.5"
                    @click="createOpen = true"
                    ><Plus class="size-3.5" /> New initiative</Button
                >
            </template>
        </WorkspaceHeader>

        <div
            class="scrollbar-thin min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-6"
        >
            <div
                v-if="initiatives.length === 0"
                class="mx-auto flex max-w-md flex-col items-center py-16 text-center"
            >
                <span
                    class="mb-3 flex size-11 items-center justify-center rounded-2xl bg-brand-soft text-brand-soft-foreground"
                    ><Compass class="size-5"
                /></span>
                <p class="font-display text-2xl">No initiatives yet.</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Group related projects under one outcome, like “Frontier
                    2.0”, and track them together.
                </p>
                <Button
                    v-if="can.create"
                    size="sm"
                    class="mt-4 gap-1.5"
                    @click="createOpen = true"
                    ><Plus class="size-3.5" /> Create the first
                    initiative</Button
                >
            </div>
            <ul v-else class="mx-auto max-w-5xl space-y-px">
                <li v-for="initiative in initiatives" :key="initiative.uuid">
                    <Link
                        :href="`/${slug}/initiatives/${initiative.slug}`"
                        class="-mx-2 issue-row h-11"
                    >
                        <InboxDot :color="initiative.color" size="md" />
                        <span
                            class="min-w-0 flex-1 truncate text-[13.5px] font-medium"
                            >{{ initiative.name }}</span
                        >
                        <span
                            class="hidden items-center gap-1.5 text-[11.5px] text-muted-foreground md:flex"
                        >
                            <span
                                :class="
                                    cn(
                                        'size-1.5 rounded-full',
                                        INITIATIVE_STATUS[initiative.status]
                                            .dot,
                                    )
                                "
                            />{{ INITIATIVE_STATUS[initiative.status].label }}
                        </span>
                        <span
                            v-if="initiative.target_date"
                            class="hidden w-16 text-right text-[11.5px] text-muted-foreground tabular-nums sm:block"
                            >{{ date(initiative.target_date) }}</span
                        >
                        <span
                            class="flex items-center gap-1.5 text-[11.5px] text-muted-foreground tabular-nums"
                        >
                            <ProgressRing
                                :value="progress(initiative)"
                                :size="14"
                            />
                            {{ initiative.progress.completed }}/{{
                                initiative.progress.total
                            }}
                            issues · {{ initiative.progress.active }} active
                            project{{
                                initiative.progress.active === 1 ? '' : 's'
                            }}
                        </span>
                        <span class="hidden items-center -space-x-0.5 sm:flex">
                            <Tooltip
                                v-for="project in initiative.projects.slice(
                                    0,
                                    5,
                                )"
                                :key="project.uuid"
                            >
                                <TooltipTrigger as-child
                                    ><span
                                        ><InboxDot
                                            :color="project.color"
                                            size="sm" /></span
                                ></TooltipTrigger>
                                <TooltipContent>{{
                                    project.name
                                }}</TooltipContent>
                            </Tooltip>
                        </span>
                        <UserAvatar
                            v-if="initiative.owner"
                            :name="initiative.owner.name"
                            :email="initiative.owner.email"
                            :src="initiative.owner.avatar"
                            :initials="initiative.owner.initials"
                            size="xs"
                        />
                        <span v-else class="size-5" />
                    </Link>
                </li>
            </ul>
        </div>

        <InitiativeDialog
            v-model:open="createOpen"
            :lookups="lookups"
            @saved="onCreated"
        />
    </div>
</template>
