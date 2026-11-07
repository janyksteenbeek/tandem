<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, MoreHorizontal, Plus, Search, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/projects/dialogs/ConfirmDialog.vue';
import ReleaseDialog from '@/components/projects/dialogs/ReleaseDialog.vue';
import type { ReleaseDetail } from '@/components/projects/dialogs/ReleaseDialog.vue';
import RichTextEditor from '@/components/projects/editor/RichTextEditor.vue';
import { DatePicker } from '@/components/projects/pickers';
import IssueIdentifier from '@/components/projects/shared/IssueIdentifier.vue';
import StatusIcon from '@/components/projects/shared/StatusIcon.vue';
import Breadcrumbs from '@/components/projects/workspace/Breadcrumbs.vue';
import CompactIssueRow from '@/components/projects/workspace/CompactIssueRow.vue';
import OverviewSection from '@/components/projects/workspace/OverviewSection.vue';
import SegmentedProgress from '@/components/projects/workspace/SegmentedProgress.vue';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useDebounceFn } from '@vueuse/core';
import { api, reportError } from '@/lib/http';
import { CATEGORY_META, CATEGORY_ORDER } from '@/lib/projects/meta';
import { cn } from '@/lib/utils';
import type {
    IssueListItem,
    IssueLookups,
    Product,
    StatusCategory,
} from '@/types';

const props = defineProps<{
    product: Product;
    release: ReleaseDetail;
    issues: IssueListItem[];
    blockers: IssueListItem[];
    lookups: IssueLookups;
    can: { update: boolean };
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const productBase = computed(
    () => `/${slug.value}/products/${props.product.slug}`,
);
const apiBase = computed(
    () => `${productBase.value}/releases/${props.release.uuid}`,
);

const release = ref<ReleaseDetail>(props.release);
const issues = ref<IssueListItem[]>(props.issues);
watch(
    () => props.release,
    (value) => (release.value = value),
);
watch(
    () => props.issues,
    (value) => (issues.value = value),
);

const STATUS: Record<
    ReleaseDetail['status'],
    { label: string; className: string }
> = {
    planned: { label: 'Planned', className: 'bg-muted text-muted-foreground' },
    in_progress: {
        label: 'In progress',
        className: 'bg-warning/20 text-warning-foreground',
    },
    released: { label: 'Released', className: 'bg-success/12 text-success' },
    canceled: {
        label: 'Canceled',
        className: 'bg-destructive/12 text-destructive',
    },
};

const counts = computed(() => {
    const by: Record<StatusCategory, number> = {
        backlog: 0,
        unstarted: 0,
        started: 0,
        completed: 0,
        canceled: 0,
    };
    issues.value.forEach((i) => (by[i.status_category] += 1));

    return by;
});

const grouped = computed(() =>
    CATEGORY_ORDER.map((category) => ({
        category,
        label: CATEGORY_META[category].label,
        rows: issues.value.filter((i) => i.status_category === category),
    })).filter((g) => g.rows.length > 0),
);

const patch = async (payload: Record<string, unknown>) => {
    try {
        const data = await api.patch<{ release: ReleaseDetail }>(
            apiBase.value,
            payload,
        );
        release.value = data.release;
    } catch (error) {
        reportError(error);
    }
};

const notes = ref(release.value.notes_html ?? '');
const notesState = ref<'idle' | 'saving' | 'saved'>('idle');
const saveNotes = useDebounceFn(async () => {
    if ((notes.value || null) === (release.value.notes_html ?? null)) {
        return;
    }

    notesState.value = 'saving';
    await patch({ notes_html: notes.value || null });
    notesState.value = 'saved';
    setTimeout(() => (notesState.value = 'idle'), 1500);
}, 800);
watch(notes, () => void saveNotes());

const editOpen = ref(false);
const deleteOpen = ref(false);
const busy = ref(false);

const destroy = async () => {
    busy.value = true;

    try {
        await api.delete(apiBase.value);
        toast.success('Release deleted');
        router.visit(`${productBase.value}/releases`);
    } catch (error) {
        reportError(error);
        busy.value = false;
    }
};

// Add issues
const addOpen = ref(false);
const query = ref('');
const results = ref<IssueListItem[]>([]);
const searching = ref(false);
const search = useDebounceFn(async (term: string) => {
    searching.value = true;

    try {
        const data = await api.get<{ issues: IssueListItem[] }>(
            `/${slug.value}/issues/search`,
            { q: term, limit: 10 },
        );
        results.value = data.issues.filter(
            (i) =>
                !issues.value.some((x) => x.uuid === i.uuid) &&
                (!i.product || i.product.id === props.product.id),
        );
    } finally {
        searching.value = false;
    }
}, 200);
watch(query, (value) => void search(value));
watch(addOpen, (value) => {
    if (value) {
        query.value = '';
        void search('');
    }
});

const toggleIssue = async (issue: IssueListItem, attached: boolean) => {
    const previous = issues.value;
    issues.value = attached
        ? [issue, ...issues.value]
        : issues.value.filter((i) => i.uuid !== issue.uuid);
    results.value = results.value.filter((i) => i.uuid !== issue.uuid);

    try {
        const data = await api.post<{ release: ReleaseDetail }>(
            `${apiBase.value}/issues`,
            { identifier: issue.identifier, attached },
        );
        release.value = data.release;
        router.reload({ only: ['issues', 'blockers'] });
    } catch (error) {
        issues.value = previous;
        reportError(error);
    }
};

const date = (iso: string | null) =>
    iso
        ? new Date(iso.slice(0, 10) + 'T00:00:00').toLocaleDateString(
              undefined,
              { month: 'short', day: 'numeric', year: 'numeric' },
          )
        : null;

const onReleaseSaved = (saved: ReleaseDetail) => {
    release.value = saved;
    notes.value = saved.notes_html ?? '';
};
</script>

<template>
    <Head :title="`${release.name} · ${product.name}`" />

    <div class="flex h-full min-h-0 flex-col">
        <header
            class="shrink-0 border-b bg-background/95 px-4 pt-3 pb-3 backdrop-blur sm:px-6"
        >
            <Breadcrumbs
                :items="[
                    { label: 'Products', href: `/${slug}/products` },
                    { label: product.name, href: productBase },
                    { label: 'Releases', href: `${productBase}/releases` },
                    { label: release.name },
                ]"
                class="pl-9 md:pl-0"
            />
            <div
                class="mt-2 flex flex-wrap items-start justify-between gap-x-4 gap-y-2"
            >
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <h1
                            class="truncate font-display text-xl leading-tight font-semibold tracking-[-0.01em]"
                        >
                            {{ release.name }}
                        </h1>
                        <span
                            v-if="release.version"
                            class="rounded-md border bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground"
                            >{{ release.version }}</span
                        >
                        <span
                            v-if="!can.update"
                            :class="
                                cn(
                                    'rounded-full px-2 py-0.5 text-[11px] font-medium',
                                    STATUS[release.status].className,
                                )
                            "
                            >{{ STATUS[release.status].label }}</span
                        >
                    </div>
                    <p class="text-[12px] text-muted-foreground">
                        <template v-if="release.released_at"
                            >Released {{ date(release.released_at) }}</template
                        >
                        <template v-else-if="release.planned_at"
                            >Planned for
                            {{ date(release.planned_at) }}</template
                        >
                        <template v-else>No date yet</template>
                        · {{ release.completed_issues_count }} of
                        {{ release.issues_count }} issues done
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <template v-if="can.update">
                        <NativeSelect
                            :model-value="release.status"
                            class="h-8 text-[12.5px]"
                            aria-label="Release status"
                            @change="
                                (e: Event) =>
                                    patch({
                                        status: (e.target as HTMLSelectElement)
                                            .value,
                                    })
                            "
                        >
                            <NativeSelectOption
                                v-for="(meta, key) in STATUS"
                                :key="key"
                                :value="key"
                                >{{ meta.label }}</NativeSelectOption
                            >
                        </NativeSelect>
                        <DatePicker
                            :value="release.planned_at"
                            label="Planned date"
                            @change="(v) => patch({ planned_at: v })"
                        />
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-8"
                                    aria-label="Release actions"
                                    ><MoreHorizontal class="size-4"
                                /></Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-40">
                                <DropdownMenuItem @click="editOpen = true"
                                    >Edit release</DropdownMenuItem
                                >
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    class="text-destructive"
                                    @click="deleteOpen = true"
                                    >Delete</DropdownMenuItem
                                >
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </template>
                </div>
            </div>
            <SegmentedProgress
                class="mt-3 max-w-3xl"
                :completed="counts.completed"
                :started="counts.started"
                :unstarted="counts.unstarted"
                :backlog="counts.backlog"
                :canceled="counts.canceled"
            />
        </header>

        <div
            class="scrollbar-thin min-h-0 flex-1 overflow-y-auto px-4 py-6 sm:px-6"
        >
            <div
                class="mx-auto grid max-w-5xl gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]"
            >
                <div class="space-y-8">
                    <OverviewSection
                        label="Release notes"
                        :hint="
                            notesState === 'saving'
                                ? 'Saving…'
                                : notesState === 'saved'
                                  ? 'Saved'
                                  : null
                        "
                    >
                        <RichTextEditor
                            v-if="can.update"
                            v-model="notes"
                            placeholder="What ships in this release? Type @ to mention someone."
                            :members="lookups.members"
                            min-height="4rem"
                        />
                        <div
                            v-else-if="release.notes_html"
                            class="prose-tandem text-[14px]"
                            v-html="release.notes_html"
                        />
                        <p v-else class="text-[13px] text-muted-foreground">
                            No release notes yet.
                        </p>
                    </OverviewSection>

                    <OverviewSection label="Issues" :hint="`${issues.length}`">
                        <template v-if="can.update" #action>
                            <Popover v-model:open="addOpen">
                                <PopoverTrigger as-child>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        class="h-7 gap-1 text-xs"
                                        ><Plus class="size-3" /> Add
                                        issue</Button
                                    >
                                </PopoverTrigger>
                                <PopoverContent class="w-96 p-0" align="end">
                                    <Command>
                                        <CommandInput
                                            placeholder="Search issues…"
                                            @input="
                                                (e: Event) =>
                                                    (query = (
                                                        e.target as HTMLInputElement
                                                    ).value)
                                            "
                                        />
                                        <CommandList class="max-h-72">
                                            <CommandEmpty>{{
                                                searching
                                                    ? 'Searching…'
                                                    : 'No issues found.'
                                            }}</CommandEmpty>
                                            <CommandGroup>
                                                <CommandItem
                                                    v-for="issue in results"
                                                    :key="issue.uuid"
                                                    :value="`${issue.identifier} ${issue.title}`"
                                                    class="gap-2"
                                                    @select="
                                                        toggleIssue(issue, true)
                                                    "
                                                >
                                                    <StatusIcon
                                                        :status="issue.status"
                                                        :size="13"
                                                    />
                                                    <IssueIdentifier
                                                        :identifier="
                                                            issue.identifier
                                                        "
                                                    />
                                                    <span class="truncate">{{
                                                        issue.title
                                                    }}</span>
                                                </CommandItem>
                                            </CommandGroup>
                                        </CommandList>
                                    </Command>
                                </PopoverContent>
                            </Popover>
                        </template>
                        <div
                            v-if="issues.length === 0"
                            class="flex flex-col items-center rounded-lg border border-dashed px-3 py-8 text-center text-[12.5px] text-muted-foreground"
                        >
                            <Search class="mb-2 size-4" />
                            No issues in this release yet.
                        </div>
                        <div v-else class="space-y-4">
                            <div v-for="group in grouped" :key="group.category">
                                <p
                                    class="mb-1 flex items-center gap-1.5 text-[11px] font-medium text-muted-foreground"
                                >
                                    <StatusIcon
                                        :category="group.category"
                                        :size="12"
                                    />
                                    {{ group.label }}
                                    <span class="tabular-nums">{{
                                        group.rows.length
                                    }}</span>
                                </p>
                                <div class="space-y-px">
                                    <div
                                        v-for="issue in group.rows"
                                        :key="issue.uuid"
                                        class="group/row flex items-center gap-1"
                                    >
                                        <CompactIssueRow
                                            :issue="issue"
                                            class="flex-1"
                                        />
                                        <Button
                                            v-if="can.update"
                                            variant="ghost"
                                            size="icon"
                                            class="size-6 shrink-0 opacity-0 group-hover/row:opacity-100 focus-visible:opacity-100"
                                            :aria-label="`Remove ${issue.identifier} from release`"
                                            @click="toggleIssue(issue, false)"
                                            ><X class="size-3"
                                        /></Button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </OverviewSection>
                </div>

                <div class="space-y-8">
                    <OverviewSection
                        label="Blockers"
                        :hint="blockers.length ? `${blockers.length}` : null"
                    >
                        <p
                            v-if="blockers.length === 0"
                            class="text-[12.5px] text-muted-foreground"
                        >
                            Nothing in this release is blocked.
                        </p>
                        <div v-else class="space-y-px">
                            <div
                                v-for="issue in blockers"
                                :key="issue.uuid"
                                class="flex items-center gap-1"
                            >
                                <AlertTriangle
                                    class="size-3.5 shrink-0 text-destructive"
                                />
                                <CompactIssueRow
                                    :issue="issue"
                                    class="flex-1"
                                    :show-teams="false"
                                />
                            </div>
                        </div>
                    </OverviewSection>
                    <OverviewSection label="Summary">
                        <dl class="space-y-1.5 text-[12.5px]">
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">Status</dt>
                                <dd>{{ STATUS[release.status].label }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">Planned</dt>
                                <dd>{{ date(release.planned_at) ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">Released</dt>
                                <dd>{{ date(release.released_at) ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">Progress</dt>
                                <dd class="tabular-nums">
                                    {{
                                        Math.round(
                                            (release.progress ?? 0) * 100,
                                        )
                                    }}%
                                </dd>
                            </div>
                        </dl>
                    </OverviewSection>
                </div>
            </div>
        </div>

        <ReleaseDialog
            v-model:open="editOpen"
            :product-slug="product.slug"
            :product-name="product.name"
            :release="release"
            @saved="onReleaseSaved"
        />
        <ConfirmDialog
            v-model:open="deleteOpen"
            title="Delete this release?"
            description="Issues stay where they are; only the release is removed."
            confirm-label="Delete release"
            destructive
            :busy="busy"
            @confirm="destroy"
        />
    </div>
</template>
