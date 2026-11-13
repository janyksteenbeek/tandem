import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { api, HttpError, reportError } from '@/lib/http';
import { extractMeetingCode } from '@/lib/calls/format';
import { lookup, store } from '@/routes/calls';
import type { Call } from '@/types';

export type StartOptions = {
    title?: string | null;
    password?: string | null;
    waiting_room?: boolean;
    auto_email_transcript?: boolean;
    link_type?: 'issue' | 'project';
    link_key?: string;
};

const clean = (options: StartOptions): Record<string, string | boolean> => {
    const payload: Record<string, string | boolean> = {};

    Object.entries(options).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            payload[key] = value;
        }
    });

    return payload;
};

/**
 * Starting and joining meetings from anywhere in the app. Starting from the Calls pages goes
 * straight into the room in this tab; starting from an issue, a project or the mail composer
 * creates the meeting and hands back its link so the page you were on stays put.
 */
export function useCallStarter() {
    const page = usePage();
    const slug = computed(() => page.props.currentOrganization?.slug ?? '');
    const starting = ref(false);
    const joining = ref(false);

    /** One click: create the meeting and walk into it. */
    const start = (options: StartOptions = {}) => {
        if (starting.value || !slug.value) {
            return;
        }

        router.post(store.url(slug.value), clean(options), {
            onStart: () => (starting.value = true),
            onFinish: () => (starting.value = false),
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ??
                        'The meeting could not be started.',
                ),
        });
    };

    /** Create a meeting without leaving the page; resolves to null when it failed. */
    const create = async (options: StartOptions = {}): Promise<Call | null> => {
        if (starting.value || !slug.value) {
            return null;
        }

        starting.value = true;

        try {
            const data = await api.post<{ call: Call }>(
                store.url(slug.value),
                clean(options),
            );

            return data.call;
        } catch (error) {
            reportError(error, 'The meeting could not be started.');

            return null;
        } finally {
            starting.value = false;
        }
    };

    /**
     * Create a meeting and open it in a new tab. The tab is opened during the click so popup
     * blockers allow it; if it was blocked anyway the meeting opens here instead.
     */
    const startInNewTab = async (
        options: StartOptions = {},
    ): Promise<Call | null> => {
        const tab = window.open('', '_blank');
        const call = await create(options);

        if (!call) {
            tab?.close();

            return null;
        }

        if (tab) {
            tab.location.href = call.join_url;
        } else {
            window.location.href = call.join_url;
        }

        return call;
    };

    /**
     * Join by a pasted code or link. Resolves to an error message to show next to the field,
     * or null when the visit to the meeting is under way.
     */
    const join = async (input: string): Promise<string | null> => {
        if (!extractMeetingCode(input)) {
            return 'That does not look like a meeting code. Codes look like abc-defg-hij.';
        }

        joining.value = true;

        try {
            const data = await api.post<{ url: string }>(
                lookup.url(slug.value),
                { code: input.trim() },
            );

            router.visit(data.url);

            return null;
        } catch (error) {
            if (error instanceof HttpError && error.status === 404) {
                return 'No meeting was found for that code.';
            }

            if (error instanceof HttpError && error.status === 429) {
                return 'Too many attempts. Wait a minute and try again.';
            }

            return 'The meeting could not be looked up. Try again.';
        } finally {
            joining.value = false;
        }
    };

    return { slug, starting, joining, start, create, startInNewTab, join };
}

/** Copy text and say so; falls back to a message when the browser refuses. */
export async function copyText(
    text: string,
    message = 'Copied to clipboard',
): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(message);

        return true;
    } catch {
        toast.error(
            'Copying was blocked by the browser. Select and copy it manually.',
        );

        return false;
    }
}
