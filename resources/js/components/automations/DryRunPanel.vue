<script setup lang="ts">
import { useDebounceFn } from '@vueuse/core';
import { Check, FlaskConical, Loader2, Search, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { describeCondition } from '@/lib/automations';
import { api, reportError } from '@/lib/http';
import { displayName } from '@/lib/mail';
import { listTime } from '@/lib/time';
import { cn } from '@/lib/utils';
import { search } from '@/routes';
import { test } from '@/routes/automations';
import type {
    AutomationCatalog,
    AutomationCondition,
    AutomationLookups,
    AutomationMatch,
    DryRunResult,
} from '@/types/automations';
import type { ConversationListItem } from '@/types/mail';

const props = defineProps<{
    slug: string;
    ruleUuid: string | null;
    conditions: AutomationCondition[];
    match: AutomationMatch;
    catalog: AutomationCatalog;
    lookups: AutomationLookups;
}>();

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

const query = ref('');
const results = ref<ConversationListItem[]>([]);
const searching = ref(false);
const picked = ref<{ uuid: string; subject: string | null } | null>(null);
const running = ref(false);
const result = ref<DryRunResult | null>(null);
const stale = ref(false);

const runSearch = useDebounceFn(async () => {
    const q = query.value.trim();

    if (q.length < 2 || UUID.test(q)) {
        results.value = [];
        searching.value = false;

        return;
    }

    try {
        const response = await api.get<{
            conversations: ConversationListItem[];
        }>(search.url(), { q, limit: 6 });
        results.value = response.conversations;
    } catch (error) {
        reportError(error, 'Search is taking a nap.');
    } finally {
        searching.value = false;
    }
}, 300);

watch(query, (value) => {
    if (UUID.test(value.trim())) {
        picked.value = { uuid: value.trim(), subject: null };
        results.value = [];

        return;
    }

    searching.value = value.trim().length >= 2;
    void runSearch();
});

watch(
    () => [props.conditions, props.match],
    () => {
        if (result.value) {
            stale.value = true;
        }
    },
    { deep: true },
);

const pick = (item: ConversationListItem) => {
    picked.value = { uuid: item.uuid, subject: item.subject };
    results.value = [];
    query.value = '';
    result.value = null;
};

const clear = () => {
    picked.value = null;
    result.value = null;
    stale.value = false;
};

const run = async () => {
    if (!props.ruleUuid || !picked.value) {
        return;
    }

    running.value = true;

    try {
        result.value = await api.post<DryRunResult>(
            test.url({
                current_organization: props.slug,
                rule: props.ruleUuid,
            }),
            {
                conversation_uuid: picked.value.uuid,
                conditions: props.conditions,
                match: props.match,
            },
        );
        picked.value = result.value.conversation;
        stale.value = false;
    } catch (error) {
        reportError(error, 'Could not run the test.');
    } finally {
        running.value = false;
    }
};

const verdict = computed(() => {
    if (!result.value) {
        return null;
    }

    if (result.value.would_run) {
        return {
            tone: 'success',
            title: 'It would run.',
            body: 'Every check lines up for this conversation.',
        };
    }

    if (result.value.matched) {
        return {
            tone: 'warning',
            title: 'Matches, but would not run.',
            body: 'The rule is paused or scoped to a different inbox.',
        };
    }

    return {
        tone: 'muted',
        title: 'No match.',
        body:
            props.match === 'all'
                ? 'At least one condition failed.'
                : 'None of the conditions passed.',
    };
});

const formatActual = (value: unknown): string => {
    if (value === null || value === undefined || value === '') {
        return 'empty';
    }

    if (Array.isArray(value)) {
        return value.length === 0 ? 'empty' : value.join(', ');
    }

    if (typeof value === 'boolean') {
        return value ? 'yes' : 'no';
    }

    return String(value);
};
</script>

<template>
    <section class="rounded-xl border bg-card p-4 shadow-pane">
        <div class="mb-3 flex items-center gap-2">
            <FlaskConical class="size-4 text-brand" />
            <h3 class="text-sm font-semibold">Dry run</h3>
        </div>

        <p
            v-if="!ruleUuid"
            class="rounded-lg border border-dashed p-3 text-xs text-muted-foreground"
        >
            Save the rule once and you can test it against a real conversation
            without touching anything.
        </p>

        <template v-else>
            <p class="mb-3 text-xs text-muted-foreground">
                Pick a conversation and see which conditions pass. Nothing is
                changed, promise.
            </p>

            <div v-if="!picked" class="relative">
                <Search
                    class="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                />
                <Input
                    v-model="query"
                    class="pl-8"
                    placeholder="Search a subject or paste a conversation id"
                    autocomplete="off"
                />
                <Loader2
                    v-if="searching"
                    class="absolute top-2.5 right-2.5 size-4 animate-spin text-muted-foreground"
                />

                <ul
                    v-if="results.length > 0"
                    data-test="dry-run-results"
                    class="mt-2 scrollbar-thin max-h-56 divide-y overflow-y-auto rounded-lg border bg-popover shadow-float"
                >
                    <li v-for="item in results" :key="item.uuid">
                        <button
                            type="button"
                            class="flex w-full flex-col gap-0.5 px-3 py-2 text-left text-sm hover:bg-accent"
                            @click="pick(item)"
                        >
                            <span
                                class="flex items-center justify-between gap-2"
                            >
                                <span class="truncate font-medium">{{
                                    item.subject || '(no subject)'
                                }}</span>
                                <span
                                    class="shrink-0 text-[11px] text-muted-foreground"
                                    >{{ listTime(item.last_activity_at) }}</span
                                >
                            </span>
                            <span class="truncate text-xs text-muted-foreground"
                                >{{ displayName(item.counterpart) }} ·
                                {{ item.inbox_name }}</span
                            >
                        </button>
                    </li>
                </ul>
                <p
                    v-else-if="query.trim().length >= 2 && !searching"
                    class="mt-2 text-xs text-muted-foreground"
                >
                    Nothing matches. Try a name or a word from the subject.
                </p>
            </div>

            <div
                v-else
                class="flex items-center gap-2 rounded-lg border bg-surface px-3 py-2 text-sm"
            >
                <span class="min-w-0 flex-1 truncate">
                    <span class="font-medium">{{
                        picked.subject || 'Conversation'
                    }}</span>
                    <span
                        v-if="!picked.subject"
                        class="ml-1 font-mono text-[11px] text-muted-foreground"
                        >{{ picked.uuid.slice(0, 8) }}…</span
                    >
                </span>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-xs"
                    aria-label="Pick another conversation"
                    @click="clear"
                    ><X
                /></Button>
            </div>

            <Button
                type="button"
                class="mt-3 w-full bg-brand text-brand-foreground hover:bg-brand/90"
                size="sm"
                :disabled="!picked || running"
                @click="run"
            >
                <Loader2 v-if="running" class="animate-spin" />
                <FlaskConical v-else />
                {{
                    result
                        ? stale
                            ? 'Run again with current conditions'
                            : 'Run again'
                        : 'Test this conversation'
                }}
            </Button>

            <div v-if="result && verdict" class="mt-3 animate-rise space-y-2">
                <div
                    :class="
                        cn(
                            'rounded-lg px-3 py-2',
                            verdict.tone === 'success' &&
                                'bg-success/15 text-foreground',
                            verdict.tone === 'warning' &&
                                'bg-warning/20 text-foreground',
                            verdict.tone === 'muted' &&
                                'bg-surface-sunken text-foreground',
                        )
                    "
                >
                    <p class="text-sm font-semibold">{{ verdict.title }}</p>
                    <p class="text-xs opacity-80">{{ verdict.body }}</p>
                    <p v-if="stale" class="mt-1 text-[11px] opacity-70">
                        You changed the conditions since this run.
                    </p>
                </div>

                <ul
                    v-if="result.conditions.length > 0"
                    class="divide-y rounded-lg border text-sm"
                >
                    <li
                        v-for="(check, i) in result.conditions"
                        :key="i"
                        class="flex items-start gap-2 px-3 py-2"
                    >
                        <span
                            :class="
                                cn(
                                    'mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full',
                                    check.passed
                                        ? 'bg-success text-success-foreground'
                                        : 'bg-destructive/15 text-destructive',
                                )
                            "
                        >
                            <Check v-if="check.passed" class="size-3" />
                            <X v-else class="size-3" />
                        </span>
                        <span class="min-w-0">
                            <span class="block">{{
                                describeCondition(check, catalog, lookups)
                            }}</span>
                            <span
                                class="block truncate text-xs text-muted-foreground"
                            >
                                <template v-if="check.error">{{
                                    check.error
                                }}</template>
                                <template v-else
                                    >Actual:
                                    {{ formatActual(check.actual) }}</template
                                >
                            </span>
                        </span>
                    </li>
                </ul>
                <p v-else class="text-xs text-muted-foreground">
                    No conditions, so every conversation matches.
                </p>
            </div>
        </template>
    </section>
</template>
