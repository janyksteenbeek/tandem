<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Bookmark,
    ChartColumnBig,
    CircleDot,
    Clock,
    FolderKanban,
    History,
    Inbox,
    Package,
    Plus,
    Star,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import SidebarEntityRow from '@/components/projects/SidebarEntityRow.vue';
import SidebarGroup from '@/components/projects/SidebarGroup.vue';
import ShellColumn from '@/components/shell/ShellColumn.vue';
import ShellNavItem from '@/components/shell/ShellNavItem.vue';
import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/kbd';
import { useSidebarPreferences } from '@/composables/projects/useSidebarPreferences';
import { useBranding } from '@/composables/useBranding';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { cn } from '@/lib/utils';
import type { ProjectsNav } from '@/types';

const emit = defineEmits<{ create: [] }>();

const page = usePage();
const branding = useBranding();
const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
const prefs = useSidebarPreferences();

const organization = computed(() => page.props.currentOrganization);
const slug = computed(() => organization.value?.slug ?? '');
const nav = computed<ProjectsNav | null>(() => page.props.projectsNav ?? null);
const canManage = computed(() => Boolean(page.props.canManageOrganization));

const url = (path: string) => `/${slug.value}${path}`;

const subtitle = computed(() => {
    const counts = nav.value?.counts;

    if (!counts) {
        return 'Products, projects and issues';
    }

    if (counts.due_today > 0) {
        return `${counts.due_today} due today`;
    }

    if (counts.assigned > 0) {
        return `${counts.assigned} assigned to you`;
    }

    return 'Nothing waiting on you';
});

const myTeams = computed(() =>
    (nav.value?.teams ?? []).filter((t) => t.is_member),
);
const otherTeams = computed(() =>
    (nav.value?.teams ?? []).filter((t) => !t.is_member),
);

const productTabs = [
    'overview',
    'issues',
    'modules',
    'projects',
    'releases',
] as const;
const projectTabs = [
    'overview',
    'issues',
    'board',
    'timeline',
    'milestones',
] as const;
const teamTabs = ['overview', 'issues', 'backlog', 'board', 'cycles'] as const;

const tabLabel = (tab: string) => tab.charAt(0).toUpperCase() + tab.slice(1);

const tabRowClass =
    'flex h-6 items-center gap-2 rounded-md px-2 text-[12px] text-sidebar-foreground/70 transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground';
const activeTabClass =
    'bg-brand-soft/60 font-medium text-brand-soft-foreground hover:bg-brand-soft hover:text-brand-soft-foreground';
</script>

<template>
    <ShellColumn label="Projects">
        <template #header>
            <div class="px-4 pt-3 pb-2">
                <div class="flex h-10 flex-col justify-center">
                    <p
                        class="truncate text-[13px] leading-[18px] font-semibold text-sidebar-foreground"
                    >
                        {{ organization?.name ?? branding.name }}
                    </p>
                    <p
                        class="truncate text-[11px] leading-[15px] text-muted-foreground"
                    >
                        {{ subtitle }}
                    </p>
                </div>

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
            </div>
        </template>

        <nav>
            <div class="space-y-px">
                <ShellNavItem
                    :href="url('/my-issues')"
                    :icon="CircleDot"
                    :active="isCurrentOrParentUrl(url('/my-issues'))"
                >
                    My issues
                    <template #trailing>
                        <span
                            v-if="nav && nav.counts.assigned > 0"
                            class="text-[11px] font-semibold text-brand tabular-nums"
                            >{{ nav.counts.assigned }}</span
                        >
                    </template>
                </ShellNavItem>
                <ShellNavItem
                    :href="
                        url(
                            '/issues?filters=' +
                                encodeURIComponent(
                                    JSON.stringify({
                                        match: 'all',
                                        groups: [
                                            {
                                                match: 'all',
                                                clauses: [
                                                    {
                                                        field: 'status_category',
                                                        op: 'in',
                                                        value: ['backlog'],
                                                    },
                                                    {
                                                        field: 'owner',
                                                        op: 'is_empty',
                                                        value: null,
                                                    },
                                                ],
                                            },
                                        ],
                                    }),
                                ),
                        )
                    "
                    :icon="Inbox"
                    :active="
                        isCurrentUrl(url('/issues')) &&
                        page.url.includes('backlog')
                    "
                >
                    Triage
                    <template #trailing>
                        <span
                            v-if="nav && nav.counts.triage > 0"
                            class="text-[11px] text-muted-foreground tabular-nums"
                            >{{ nav.counts.triage }}</span
                        >
                    </template>
                </ShellNavItem>
                <ShellNavItem
                    :href="url('/issues')"
                    :icon="FolderKanban"
                    :active="
                        isCurrentUrl(url('/issues')) &&
                        !page.url.includes('backlog')
                    "
                >
                    All issues
                </ShellNavItem>
                <ShellNavItem
                    :href="url('/reports')"
                    :icon="ChartColumnBig"
                    :active="isCurrentOrParentUrl(url('/reports'))"
                >
                    Reports
                </ShellNavItem>
            </div>

            <template v-if="nav">
                <SidebarGroup
                    v-if="nav.favorites.length > 0"
                    label="Favorites"
                    :open="!prefs.isCollapsed('favorites')"
                    @toggle="prefs.toggleGroup('favorites')"
                >
                    <SidebarEntityRow
                        v-for="item in nav.favorites"
                        :key="item.key"
                        :href="item.url"
                        :name="item.name"
                        :color="item.color"
                        :icon="
                            item.type === 'issue'
                                ? CircleDot
                                : item.type === 'view'
                                  ? Bookmark
                                  : undefined
                        "
                        :identifier="item.identifier"
                        :active="isCurrentOrParentUrl(item.url)"
                    />
                </SidebarGroup>

                <SidebarGroup
                    v-if="nav.recent.length > 0"
                    label="Recent"
                    :open="!prefs.isCollapsed('recent')"
                    @toggle="prefs.toggleGroup('recent')"
                >
                    <SidebarEntityRow
                        v-for="item in nav.recent.slice(0, 6)"
                        :key="item.key"
                        :href="item.url"
                        :name="item.name"
                        :color="item.color"
                        :icon="
                            item.type === 'issue'
                                ? History
                                : item.type === 'view'
                                  ? Bookmark
                                  : undefined
                        "
                        :identifier="item.identifier"
                        :active="isCurrentOrParentUrl(item.url)"
                    />
                </SidebarGroup>

                <SidebarGroup
                    label="Products"
                    :open="!prefs.isCollapsed('products')"
                    :all-href="url('/products')"
                    :can-add="canManage"
                    @toggle="prefs.toggleGroup('products')"
                    @add="$inertia.visit(url('/products?create=1'))"
                >
                    <div
                        v-if="nav.products.length === 0"
                        class="mx-1 rounded-lg border border-dashed border-sidebar-border px-3 py-2.5 text-[11.5px] text-muted-foreground"
                    >
                        No products yet.
                        <Link
                            v-if="canManage"
                            :href="url('/products?create=1')"
                            class="ml-1 font-medium text-brand hover:underline"
                            >Create one</Link
                        >
                    </div>
                    <template
                        v-for="product in nav.products"
                        :key="product.uuid"
                    >
                        <SidebarEntityRow
                            :href="url(`/products/${product.slug}`)"
                            :name="product.name"
                            :color="product.color"
                            :active="
                                isCurrentOrParentUrl(
                                    url(`/products/${product.slug}`),
                                )
                            "
                            expandable
                            :expanded="
                                prefs.isExpanded(`product:${product.id}`)
                            "
                            :trailing="product.open_issues_count || null"
                            @toggle="
                                prefs.toggleExpanded(`product:${product.id}`)
                            "
                        />
                        <div
                            v-if="prefs.isExpanded(`product:${product.id}`)"
                            class="mb-1 ml-5 space-y-px border-l border-sidebar-border pl-2"
                        >
                            <Link
                                v-for="tab in productTabs"
                                :key="tab"
                                :href="url(`/products/${product.slug}/${tab}`)"
                                :class="
                                    cn(
                                        tabRowClass,
                                        isCurrentUrl(
                                            url(
                                                `/products/${product.slug}/${tab}`,
                                            ),
                                        ) && activeTabClass,
                                    )
                                "
                            >
                                {{ tabLabel(tab) }}
                            </Link>
                            <Link
                                v-for="module in product.modules.filter(
                                    (m) => m.parent_id === null,
                                )"
                                :key="module.id"
                                :href="
                                    url(
                                        `/products/${product.slug}/modules/${module.slug}`,
                                    )
                                "
                                :class="
                                    cn(
                                        tabRowClass,
                                        isCurrentOrParentUrl(
                                            url(
                                                `/products/${product.slug}/modules/${module.slug}`,
                                            ),
                                        ) && activeTabClass,
                                    )
                                "
                            >
                                <span
                                    class="size-1.5 rounded-full bg-current opacity-50"
                                />
                                <span class="truncate">{{ module.name }}</span>
                            </Link>
                        </div>
                    </template>
                </SidebarGroup>

                <SidebarGroup
                    label="Projects"
                    :open="!prefs.isCollapsed('projects')"
                    :all-href="url('/projects/all')"
                    can-add
                    @toggle="prefs.toggleGroup('projects')"
                    @add="$inertia.visit(url('/projects/all?create=1'))"
                >
                    <div
                        v-if="nav.projects.length === 0"
                        class="mx-1 rounded-lg border border-dashed border-sidebar-border px-3 py-2.5 text-[11.5px] text-muted-foreground"
                    >
                        No active projects.
                    </div>
                    <template
                        v-for="project in nav.projects.slice(0, 12)"
                        :key="project.uuid"
                    >
                        <SidebarEntityRow
                            :href="url(`/projects/${project.slug}`)"
                            :name="project.name"
                            :color="project.color"
                            :active="
                                isCurrentOrParentUrl(
                                    url(`/projects/${project.slug}`),
                                )
                            "
                            expandable
                            :expanded="
                                prefs.isExpanded(`project:${project.id}`)
                            "
                            :trailing="
                                project.progress > 0
                                    ? `${Math.round(project.progress * 100)}%`
                                    : null
                            "
                            @toggle="
                                prefs.toggleExpanded(`project:${project.id}`)
                            "
                        />
                        <div
                            v-if="prefs.isExpanded(`project:${project.id}`)"
                            class="mb-1 ml-5 space-y-px border-l border-sidebar-border pl-2"
                        >
                            <Link
                                v-for="tab in projectTabs"
                                :key="tab"
                                :href="url(`/projects/${project.slug}/${tab}`)"
                                :class="
                                    cn(
                                        tabRowClass,
                                        isCurrentUrl(
                                            url(
                                                `/projects/${project.slug}/${tab}`,
                                            ),
                                        ) && activeTabClass,
                                    )
                                "
                            >
                                {{ tabLabel(tab) }}
                            </Link>
                        </div>
                    </template>
                </SidebarGroup>

                <SidebarGroup
                    label="Teams"
                    :open="!prefs.isCollapsed('teams')"
                    :all-href="url('/teams')"
                    @toggle="prefs.toggleGroup('teams')"
                >
                    <template
                        v-for="team in [...myTeams, ...otherTeams]"
                        :key="team.id"
                    >
                        <SidebarEntityRow
                            :href="url(`/teams/${team.slug}`)"
                            :name="team.name"
                            :color="team.color"
                            :icon="team.is_member ? undefined : Users"
                            :active="
                                isCurrentOrParentUrl(url(`/teams/${team.slug}`))
                            "
                            expandable
                            :expanded="prefs.isExpanded(`team:${team.id}`)"
                            @toggle="prefs.toggleExpanded(`team:${team.id}`)"
                        />
                        <div
                            v-if="prefs.isExpanded(`team:${team.id}`)"
                            class="mb-1 ml-5 space-y-px border-l border-sidebar-border pl-2"
                        >
                            <Link
                                v-for="tab in teamTabs"
                                :key="tab"
                                :href="url(`/teams/${team.slug}/${tab}`)"
                                :class="
                                    cn(
                                        tabRowClass,
                                        isCurrentUrl(
                                            url(`/teams/${team.slug}/${tab}`),
                                        ) && activeTabClass,
                                    )
                                "
                            >
                                {{ tabLabel(tab) }}
                            </Link>
                        </div>
                    </template>
                </SidebarGroup>

                <SidebarGroup
                    v-if="nav.cycles.length > 0"
                    label="Active cycles"
                    :open="!prefs.isCollapsed('cycles')"
                    @toggle="prefs.toggleGroup('cycles')"
                >
                    <SidebarEntityRow
                        v-for="cycle in nav.cycles"
                        :key="cycle.uuid"
                        :href="
                            url(
                                `/teams/${cycle.team?.slug}/cycles/${cycle.uuid}`,
                            )
                        "
                        :name="`${cycle.team?.name} · ${cycle.name}`"
                        :icon="Clock"
                        :active="
                            isCurrentOrParentUrl(
                                url(
                                    `/teams/${cycle.team?.slug}/cycles/${cycle.uuid}`,
                                ),
                            )
                        "
                        :trailing="`${Math.round(cycle.progress * 100)}%`"
                    />
                </SidebarGroup>

                <SidebarGroup
                    v-if="nav.views.length > 0"
                    label="Views"
                    :open="!prefs.isCollapsed('views')"
                    @toggle="prefs.toggleGroup('views')"
                >
                    <SidebarEntityRow
                        v-for="view in nav.views"
                        :key="view.uuid"
                        :href="url(`/views/${view.uuid}`)"
                        :name="view.name"
                        :icon="
                            view.scope === 'personal'
                                ? Bookmark
                                : view.scope === 'team'
                                  ? Users
                                  : Star
                        "
                        :active="
                            isCurrentOrParentUrl(url(`/views/${view.uuid}`))
                        "
                    />
                </SidebarGroup>
            </template>
        </nav>

        <template #footer>
            <Package class="size-3" />
            <span
                >Press <kbd class="rounded border px-1 text-[10px]">⌘K</kbd> to
                search everything</span
            >
        </template>
    </ShellColumn>
</template>
