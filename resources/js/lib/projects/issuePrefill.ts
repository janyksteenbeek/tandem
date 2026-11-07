import type { Conversation, CreateIssuePrefill, Message } from '@/types';

const MAX_SNIPPET = 600;

const escapeHtml = (value: string) =>
    value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

/**
 * Plain text of a message for quoting: the text body when present, otherwise the HTML body
 * with tags stripped. Quoted replies (lines starting with ">") and signatures are dropped.
 */
export function messageText(
    message: Pick<Message, 'text_body' | 'html_body' | 'snippet'>,
): string {
    const raw =
        message.text_body?.trim() ||
        (message.html_body
            ? message.html_body
                  .replace(/<style[\s\S]*?<\/style>/gi, ' ')
                  .replace(/<br\s*\/?>/gi, '\n')
                  .replace(/<\/(p|div|li|tr|h[1-6])>/gi, '\n')
                  .replace(/<[^>]+>/g, ' ')
                  .replace(/&nbsp;/g, ' ')
                  .replace(/&amp;/g, '&')
                  .replace(/&lt;/g, '<')
                  .replace(/&gt;/g, '>')
                  .replace(/&quot;/g, '"')
            : '') ||
        message.snippet?.trim() ||
        '';

    const lines = raw
        .split('\n')
        .map((line) => line.replace(/[ \t]+/g, ' ').trim());

    const kept: string[] = [];

    for (const line of lines) {
        if (
            /^(--\s*$|__+$|-----Original Message-----|On .+ wrote:$)/.test(
                line.trim(),
            )
        ) {
            break;
        }

        if (line.trim().startsWith('>')) {
            continue;
        }

        kept.push(line);
    }

    return kept
        .join('\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

/**
 * The quoted snippet placed in a new issue's description.
 */
export function quotedSnippet(
    message: Pick<
        Message,
        'text_body' | 'html_body' | 'snippet' | 'from' | 'sent_at'
    >,
    limit = MAX_SNIPPET,
): string {
    const text = messageText(message);

    if (!text) {
        return '';
    }

    const trimmed =
        text.length > limit ? `${text.slice(0, limit).trimEnd()}…` : text;
    const who = message.from?.name || message.from?.email || 'the sender';

    const paragraphs = trimmed
        .split(/\n{2,}/)
        .map((p) => `<p>${escapeHtml(p).replace(/\n/g, '<br>')}</p>`)
        .join('');

    return `<p><em>From ${escapeHtml(who)}:</em></p><blockquote>${paragraphs}</blockquote>`;
}

/**
 * Prefill for the create-issue dialog when an issue is started from a conversation: the subject
 * becomes the title, the last inbound message is quoted, and the conversation is linked on save.
 * Nothing else from the mailbox is copied.
 */
export function prefillFromConversation(
    conversation: Pick<Conversation, 'uuid' | 'subject' | 'messages'>,
    lastInbound?: Message | null,
): CreateIssuePrefill {
    const message =
        lastInbound ??
        [...conversation.messages]
            .reverse()
            .find((m) => m.direction === 'inbound' && !m.is_draft) ??
        [...conversation.messages].reverse().find((m) => !m.is_draft) ??
        null;

    const title = (conversation.subject ?? '')
        .replace(/^(\s*(re|fwd?|aw|wg)\s*:\s*)+/i, '')
        .trim();

    return {
        title: title || conversation.subject?.trim() || '',
        description_html: message ? quotedSnippet(message) : '',
        conversation_uuid: conversation.uuid,
        conversation_subject: conversation.subject ?? null,
    };
}
