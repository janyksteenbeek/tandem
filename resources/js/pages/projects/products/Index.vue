<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Package, Plus } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import CreateProductDialog from '@/components/projects/dialogs/CreateProductDialog.vue';
import AvatarStack from '@/components/projects/shared/AvatarStack.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/kbd';
import { useIssueLookups } from '@/composables/projects/useIssueLookups';
import type {
    IssueLookups,
    ProductSummary,
    TeamSummary,
    UserSummary,
} from '@/types';

type Row = ProductSummary & {
    description: string | null;
    owner: UserSummary | null;
    teams: TeamSummary[];
    modules_count: number;
};

const props = defineProps<{
    products: Row[];
    lookups: IssueLookups;
    can: { create: boolean };
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const { lookups } = useIssueLookups(() => props.lookups);

const createOpen = ref(false);

onMounted(() => {
    if (
        new URLSearchParams(window.location.search).get('create') === '1' &&
        props.can.create
    ) {
        createOpen.value = true;
    }
});

const active = computed(() =>
    props.products.filter((p) => p.status === 'active'),
);
const archived = computed(() =>
    props.products.filter((p) => p.status !== 'active'),
);
</script>

<template>
    <Head title="Products" />

    <div class="flex h-full min-h-0 flex-col">
        <header
            class="flex shrink-0 items-center justify-between gap-3 border-b px-4 py-3 pl-12 sm:px-6 md:pl-6"
        >
            <div>
                <h1
                    class="font-display text-xl font-semibold tracking-[-0.01em]"
                >
                    Products
                </h1>
                <p class="text-[12px] text-muted-foreground">
                    What {{ page.props.currentOrganization?.name }} builds and
                    maintains.
                </p>
            </div>
            <Button
                v-if="can.create"
                size="sm"
                class="gap-1.5 bg-brand text-brand-foreground hover:bg-brand/90"
                @click="createOpen = true"
                ><Plus class="size-3.5" /> New product</Button
            >
        </header>

        <div
            class="scrollbar-thin min-h-0 flex-1 overflow-y-auto px-4 py-5 sm:px-6"
        >
            <div
                v-if="products.length === 0"
                class="mx-auto flex max-w-md flex-col items-center py-16 text-center"
            >
                <span
                    class="mb-3 flex size-11 items-center justify-center rounded-2xl bg-brand-soft text-brand-soft-foreground"
                    ><Package class="size-5"
                /></span>
                <p class="font-display text-2xl">No products yet.</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    A product groups modules, projects, releases and issues
                    under one key. Start with the thing you ship.
                </p>
                <Button
                    v-if="can.create"
                    class="mt-4 gap-1.5"
                    @click="createOpen = true"
                    ><Plus class="size-3.5" /> Create the first product</Button
                >
            </div>

            <template v-else>
                <ul
                    class="grid grid-cols-[repeat(auto-fill,minmax(16rem,18rem))] gap-3"
                >
                    <li v-for="product in active" :key="product.uuid">
                        <Link
                            :href="`/${slug}/products/${product.slug}`"
                            class="group flex h-full flex-col gap-2 rounded-lg border bg-card px-4 py-3 shadow-pane transition-colors hover:border-brand/40 hover:bg-accent/30"
                        >
                            <div class="flex items-center gap-2">
                                <InboxDot :color="product.color" size="md" />
                                <span
                                    class="min-w-0 flex-1 truncate text-[14px] font-medium"
                                    >{{ product.name }}</span
                                >
                                <Kbd class="h-5 px-1.5 font-mono text-[10px]">{{
                                    product.key
                                }}</Kbd>
                            </div>
                            <p
                                class="line-clamp-2 min-h-[2.25rem] text-[12.5px] text-muted-foreground"
                            >
                                {{
                                    product.description || 'No description yet.'
                                }}
                            </p>
                            <div
                                class="mt-auto flex items-center gap-3 text-[11.5px] text-muted-foreground"
                            >
                                <span
                                    ><span
                                        class="font-semibold text-foreground tabular-nums"
                                        >{{ product.open_issues_count }}</span
                                    >
                                    open</span
                                >
                                <span
                                    ><span
                                        class="font-semibold text-foreground tabular-nums"
                                        >{{ product.modules_count }}</span
                                    >
                                    modules</span
                                >
                                <span class="ml-auto flex items-center gap-1.5">
                                    <span class="flex items-center -space-x-0.5"
                                        ><InboxDot
                                            v-for="team in product.teams.slice(
                                                0,
                                                4,
                                            )"
                                            :key="team.id"
                                            :color="team.color"
                                            size="sm"
                                    /></span>
                                    <AvatarStack
                                        v-if="product.owner"
                                        :users="[product.owner]"
                                    />
                                </span>
                            </div>
                        </Link>
                    </li>
                </ul>

                <section v-if="archived.length > 0" class="mt-8">
                    <p
                        class="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        Archived
                    </p>
                    <ul class="space-y-px">
                        <li v-for="product in archived" :key="product.uuid">
                            <Link
                                :href="`/${slug}/products/${product.slug}`"
                                class="-mx-2 issue-row text-muted-foreground"
                            >
                                <InboxDot :color="product.color" size="sm" />
                                <span class="truncate">{{ product.name }}</span>
                                <span class="font-mono text-[11px]">{{
                                    product.key
                                }}</span>
                            </Link>
                        </li>
                    </ul>
                </section>
            </template>
        </div>

        <CreateProductDialog v-model:open="createOpen" :lookups="lookups" />
    </div>
</template>
