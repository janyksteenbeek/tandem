import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch, type Ref } from 'vue';
import { toast } from 'vue-sonner';
import { api, reportError } from '@/lib/http';
import type { Draft, DraftAttachment, DraftKind, EmailAddress, Message } from '@/types';

export type DraftPatch = {
    to?: EmailAddress[];
    cc?: EmailAddress[];
    bcc?: EmailAddress[];
    subject?: string | null;
    html_body?: string | null;
    sender_identity_id?: number | null;
    mail_account_uuid?: string | null;
    is_shared?: boolean;
};

/**
 * Owns one draft's lifecycle: create, debounced autosave, attachments, send, discard.
 */
export function useDraft(initial?: Draft | null) {
    const draft: Ref<Draft | null> = ref(initial ?? null);
    const saving = ref(false);
    const sending = ref(false);
    const dirty = ref(false);
    const lastSavedAt = ref<string | null>(initial?.last_saved_at ?? null);
    const pending = ref<DraftPatch>({});

    const flush = async (): Promise<void> => {
        if (!draft.value || Object.keys(pending.value).length === 0) {
            return;
        }

        const patch = pending.value;
        pending.value = {};
        saving.value = true;

        try {
            const data = await api.patch<{ draft: Draft }>(`/drafts/${draft.value.uuid}`, patch as Record<string, unknown>);
            draft.value = { ...draft.value, ...data.draft, html_body: draft.value.html_body };
            lastSavedAt.value = data.draft.last_saved_at;
            dirty.value = Object.keys(pending.value).length > 0;
        } catch (error) {
            pending.value = { ...patch, ...pending.value };
            reportError(error, 'Could not save draft.');
        } finally {
            saving.value = false;
        }
    };

    const debouncedFlush = useDebounceFn(flush, 900);

    const create = async (params: { inbox: string; conversation?: string | null; kind: DraftKind; in_reply_to?: string | null; is_shared?: boolean }) => {
        const data = await api.post<{ draft: Draft }>('/drafts', params as Record<string, unknown>);
        draft.value = data.draft;
        lastSavedAt.value = data.draft.last_saved_at;
        dirty.value = false;

        return data.draft;
    };

    const update = (patch: DraftPatch) => {
        if (!draft.value) {
            return;
        }

        Object.assign(draft.value, patch);
        pending.value = { ...pending.value, ...patch };
        dirty.value = true;
        void debouncedFlush();
    };

    const upload = async (file: File, inline = false): Promise<DraftAttachment | null> => {
        if (!draft.value) {
            return null;
        }

        const form = new FormData();
        form.append('file', file);
        form.append('inline', inline ? '1' : '0');

        try {
            const data = await api.post<{ attachment: DraftAttachment }>(`/drafts/${draft.value.uuid}/attachments`, form);
            draft.value.attachments = [...draft.value.attachments, data.attachment];

            return data.attachment;
        } catch (error) {
            reportError(error, 'Upload failed.');

            return null;
        }
    };

    const removeAttachment = async (attachment: DraftAttachment) => {
        if (!draft.value) {
            return;
        }

        draft.value.attachments = draft.value.attachments.filter((a) => a.uuid !== attachment.uuid);

        try {
            await api.delete(`/drafts/${draft.value.uuid}/attachments/${attachment.uuid}`);
        } catch (error) {
            reportError(error);
        }
    };

    const send = async (then: 'open' | 'close' | 'pending' = 'open'): Promise<{ message: Message; conversation_uuid: string } | null> => {
        if (!draft.value) {
            return null;
        }

        await flush();
        sending.value = true;

        try {
            const data = await api.post<{ message: Message; conversation_uuid: string }>(`/drafts/${draft.value.uuid}/send`, { then });
            draft.value = null;
            toast.success('Sending…', { description: 'We will let you know if delivery fails.' });

            return data;
        } catch (error) {
            reportError(error, 'Could not send.');

            return null;
        } finally {
            sending.value = false;
        }
    };

    const discard = async () => {
        if (!draft.value) {
            return;
        }

        const uuid = draft.value.uuid;
        draft.value = null;
        pending.value = {};

        try {
            await api.delete(`/drafts/${uuid}`);
        } catch (error) {
            reportError(error);
        }
    };

    const savedLabel = computed(() => {
        if (saving.value) {
            return 'Saving…';
        }

        if (dirty.value) {
            return 'Unsaved changes';
        }

        return lastSavedAt.value ? 'Saved' : '';
    });

    watch(draft, (value) => {
        if (!value) {
            pending.value = {};
            dirty.value = false;
        }
    });

    return { draft, saving, sending, dirty, savedLabel, create, update, flush, upload, removeAttachment, send, discard };
}
