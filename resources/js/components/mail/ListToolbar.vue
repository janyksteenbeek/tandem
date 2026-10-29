<script setup lang="ts">
import { ArrowDownUp, Check, Filter, Paperclip, Search, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import UserAvatar from '@/components/mail/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { ConversationFilters, Tag, UserSummary } from '@/types';

const props = defineProps<{
    title: string;
    subtitle?: string | null;
    filters: ConversationFilters;
    members: UserSummary[];
    tags: Tag[];
    isShared: boolean;
    total?: number | null;
}>();

const emit = defineEmits<{
    update: [filters: ConversationFilters];
    saveView: [];
}>();

const q = ref(props.filters.q ?? '');
const searchInput = ref<InstanceType<typeof Input> | null>(null);

watch(
    () => props.filters.q,
    (value) => (q.value = value ?? ''),
);

const patch = (changes: ConversationFilters) =>
    emit('update', { ...props.filters, ...changes });

let timer: ReturnType<typeof setTimeout> | null = null;

watch(q, (value) => {
    if (timer) {
        clearTimeout(timer);
    }

    timer = setTimeout(() => {
        if ((props.filters.q ?? '') !== value) {
            patch({ q: value || undefined });
        }
    }, 350);
});

const activeCount = computed(
    () =>
        [
            'assignee',
            'status',
            'priority',
            'tag',
            'unread',
            'attachments',
            'from',
        ].filter((key) => {
            const value = props.filters[key as keyof ConversationFilters];

            return value !== undefined && value !== '' && value !== null;
        }).length,
);

const clear = () => {
    q.value = '';
    emit('update', { sort: props.filters.sort });
};

const tagValue = computed(() =>
    Array.isArray(props.filters.tag) ? props.filters.tag[0] : props.filters.tag,
);

defineExpose({
    focusSearch: () =>
        (searchInput.value?.$el as HTMLInputElement | undefined)?.focus(),
});
</script>

<template>
    <div
        class="border-b border-border/80 bg-surface/80 backdrop-blur supports-[backdrop-filter]:bg-surface/60"
    >
        <div class="flex items-center gap-2 px-3 pt-3 pb-2 pl-12 md:pl-3">
            <div class="min-w-0 flex-1">
                <h1 class="truncate text-[15px] font-semibold tracking-tight">
                    {{ title }}
                </h1>
                <p
                    v-if="subtitle"
                    class="truncate text-[11px] text-muted-foreground"
                >
                    {{ subtitle }}
                </p>
            </div>
            <span
                v-if="total !== null && total !== undefined"
                class="text-[11px] text-muted-foreground tabular-nums"
                >{{ total }}</span
            >

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="sm"
                        :class="
                            cn(
                                'h-8 gap-1 px-2 text-xs',
                                activeCount &&
                                    'bg-brand-soft text-brand-soft-foreground',
                            )
                        "
                    >
                        <Filter class="size-3.5" />
                        <span class="hidden sm:inline">Filter</span>
                        <span
                            v-if="activeCount"
                            class="rounded-full bg-brand px-1.5 text-[10px] text-brand-foreground"
                            >{{ activeCount }}</span
                        >
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-60">
                    <DropdownMenuCheckboxItem
                        :model-value="filters.unread === '1'"
                        @update:model-value="
                            (v) => patch({ unread: v ? '1' : undefined })
                        "
                        >Unread only</DropdownMenuCheckboxItem
                    >
                    <DropdownMenuCheckboxItem
                        :model-value="filters.attachments === '1'"
                        @update:model-value="
                            (v) => patch({ attachments: v ? '1' : undefined })
                        "
                    >
                        <Paperclip class="mr-1 size-3.5" /> Has attachments
                    </DropdownMenuCheckboxItem>

                    <template v-if="isShared">
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel
                            class="text-[11px] text-muted-foreground uppercase"
                            >Assignee</DropdownMenuLabel
                        >
                        <DropdownMenuRadioGroup
                            :model-value="filters.assignee ?? ''"
                            @update:model-value="
                                (v) =>
                                    patch({
                                        assignee: v ? String(v) : undefined,
                                    })
                            "
                        >
                            <DropdownMenuRadioItem value=""
                                >Anyone</DropdownMenuRadioItem
                            >
                            <DropdownMenuRadioItem value="me"
                                >Me</DropdownMenuRadioItem
                            >
                            <DropdownMenuRadioItem value="none"
                                >Unassigned</DropdownMenuRadioItem
                            >
                            <DropdownMenuRadioItem
                                v-for="m in members"
                                :key="m.id"
                                :value="String(m.id)"
                                class="gap-2"
                            >
                                <UserAvatar
                                    :name="m.name"
                                    :email="m.email"
                                    :src="m.avatar"
                                    :initials="m.initials"
                                    size="xs"
                                />
                                {{ m.name }}
                            </DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>

                        <DropdownMenuSeparator />
                        <DropdownMenuLabel
                            class="text-[11px] text-muted-foreground uppercase"
                            >Priority</DropdownMenuLabel
                        >
                        <DropdownMenuRadioGroup
                            :model-value="filters.priority ?? ''"
                            @update:model-value="
                                (v) =>
                                    patch({
                                        priority: v ? String(v) : undefined,
                                    })
                            "
                        >
                            <DropdownMenuRadioItem value=""
                                >Any</DropdownMenuRadioItem
                            >
                            <DropdownMenuRadioItem value="urgent"
                                >Urgent</DropdownMenuRadioItem
                            >
                            <DropdownMenuRadioItem value="high"
                                >High</DropdownMenuRadioItem
                            >
                            <DropdownMenuRadioItem value="normal"
                                >Normal</DropdownMenuRadioItem
                            >
                            <DropdownMenuRadioItem value="low"
                                >Low</DropdownMenuRadioItem
                            >
                        </DropdownMenuRadioGroup>
                    </template>

                    <template v-if="tags.length">
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel
                            class="text-[11px] text-muted-foreground uppercase"
                            >Tag</DropdownMenuLabel
                        >
                        <DropdownMenuRadioGroup
                            :model-value="tagValue ?? ''"
                            @update:model-value="
                                (v) => patch({ tag: v ? String(v) : undefined })
                            "
                        >
                            <DropdownMenuRadioItem value=""
                                >Any tag</DropdownMenuRadioItem
                            >
                            <DropdownMenuRadioItem
                                v-for="tag in tags"
                                :key="tag.id"
                                :value="tag.slug"
                                >{{ tag.name }}</DropdownMenuRadioItem
                            >
                        </DropdownMenuRadioGroup>
                    </template>

                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        :disabled="activeCount === 0 && !filters.q"
                        @click="emit('saveView')"
                        ><Check class="mr-1 size-3.5" /> Save as
                        view</DropdownMenuItem
                    >
                    <DropdownMenuItem
                        :disabled="activeCount === 0 && !filters.q"
                        @click="clear"
                        ><X class="mr-1 size-3.5" /> Clear
                        filters</DropdownMenuItem
                    >
                </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-8"
                        aria-label="Sort"
                    >
                        <ArrowDownUp class="size-3.5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuRadioGroup
                        :model-value="filters.sort ?? 'newest'"
                        @update:model-value="
                            (v) =>
                                patch({
                                    sort:
                                        v === 'newest' || !v
                                            ? undefined
                                            : String(v),
                                })
                        "
                    >
                        <DropdownMenuRadioItem value="newest"
                            >Newest first</DropdownMenuRadioItem
                        >
                        <DropdownMenuRadioItem value="oldest"
                            >Oldest first</DropdownMenuRadioItem
                        >
                        <DropdownMenuRadioItem value="priority"
                            >Priority</DropdownMenuRadioItem
                        >
                    </DropdownMenuRadioGroup>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <div class="relative px-3 pb-2.5">
            <Search
                class="pointer-events-none absolute top-1/2 left-5.5 size-3.5 -translate-y-[calc(50%+5px)] text-muted-foreground"
            />
            <Input
                ref="searchInput"
                v-model="q"
                type="search"
                placeholder="Search this inbox…"
                class="h-8 rounded-lg border-transparent bg-surface-sunken pl-8 text-[13px] shadow-none focus-visible:border-input focus-visible:bg-background"
                data-search-input
            />
        </div>
    </div>
</template>
