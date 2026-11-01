import { usePage } from '@inertiajs/vue3';
import type { Branding } from '@/types';

/**
 * Product name, logo and copy from config/branding.php, shared on every page.
 * Branding never changes during a session, so the plain object is returned.
 */
export function useBranding(): Branding {
    return usePage().props.branding;
}
