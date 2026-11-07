<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import { Star } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Breadcrumbs from '@/components/projects/workspace/Breadcrumbs.vue';
import type { Crumb } from '@/components/projects/workspace/Breadcrumbs.vue';
import WorkspaceTabs from '@/components/projects/workspace/WorkspaceTabs.vue';
import type { WorkspaceTab } from '@/components/projects/workspace/WorkspaceTabs.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { api, reportError } from '@/lib/http';
import { cn } from '@/lib/utils';

/**
 * The shared top of every product, module, project and team workspace: breadcrumbs, name with
 * colour, meta chips, actions and the tab strip. Favourite state is derived from projectsNav.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        subtitle?: string | null;
        color?: string | null;
        icon?: LucideIcon;
        breadcrumbs: Crumb[];
        tabs: WorkspaceTab[];
        activeTab: string;
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
        subtitle: null,
        color: null,
        favoriteType: null,
        favoriteId: null,
        isFavorite: null,
    },
);

const emit = defineEmits<{ tab: [key: string] }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');

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
</script>

<template>
    <header
        class="shrink-0 border-b bg-background/95 px-4 pt-3 backdrop-blur sm:px-6"
    >
        <Breadcrumbs :items="breadcrumbs" class="pl-9 md:pl-0" />
        <div
            class="mt-2 flex flex-wrap items-start justify-between gap-x-4 gap-y-2"
        >
            <div class="flex min-w-0 items-center gap-2.5">
                <span
                    v-if="icon"
                    class="flex size-7 items-center justify-center rounded-md bg-muted text-muted-foreground"
                    ><component :is="icon" class="size-4"
                /></span>
                <InboxDot
                    v-else-if="color"
                    :color="color"
                    size="md"
                    class="size-3"
                />
                <div class="min-w-0">
                    <h1
                        class="truncate font-display text-xl leading-tight font-semibold tracking-[-0.01em]"
                    >
                        {{ title }}
                    </h1>
                    <p
                        v-if="subtitle"
                        class="truncate text-[12px] text-muted-foreground"
                    >
                        {{ subtitle }}
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
        <WorkspaceTabs
            class="mt-2"
            :tabs="tabs"
            :active="activeTab"
            @change="(key) => emit('tab', key)"
        />
    </header>
</template>
