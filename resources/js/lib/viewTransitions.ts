import type { VisitOptions } from '@inertiajs/core';

/**
 * Decides which Inertia visits get a browser view transition.
 *
 * Full page visits (rail, sidebar, settings navigation, form redirects) fade
 * the content column. Partial reloads keep working silently: live counters,
 * "load more" and filter refreshes would otherwise cross-fade the whole screen
 * every time a message arrives. A visit that asks for a transition itself
 * (`viewTransition: true`) always gets one.
 */
export function visitOptions(
    _href: string,
    options: VisitOptions,
): VisitOptions {
    if (options.viewTransition) {
        return {};
    }

    const isPartial = (options.only?.length ?? 0) > 0;

    return {
        viewTransition: !isPartial && !options.prefetch && !options.async,
    };
}
