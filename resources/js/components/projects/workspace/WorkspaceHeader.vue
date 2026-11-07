<script lang="ts">
export type Crumb = { label: string; href?: string | null };
</script>

<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import { Star } from '@lucide/vue';
import { computed, ref, useSlots, watch } from 'vue';
import WorkspaceTabs from '@/components/projects/workspace/WorkspaceTabs.vue';
import type { WorkspaceTab } from '@/components/projects/workspace/WorkspaceTabs.vue';
import EntityGlyph from '@/components/shell/EntityGlyph.vue';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useSidebarPreferences } from '@/composables/projects/useSidebarPreferences';
import { api, reportError } from '@/lib/http';
import { cn } from '@/lib/utils';

/**
 * The one header every projects page uses. The breadcrumb trail always starts at the section
 * the sidebar links to (Issues, Projects, Products, Teams, …). Without a `title` the last crumb
 * is the page title and the header is a single row; with one, the entity gets its own row with
 * colour, subtitle, meta chips, actions and tabs.
 */
const props = withDefaults(
    defineProps<{
        breadcrumbs: Crumb[];
        title?: string | null;
        subtitle?: string | null;
        color?: string | null;
        icon?: LucideIcon;
        /** The entity's stored lucide id; with `color` it renders the same tile as the sidebar. */
        iconName?: string | null;
        tabs?: WorkspaceTab[];
        activeTab?: string | null;
        favoriteType?:
            | 'product'
            | 'module'
            | 'project'
            | 'team'
            | 'cycle'
            | 'view'
            | null;
        favoriteId?: number | null;
        isFavorite?: boolean | null;
    }>(),
    {
        title: null,
        subtitle: null,
        color: null,
        iconName: null,
        tabs: () => [],
        activeTab: null,
        favoriteType: null,
        favoriteId: null,
        isFavorite: null,
    },
);

const emit = defineEmits<{ tab: [key: string] }>();

const slots = useSlots();
const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const prefs = useSidebarPreferences();

const compact = computed(() => !props.title);
const trail = computed(() =>
    compact.value ? props.breadcrumbs.slice(0, -1) : props.breadcrumbs,
);
const current = computed(() => props.breadcrumbs[props.breadcrumbs.length - 1]);

const derivedFavorite = computed(() => {
    if (props.isFavorite !== null) {
        return props.isFavorite;
    }

    return (page.props.projectsNav?.favorites ?? []).some(
        (f) => f.type === props.favoriteType && f.id === props.favoriteId,
    );
});

const favorite = ref(derivedFavorite.value);
watch(derivedFavorite, (value) => (favorite.value = value));

const toggleFavorite = async () => {
    if (!props.favoriteType || !props.favoriteId) {
        return;
    }

    favorite.value = !favorite.value;

    try {
        const data = await api.post<{ favorite: boolean }>(
            `/${slug.value}/favorites`,
            { type: props.favoriteType, id: props.favoriteId },
        );
        favorite.value = data.favorite;
        router.reload({ only: ['projectsNav'] });
    } catch (error) {
        favorite.value = !favorite.value;
        reportError(error);
    }
};

/** Sidebar rows return to the tab you used last on this product, project or team. */
const changeTab = (key: string) => {
    if (props.favoriteType && props.favoriteId) {
        prefs.rememberTab(`${props.favoriteType}:${props.favoriteId}`, key);
    }

    emit('tab', key);
};
</script>

<template>
    <header
        class="shrink-0 border-b bg-background/95 px-4 pl-12 backdrop-blur sm:px-6 md:pl-6"
    >
        <div
            :class="
                cn('flex min-w-0 items-center gap-3', compact ? 'h-12' : 'h-9')
            "
        >
            <Breadcrumb class="min-w-0">
                <BreadcrumbList
                    class="flex-nowrap gap-1 text-[12px] sm:gap-1.5"
                >
                    <template v-for="(crumb, index) in trail" :key="index">
                        <BreadcrumbSeparator
                            v-if="index > 0"
                            class="[&>svg]:size-3"
                        />
                        <BreadcrumbItem class="min-w-0">
                            <BreadcrumbLink v-if="crumb.href" as-child>
                                <Link :href="crumb.href" class="truncate">{{
                                    crumb.label
                                }}</Link>
                            </BreadcrumbLink>
                            <BreadcrumbPage v-else class="truncate">{{
                                crumb.label
                            }}</BreadcrumbPage>
                        </BreadcrumbItem>
                    </template>
                    <template v-if="compact && current">
                        <BreadcrumbSeparator
                            v-if="trail.length > 0"
                            class="[&>svg]:size-3"
                        />
                        <BreadcrumbItem class="min-w-0">
                            <h1
                                class="truncate text-[15px] font-semibold tracking-[-0.01em] text-foreground"
                                aria-current="page"
                            >
                                {{ current.label }}
                            </h1>
                        </BreadcrumbItem>
                    </template>
                </BreadcrumbList>
            </Breadcrumb>
            <template v-if="compact">
                <p
                    v-if="subtitle"
                    class="hidden min-w-0 truncate text-[12px] text-muted-foreground lg:block"
                >
                    {{ subtitle }}
                </p>
                <div
                    v-if="slots.meta || slots.actions"
                    class="ml-auto flex shrink-0 items-center gap-2"
                >
                    <slot name="meta" />
                    <slot name="actions" />
                </div>
            </template>
        </div>

        <div
            v-if="!compact"
            class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 pb-2"
        >
            <div class="flex min-w-0 items-center gap-2.5">
                <span
                    v-if="icon"
                    class="flex size-7 items-center justify-center rounded-md bg-muted text-muted-foreground"
                    ><component :is="icon" class="size-4"
                /></span>
                <EntityGlyph
                    v-else-if="color"
                    :name="title ?? ''"
                    :color="color"
                    :icon="iconName"
                    size="lg"
                />
                <div class="min-w-0">
                    <div class="flex min-w-0 items-center gap-2">
                        <h1
                            class="truncate font-display text-xl leading-tight font-semibold tracking-[-0.01em]"
                        >
                            {{ title }}
                        </h1>
                        <slot name="title-extra" />
                    </div>
                    <p
                        v-if="subtitle || slots.subtitle"
                        class="truncate text-[12px] text-muted-foreground"
                    >
                        <slot name="subtitle">{{ subtitle }}</slot>
                    </p>
                </div>
                <Tooltip v-if="favoriteType && favoriteId">
                    <TooltipTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-7"
                            :aria-label="
                                favorite
                                    ? 'Remove from favorites'
                                    : 'Add to favorites'
                            "
                            :aria-pressed="favorite"
                            @click="toggleFavorite"
                        >
                            <Star
                                :class="
                                    cn(
                                        'size-4',
                                        favorite
                                            ? 'fill-highlight text-highlight'
                                            : 'text-muted-foreground',
                                    )
                                "
                            />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>{{
                        favorite ? 'Favorited' : 'Add to favorites'
                    }}</TooltipContent>
                </Tooltip>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <slot name="meta" />
                <slot name="actions" />
            </div>
        </div>

        <slot name="tabs">
            <WorkspaceTabs
                v-if="tabs.length > 0"
                :class="compact ? '' : 'mt-1'"
                :tabs="tabs"
                :active="activeTab ?? ''"
                @change="changeTab"
            />
        </slot>

        <div v-if="slots.default" class="pb-3">
            <slot />
        </div>
    </header>
</template>
