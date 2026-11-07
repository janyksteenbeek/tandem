import { router, usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { computed, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { matches } from '@/lib/projects/issueFilters';
import { issueStore } from '@/lib/projects/issueStore';
import type { CursorPage, IssueFilters, IssueListItem } from '@/types';

type Payload = {
    reason: string;
    issue: IssueListItem | { uuid: string; identifier: string };
};

type Options = {
    page: () => CursorPage<IssueListItem> | null | undefined;
    filters: () => IssueFilters;
    /** Private channels to listen on, e.g. `organization.3.issues`, `team.4`, `project.{uuid}`. */
    channels: () => string[];
    /** Extra membership rule on top of the filters (e.g. a My Issues bucket). */
    belongs?: (issue: IssueListItem) => boolean;
    /** Prop key to reload for "load more". */
    propKey?: string;
};

/**
 * Keeps a store-backed, ordered list in sync with Inertia merge props and realtime updates.
 * Rows that stop matching the filters leave; new matching rows arrive at the top.
 */
export function useIssueView(options: Options) {
    const page = usePage();
    const meId = computed(() => page.props.auth.user.id);
    const order: Ref<string[]> = ref([]);
    const nextCursor = ref<string | null>(null);
    const loadingMore = ref(false);
    const loading = ref(false);

    const belongs = (issue: IssueListItem) =>
        matches(issue, options.filters(), meId.value) &&
        (options.belongs?.(issue) ?? true);

    const sync = () => {
        const current = options.page();

        if (!current) {
            return;
        }

        issueStore.ingest(current.data);

        // Merge props append; keep order unique and in page order.
        const seen = new Set<string>();
        order.value = current.data
            .map((i) => i.uuid)
            .filter((uuid) =>
                seen.has(uuid) ? false : (seen.add(uuid), true),
            );
        nextCursor.value = current.meta.next_cursor;
        loadingMore.value = false;
        loading.value = false;
    };

    watch(options.page, sync, { immediate: true, deep: false });

    const items = computed<IssueListItem[]>(() => {
        void issueStore.version.value;

        return order.value
            .map((uuid) => issueStore.get(uuid))
            .filter((issue): issue is IssueListItem => Boolean(issue));
    });

    const upsert = (incoming: IssueListItem) => {
        issueStore.upsert(incoming);
        const index = order.value.indexOf(incoming.uuid);
        const keep = belongs(incoming);

        if (index === -1) {
            if (keep && !options.filters().q) {
                order.value = [incoming.uuid, ...order.value];
            }

            return;
        }

        if (!keep) {
            order.value = order.value.filter((uuid) => uuid !== incoming.uuid);
        }
    };

    const remove = (uuid: string) => {
        order.value = order.value.filter((u) => u !== uuid);
        issueStore.remove(uuid);
    };

    const handle = (payload: Payload) => {
        if (payload.reason === 'deleted') {
            remove(payload.issue.uuid);

            return;
        }

        if ('title' in payload.issue) {
            upsert(payload.issue);
        }
    };

    for (const channel of options.channels()) {
        useEcho<Payload>(channel, '.issue.updated', handle);
    }

    const loadMore = (data: Record<string, unknown> = {}) => {
        if (!nextCursor.value || loadingMore.value) {
            return;
        }

        loadingMore.value = true;
        router.reload({
            only: [options.propKey ?? 'issues'],
            data: { ...data, cursor: nextCursor.value },
        });
    };

    return {
        items,
        order,
        nextCursor,
        loading,
        loadingMore,
        loadMore,
        upsert,
        remove,
        belongs,
    };
}
