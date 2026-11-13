import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { api, reportError } from '@/lib/http';
import { share, unshare } from '@/routes/calls/recordings';

type Scope = { slug: string; call: string; recording: string };

/**
 * The secret link that lets someone without an account watch a recording. The server only
 * ever returns the link at the moment it is created, so it is kept here to be shown once.
 */
export function useRecordingShare(
    scope: () => Scope,
    initiallyShared: boolean,
) {
    const shared = ref(initiallyShared);
    const link = ref<{ url: string; expires_at: string | null } | null>(null);
    const busy = ref(false);

    const args = (): [string, string, string] => {
        const { slug, call, recording } = scope();

        return [slug, call, recording];
    };

    const create = async () => {
        busy.value = true;

        try {
            link.value = await api.post<{
                url: string;
                expires_at: string | null;
            }>(share.url(args()));
            shared.value = true;
        } catch (error) {
            reportError(error, 'The link could not be created.');
        } finally {
            busy.value = false;
        }
    };

    const revoke = async () => {
        busy.value = true;

        try {
            await api.delete(unshare.url(args()));
            shared.value = false;
            link.value = null;
            toast.success('The link no longer works');
        } catch (error) {
            reportError(error, 'The link could not be revoked.');
        } finally {
            busy.value = false;
        }
    };

    return { shared, link, busy, create, revoke };
}
