<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import {
    Bookmark,
    ChartColumnBig,
    CircleDot,
    Inbox,
    Layers,
    Plus,
    RefreshCw,
    Star,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import ProgressRing from '@/components/projects/charts/ProgressRing.vue';
import SidebarRow from '@/components/shell/SidebarRow.vue';
import SidebarSearchRow from '@/components/shell/SidebarSearchRow.vue';
import SidebarSection from '@/components/shell/SidebarSection.vue';
import ShellColumn from '@/components/shell/ShellColumn.vue';
import WorkspaceSwitcher from '@/components/shell/WorkspaceSwitcher.vue';
import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/kbd';
import { useSidebarPreferences } from '@/composables/projects/useSidebarPreferences';
import { cn } from '@/lib/utils';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { daysUntil } from '@/lib/time';
import type { NavEntity, ProjectsNav } from '@/types';

/**
 * The projects sidebar: your work first, then the things you belong to. Every entity appears
 * once, under the group it belongs to; favorites are the only curated exception. Rows link to
 * the workspace tab you last used; tabs themselves live in the page header, not here.
 */
const emit = defineEmits<{ create: [] }>();

const MAX_ROWS = 8;

const page = usePage();
const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
const prefs = useSidebarPreferences();

const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const nav = computed<ProjectsNav | null>(() => page.props.projectsNav ?? null);
const canManage = computed(() => Boolean(page.props.canManageOrganization));

const url = (path: string) => `/${slug.value}${path}`;

const triageFilters = encodeURIComponent(
    JSON.stringify({
        match: 'all',
        groups: [
            {
                match: 'all',
                clauses: [
                    { field: 'status_category', op: 'in', value: ['backlog'] },
                    { field: 'owner', op: 'is_empty', value: null },
                ],
            },
        ],
    }),
);
const triageUrl = computed(() => url(`/issues?filters=${triageFilters}`));
const onTriage = computed(
    () => isCurrentUrl(url('/issues')) && page.url.includes('backlog'),
);

const myTeams = computed(() =>
    (nav.value?.teams ?? []).filter((team) => team.is_member),
);
const projects = computed(() => nav.value?.projects ?? []);
const products = computed(() => nav.value?.products ?? []);

const entityHref = (type: string, id: number, base: string) => {
    const tab = prefs.lastTab(`${type}:${id}`);

    return tab && tab !== 'overview' ? `${base}/${tab}` : base;
};

const favoriteIcon = (item: NavEntity) =>
    item.type === 'issue'
        ? CircleDot
        : item.type === 'view'
          ? Bookmark
          : item.type === 'cycle'
            ? RefreshCw
            : item.type === 'team'
              ? Users
              : null;

const percent = (fraction: number) => `${Math.round(fraction * 100)}%`;

const viewIcon = (scope: string) =>
    scope === 'personal' ? Bookmark : scope === 'team' ? Users : Star;

const pillClass =
    'inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1.5 text-[10.5px] font-semibold tabular-nums';
</script>

<template>
    <ShellColumn label="Projects">
        <template #header>
            <WorkspaceSwitcher />
            <Button
                class="mt-2 h-8 w-full justify-start gap-2 rounded-lg px-2.5 text-[13px] text-brand-foreground brand-button-surface"
                @click="emit('create')"
            >
                <Plus class="size-3.5" />
                <span class="flex-1 text-left">New issue</span>
                <Kbd
                    class="h-4 min-w-4 bg-brand-foreground/15 px-1 text-[10px] text-brand-foreground/80"
                    >C</Kbd
                >
            </Button>
            <SidebarSearchRow class="mt-2" />
        </template>

        <nav aria-label="Projects">
            <div class="space-y-px">
                <SidebarRow
                    :href="url('/my-issues')"
                    :icon="CircleDot"
                    :active="isCurrentOrParentUrl(url('/my-issues'))"
                >
                    My issues
                    <template v-if="nav && nav.counts.assigned > 0" #trailing>
                        <span
                            :class="
                                cn(
                                    pillClass,
                                    'bg-brand/10 text-brand dark:bg-brand/20',
                                )
                            "
                            >{{ nav.counts.assigned }}</span
                        >
                    </template>
                </SidebarRow>
                <SidebarRow :href="triageUrl" :icon="Inbox" :active="onTriage">
                    Triage
                    <template v-if="nav && nav.counts.triage > 0" #trailing>
                        <span
                            :class="
                                cn(
                                    pillClass,
                                    'bg-sidebar-accent font-medium text-muted-foreground',
                                )
                            "
                            >{{ nav.counts.triage }}</span
                        >
                    </template>
                </SidebarRow>
                <SidebarRow
                    :href="url('/issues')"
                    :icon="Layers"
                    :active="isCurrentUrl(url('/issues')) && !onTriage"
                >
                    Issues
                </SidebarRow>
                <SidebarRow
                    :href="url('/reports')"
                    :icon="ChartColumnBig"
                    :active="isCurrentOrParentUrl(url('/reports'))"
                >
                    Reports
                </SidebarRow>
            </div>

            <template v-if="nav">
                <SidebarSection
                    v-if="nav.favorites.length > 0"
                    label="Favorites"
                    :open="!prefs.isCollapsed('favorites')"
                    @toggle="prefs.toggleGroup('favorites')"
                >
                    <SidebarRow
                        v-for="item in nav.favorites"
                        :key="item.key"
                        :href="item.url"
                        :entity="item.type === 'issue' ? null : item"
                        :icon="favoriteIcon(item)"
                        :identifier="item.identifier"
                        :active="isCurrentOrParentUrl(item.url)"
                    >
                        {{ item.name }}
                    </SidebarRow>
                </SidebarSection>

                <SidebarSection
                    label="Your teams"
                    :open="!prefs.isCollapsed('teams')"
                    :all-href="url('/teams')"
                    @toggle="prefs.toggleGroup('teams')"
                >
                    <div
                        v-if="myTeams.length === 0"
                        class="mx-1 rounded-lg border border-dashed border-sidebar-border px-3 py-2.5 text-[11.5px] text-muted-foreground"
                    >
                        You are not on a team yet.
                    </div>
                    <template v-for="team in myTeams" :key="team.id">
                        <SidebarRow
                            :href="
                                entityHref(
                                    'team',
                                    team.id,
                                    url(`/teams/${team.slug}`),
                                )
                            "
                            :entity="team"
                            :active="
                                isCurrentOrParentUrl(
                                    url(`/teams/${team.slug}`),
                                ) &&
                                !(
                                    team.active_cycle &&
                                    isCurrentOrParentUrl(
                                        url(
                                            `/teams/${team.slug}/cycles/${team.active_cycle.uuid}`,
                                        ),
                                    )
                                )
                            "
                        >
                            {{ team.name }}
                        </SidebarRow>
                        <SidebarRow
                            v-if="team.active_cycle"
                            :href="
                                url(
                                    `/teams/${team.slug}/cycles/${team.active_cycle.uuid}`,
                                )
                            "
                            :icon="RefreshCw"
                            :depth="1"
                            :active="
                                isCurrentOrParentUrl(
                                    url(
                                        `/teams/${team.slug}/cycles/${team.active_cycle.uuid}`,
                                    ),
                                )
                            "
                            :title="`Active cycle · ${team.active_cycle.name} · ${percent(team.active_cycle.progress)} done`"
                        >
                            {{ team.active_cycle.name }}
                            <template #trailing>
                                <span
                                    class="flex items-center gap-1.5 text-[10.5px] text-muted-foreground tabular-nums"
                                >
                                    <span
                                        >{{
                                            daysUntil(
                                                team.active_cycle.ends_at,
                                            )
                                        }}d</span
                                    >
                                    <ProgressRing
                                        :value="team.active_cycle.progress"
                                        :size="14"
                                        :stroke="2.5"
                                    />
                                </span>
                            </template>
                        </SidebarRow>
                    </template>
                </SidebarSection>

                <SidebarSection
                    label="Projects"
                    :open="!prefs.isCollapsed('projects')"
                    :all-href="url('/projects/all')"
                    can-add
                    @toggle="prefs.toggleGroup('projects')"
                    @add="router.visit(url('/projects/all?create=1'))"
                >
                    <div
                        v-if="projects.length === 0"
                        class="mx-1 rounded-lg border border-dashed border-sidebar-border px-3 py-2.5 text-[11.5px] text-muted-foreground"
                    >
                        No active projects.
                    </div>
                    <SidebarRow
                        v-for="project in projects.slice(0, MAX_ROWS)"
                        :key="project.uuid"
                        :href="
                            entityHref(
                                'project',
                                project.id,
                                url(`/projects/${project.slug}`),
                            )
                        "
                        :entity="project"
                        :active="
                            isCurrentOrParentUrl(
                                url(`/projects/${project.slug}`),
                            )
                        "
                        :locked="project.visibility === 'private'"
                        :title="
                            [
                                project.product?.name,
                                project.name,
                                `${percent(project.progress)} done`,
                            ]
                                .filter(Boolean)
                                .join(' · ')
                        "
                    >
                        {{ project.name }}
                        <template v-if="project.progress > 0" #trailing>
                            <ProgressRing
                                :value="project.progress"
                                :size="14"
                                :stroke="2.5"
                                :tone="
                                    project.health === 'at_risk' ||
                                    project.health === 'off_track'
                                        ? 'warning'
                                        : 'brand'
                                "
                            />
                        </template>
                    </SidebarRow>
                    <SidebarRow
                        v-if="projects.length > MAX_ROWS"
                        :href="url('/projects/all')"
                        :depth="1"
                    >
                        <span class="text-muted-foreground"
                            >Show all {{ projects.length }}</span
                        >
                    </SidebarRow>
                </SidebarSection>

                <SidebarSection
                    label="Products"
                    :open="!prefs.isCollapsed('products')"
                    :all-href="url('/products')"
                    :can-add="canManage"
                    @toggle="prefs.toggleGroup('products')"
                    @add="router.visit(url('/products?create=1'))"
                >
                    <div
                        v-if="products.length === 0"
                        class="mx-1 rounded-lg border border-dashed border-sidebar-border px-3 py-2.5 text-[11.5px] text-muted-foreground"
                    >
                        No products yet.
                    </div>
                    <template
                        v-for="product in products.slice(0, MAX_ROWS)"
                        :key="product.uuid"
                    >
                        <SidebarRow
                            :href="
                                entityHref(
                                    'product',
                                    product.id,
                                    url(`/products/${product.slug}`),
                                )
                            "
                            :entity="product"
                            :active="
                                isCurrentOrParentUrl(
                                    url(`/products/${product.slug}`),
                                ) &&
                                !page.url.includes(
                                    `/products/${product.slug}/modules/`,
                                )
                            "
                            :expandable="product.modules.length > 0"
                            :expanded="
                                prefs.isExpanded(`product:${product.id}`)
                            "
                            :trailing="product.open_issues_count || null"
                            @toggle="
                                prefs.toggleExpanded(`product:${product.id}`)
                            "
                        >
                            {{ product.name }}
                        </SidebarRow>
                        <template
                            v-if="prefs.isExpanded(`product:${product.id}`)"
                        >
                            <SidebarRow
                                v-for="module in product.modules.filter(
                                    (m) => m.parent_id === null,
                                )"
                                :key="module.id"
                                :href="
                                    url(
                                        `/products/${product.slug}/modules/${module.slug}`,
                                    )
                                "
                                :entity="{
                                    name: module.name,
                                    color: module.color ?? product.color,
                                    icon: module.icon,
                                }"
                                :depth="1"
                                :active="
                                    isCurrentOrParentUrl(
                                        url(
                                            `/products/${product.slug}/modules/${module.slug}`,
                                        ),
                                    )
                                "
                            >
                                {{ module.name }}
                            </SidebarRow>
                        </template>
                    </template>
                    <SidebarRow
                        v-if="products.length > MAX_ROWS"
                        :href="url('/products')"
                        :depth="1"
                    >
                        <span class="text-muted-foreground"
                            >Show all {{ products.length }}</span
                        >
                    </SidebarRow>
                </SidebarSection>

                <SidebarSection
                    v-if="nav.views.length > 0"
                    label="Views"
                    :open="!prefs.isCollapsed('views')"
                    @toggle="prefs.toggleGroup('views')"
                >
                    <SidebarRow
                        v-for="view in nav.views"
                        :key="view.uuid"
                        :href="url(`/views/${view.uuid}`)"
                        :entity="{
                            name: view.name,
                            color: view.color,
                            icon: view.icon,
                        }"
                        :icon="viewIcon(view.scope)"
                        :active="
                            isCurrentOrParentUrl(url(`/views/${view.uuid}`))
                        "
                    >
                        {{ view.name }}
                    </SidebarRow>
                </SidebarSection>
            </template>
        </nav>
    </ShellColumn>
</template>
