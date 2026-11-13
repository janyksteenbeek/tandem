<script lang="ts">
export type LinkIssueMode =
    | 'relation'
    | 'parent'
    | 'child'
    | 'dependency'
    | 'conversation';
</script>

<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Loader2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AvatarStack from '@/components/projects/shared/AvatarStack.vue';
import IssueIdentifier from '@/components/projects/shared/IssueIdentifier.vue';
import StatusIcon from '@/components/projects/shared/StatusIcon.vue';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { api } from '@/lib/http';
import type { IssueListItem, IssueRelationKind } from '@/types';

/**
 * Search-and-pick an existing issue: to relate it, make it the parent, add it as a sub-issue,
 * or make a workstream depend on it. Empty query shows recently active issues.
 */
const props = withDefaults(
    defineProps<{ mode: LinkIssueMode; exclude?: string; title?: string }>(),
    { exclude: undefined, title: undefined },
);
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{
    pick: [issue: IssueListItem, kind?: IssueRelationKind];
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');

const query = ref('');
const results = ref<IssueListItem[]>([]);
const searching = ref(false);
const kind = ref<IssueRelationKind>('blocked_by');

const heading = computed(() => {
    if (props.title) {
        return props.title;
    }

    switch (props.mode) {
        case 'parent':
            return 'Set parent issue';
        case 'child':
            return 'Add existing issue as sub-issue';
        case 'dependency':
            return 'Wait for another issue';
        case 'conversation':
            return 'Link to issue';
        default:
            return 'Relate to an issue';
    }
});

const search = useDebounceFn(async (term: string) => {
    searching.value = true;

    try {
        const data = await api.get<{ issues: IssueListItem[] }>(
            `/${slug.value}/issues/search`,
            { q: term, limit: 12, exclude: props.exclude },
        );

        if (query.value === term) {
            results.value = data.issues;
        }
    } catch {
        results.value = [];
    } finally {
        searching.value = false;
    }
}, 180);

watch(query, (value) => void search(value));

watch(open, (value) => {
    if (value) {
        query.value = '';
        results.value = [];
        void search('');
    }
});

const onInput = (event: Event) => {
    const target = event.target;

    if (target instanceof HTMLInputElement) {
        query.value = target.value;
    }
};

const pick = (issue: IssueListItem) => {
    emit('pick', issue, props.mode === 'relation' ? kind.value : undefined);
    open.value = false;
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="top-[14%] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-lg"
            :show-close-button="false"
        >
            <DialogTitle class="sr-only">{{ heading }}</DialogTitle>
            <DialogDescription class="sr-only"
                >Search issues by identifier or title.</DialogDescription
            >

            <div
                class="flex items-center justify-between gap-2 border-b px-4 py-2"
            >
                <p class="text-[12px] font-medium text-muted-foreground">
                    {{ heading }}
                </p>
                <ToggleGroup
                    v-if="mode === 'relation'"
                    v-model="kind"
                    type="single"
                    size="sm"
                    variant="outline"
                    class="h-7"
                >
                    <ToggleGroupItem
                        value="blocked_by"
                        class="h-7 px-2 text-[11px]"
                        >Blocked by</ToggleGroupItem
                    >
                    <ToggleGroupItem value="blocks" class="h-7 px-2 text-[11px]"
                        >Blocks</ToggleGroupItem
                    >
                    <ToggleGroupItem
                        value="related"
                        class="h-7 px-2 text-[11px]"
                        >Related</ToggleGroupItem
                    >
                    <ToggleGroupItem
                        value="duplicate"
                        class="h-7 px-2 text-[11px]"
                        >Duplicate</ToggleGroupItem
                    >
                </ToggleGroup>
            </div>

            <Command class="rounded-none border-0" @input="onInput">
                <div class="relative">
                    <input
                        class="h-11 w-full border-b bg-transparent px-4 text-[14px] outline-none placeholder:text-muted-foreground/60"
                        placeholder="Type an identifier or title…"
                        autofocus
                    />
                    <Loader2
                        v-if="searching"
                        class="absolute top-3.5 right-4 size-4 animate-spin text-muted-foreground"
                    />
                </div>
                <CommandList class="max-h-80">
                    <CommandEmpty>{{
                        query ? 'No matching issues.' : 'No recent issues.'
                    }}</CommandEmpty>
                    <CommandGroup :heading="query ? 'Results' : 'Recent'">
                        <CommandItem
                            v-for="issue in results"
                            :key="issue.uuid"
                            :value="`${issue.identifier} ${issue.title}`"
                            class="gap-2.5 py-2"
                            @select="pick(issue)"
                        >
                            <StatusIcon :status="issue.status" :size="14" />
                            <IssueIdentifier :identifier="issue.identifier" />
                            <span class="min-w-0 flex-1 truncate text-[13px]">{{
                                issue.title
                            }}</span>
                            <AvatarStack
                                v-if="issue.assignees.length"
                                :users="issue.assignees"
                                :max="2"
                            />
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </DialogContent>
    </Dialog>
</template>
