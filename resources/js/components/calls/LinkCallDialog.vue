<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { api, HttpError } from '@/lib/http';
import { cn } from '@/lib/utils';
import { store } from '@/routes/calls/links';
import type { CallLinkItem, IssueListItem } from '@/types';

/**
 * Tie a meeting to the issue or project it was about. Issues are found by identifier or
 * title; projects are picked from the ones you can see.
 */
const props = defineProps<{ callUuid: string }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ linked: [links: CallLinkItem[]] }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const projects = computed(() => page.props.projectsNav?.projects ?? []);

const type = ref<'issue' | 'project'>('issue');
const query = ref('');
const results = ref<IssueListItem[]>([]);
const searching = ref(false);
const busy = ref(false);
const error = ref<string | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(open, (value) => {
    if (value) {
        type.value = 'issue';
        query.value = '';
        results.value = [];
        error.value = null;
    }
});

watch(query, (term) => {
    clearTimeout(timer);
    error.value = null;

    if (type.value !== 'issue' || term.trim().length < 2) {
        results.value = [];

        return;
    }

    timer = setTimeout(async () => {
        searching.value = true;

        try {
            const data = await api.get<{ issues: IssueListItem[] }>(
                `/${slug.value}/issues/search`,
                { q: term.trim(), limit: 8 },
            );

            if (query.value === term) {
                results.value = data.issues;
            }
        } catch {
            results.value = [];
        } finally {
            searching.value = false;
        }
    }, 200);
});

const filteredProjects = computed(() => {
    const term = query.value.trim().toLowerCase();

    return projects.value
        .filter((project) => !term || project.name.toLowerCase().includes(term))
        .slice(0, 8);
});

const link = async (key: string) => {
    busy.value = true;
    error.value = null;

    try {
        const data = await api.post<{ links: CallLinkItem[] }>(
            store.url([slug.value, props.callUuid]),
            { type: type.value, key },
        );

        emit('linked', data.links);
        open.value = false;
    } catch (failure) {
        error.value =
            failure instanceof HttpError
                ? failure.message
                : 'The link could not be saved.';
    } finally {
        busy.value = false;
    }
};

/** Enter on a typed identifier (FRONT-124) links it directly. */
const submit = () => {
    const term = query.value.trim();

    if (type.value === 'issue' && /^[a-z0-9]+-\d+$/i.test(term)) {
        void link(term.toUpperCase());
    } else if (type.value === 'issue' && results.value.length === 1) {
        void link(results.value[0].identifier);
    } else if (
        type.value === 'project' &&
        filteredProjects.value.length === 1
    ) {
        void link(filteredProjects.value[0].slug);
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Link this meeting</DialogTitle>
                <DialogDescription>
                    The meeting then shows up on the issue or project, for
                    everyone who may open both.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-3" @submit.prevent="submit">
                <div
                    class="inline-flex rounded-lg bg-muted p-0.5 text-[13px]"
                    role="radiogroup"
                    aria-label="Link to"
                >
                    <button
                        v-for="option in ['issue', 'project'] as const"
                        :key="option"
                        type="button"
                        role="radio"
                        :aria-checked="type === option"
                        :class="
                            cn(
                                'rounded-md px-3 py-1 capitalize transition-colors',
                                type === option
                                    ? 'bg-background font-medium shadow-xs'
                                    : 'text-muted-foreground hover:text-foreground',
                            )
                        "
                        @click="
                            type = option;
                            query = '';
                        "
                    >
                        {{ option }}
                    </button>
                </div>

                <div class="space-y-1.5">
                    <Label for="link-call-query" class="sr-only">{{
                        type === 'issue' ? 'Find an issue' : 'Find a project'
                    }}</Label>
                    <div class="relative">
                        <Input
                            id="link-call-query"
                            v-model="query"
                            v-focus
                            class="h-9"
                            autocomplete="off"
                            :placeholder="
                                type === 'issue'
                                    ? 'Identifier or title, e.g. FRONT-124'
                                    : 'Project name'
                            "
                        />
                        <Spinner
                            v-if="searching || busy"
                            class="absolute top-1/2 right-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                    </div>
                </div>

                <ul
                    v-if="type === 'issue' && results.length > 0"
                    class="max-h-64 divide-y overflow-y-auto rounded-lg border"
                >
                    <li v-for="issue in results" :key="issue.uuid">
                        <button
                            type="button"
                            class="flex w-full items-center gap-2 px-3 py-2 text-left text-[13px] hover:bg-accent/60"
                            :disabled="busy"
                            @click="link(issue.identifier)"
                        >
                            <span
                                class="shrink-0 font-mono text-[11px] text-muted-foreground"
                                >{{ issue.identifier }}</span
                            >
                            <span class="min-w-0 flex-1 truncate">{{
                                issue.title
                            }}</span>
                        </button>
                    </li>
                </ul>
                <ul
                    v-else-if="
                        type === 'project' && filteredProjects.length > 0
                    "
                    class="max-h-64 divide-y overflow-y-auto rounded-lg border"
                >
                    <li v-for="project in filteredProjects" :key="project.uuid">
                        <button
                            type="button"
                            class="flex w-full items-center gap-2 px-3 py-2 text-left text-[13px] hover:bg-accent/60"
                            :disabled="busy"
                            @click="link(project.slug)"
                        >
                            <span class="min-w-0 flex-1 truncate">{{
                                project.name
                            }}</span>
                        </button>
                    </li>
                </ul>
                <p
                    v-else-if="
                        !searching &&
                        (type === 'project' || query.trim().length >= 2)
                    "
                    class="rounded-lg border border-dashed px-3 py-3 text-center text-[12.5px] text-muted-foreground"
                >
                    {{
                        type === 'issue'
                            ? 'No issue matches. Type the full identifier and press Enter.'
                            : 'No project matches.'
                    }}
                </p>

                <p
                    v-if="error"
                    role="alert"
                    class="text-[12.5px] text-destructive"
                >
                    {{ error }}
                </p>
            </form>

            <DialogFooter>
                <Button variant="ghost" size="sm" @click="open = false"
                    >Close</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
