import { Forward, Server } from '@lucide/vue';
import type { Component } from 'vue';
import GoogleGroupsIcon from '@/components/icons/GoogleGroupsIcon.vue';
import GoogleIcon from '@/components/icons/GoogleIcon.vue';
import type { ForwardingChannel, MailProvider } from '@/types';

export type ProviderMeta = {
    label: string;
    /** Lucide icon or an inline brand SVG component; both accept a class for sizing. */
    icon: Component;
    tone: string;
    /** One-line pitch shown on the channel picker card. */
    tagline: string;
};

export const PROVIDER_META: Record<MailProvider, ProviderMeta> = {
    gmail: {
        label: 'Gmail',
        icon: GoogleIcon,
        tone: 'chip-slate',
        tagline: 'Sign in with Google.',
    },
    imap: {
        label: 'IMAP / SMTP',
        icon: Server,
        tone: 'chip-sky',
        tagline: 'Any other mailbox.',
    },
    google_group: {
        label: 'Google Group',
        icon: GoogleGroupsIcon,
        tone: 'chip-slate',
        tagline: 'Add a generated address to your group.',
    },
    forwarding: {
        label: 'Forward',
        icon: Forward,
        tone: 'chip-teal',
        tagline: 'Forward an existing address here.',
    },
};

/**
 * Channels that receive mail on a generated address of the forwarding domain instead of
 * connecting to a mailbox. Order matters: it is the order they are offered in.
 */
export const FORWARDING_CHANNELS: ForwardingChannel[] = [
    'google_group',
    'forwarding',
];

export function usesForwardingAddress(
    provider: MailProvider,
): provider is ForwardingChannel {
    return (FORWARDING_CHANNELS as MailProvider[]).includes(provider);
}

/** Why the user would pick this channel, shown above the generate button. */
export function channelIntro(
    channel: ForwardingChannel,
    appName: string,
): string {
    switch (channel) {
        case 'google_group':
            return `${appName} generates a private address. Add it to the group as a member.`;
        case 'forwarding':
            return `${appName} generates a private address. Forward your mail to it.`;
    }
}

/** What to do with the generated address, shown once it exists. */
export const CHANNEL_STEPS: Record<ForwardingChannel, string[]> = {
    google_group: [
        'In the Google Admin console, open Directory → Groups and pick the group.',
        'Add the address above as a Member.',
        'In Access settings, allow external members and let anyone post.',
    ],
    forwarding: [
        'At your current provider, forward incoming mail to the address above.',
        'If a confirmation email arrives in this inbox, open it and confirm.',
        'Press Send test email to check.',
    ],
};
