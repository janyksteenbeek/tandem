import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { homeFor, sectionForPath } from '@/lib/navigation';

/**
 * Switching organization keeps you in the same area: mail stays mail, projects stays projects.
 */
export function useOrganizationSwitcher() {
    const page = usePage();
    const { currentUrl } = useCurrentUrl();
    const organization = computed(() => page.props.currentOrganization);
    const organizations = computed(() => page.props.organizations ?? []);

    const switchTo = (slug: string) => {
        if (slug === organization.value?.slug) {
            return;
        }

        const section = sectionForPath(
            currentUrl.value,
            organization.value?.slug ?? null,
        );

        router.post(
            `/settings/organizations/${slug}/switch`,
            {},
            { onFinish: () => router.visit(homeFor(section, slug)) },
        );
    };

    return { organization, organizations, switchTo };
}
