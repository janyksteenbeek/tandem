import { usePage } from '@inertiajs/vue3';
import type { Sso } from '@/types';

/**
 * Single sign-on settings from config/sso.php, shared on every page: which
 * identity providers are enabled and whether they are the only way in.
 */
export function useSso(): Sso {
    return usePage().props.sso;
}
