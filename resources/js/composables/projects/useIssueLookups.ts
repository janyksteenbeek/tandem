import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { api } from '@/lib/http';
import type { IssueLookups } from '@/types';

const cache = ref<Record<string, IssueLookups>>({});
const pending = new Map<string, Promise<IssueLookups>>();

/**
 * Pickers and the create dialog need the same lookups everywhere; pages that already received
 * them as props seed the cache, mail pages fetch lazily.
 */
export function useIssueLookups(
    initial?: () => IssueLookups | null | undefined,
) {
    const page = usePage();
    const slug = computed(() => page.props.currentOrganization?.slug ?? '');

    const seeded = initial?.();

    if (seeded && slug.value) {
        cache.value = { ...cache.value, [slug.value]: seeded };
    }

    const lookups = computed<IssueLookups | null>(
        () => initial?.() ?? cache.value[slug.value] ?? null,
    );

    const load = async (): Promise<IssueLookups | null> => {
        if (!slug.value) {
            return null;
        }

        if (cache.value[slug.value]) {
            return cache.value[slug.value];
        }

        let request = pending.get(slug.value);

        if (!request) {
            request = api
                .get<{ lookups: IssueLookups }>(`/${slug.value}/issues/lookups`)
                .then((data) => {
                    cache.value = {
                        ...cache.value,
                        [slug.value]: data.lookups,
                    };
                    pending.delete(slug.value);

                    return data.lookups;
                });
            pending.set(slug.value, request);
        }

        return request;
    };

    const invalidate = () => {
        const next = { ...cache.value };
        delete next[slug.value];
        cache.value = next;
    };

    return { lookups, load, invalidate };
}
