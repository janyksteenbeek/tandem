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
        tagline:
            'Sign in with Google. Labels, threads and push notifications come along for free.',
    },
    imap: {
        label: 'IMAP / SMTP',
        icon: Server,
        tone: 'chip-sky',
        tagline:
            'Any other mailbox. We poll for new mail every couple of minutes and keep folders in sync.',
    },
    google_group: {
        label: 'Google Group',
        icon: GoogleGroupsIcon,
        tone: 'chip-slate',
        tagline:
            'Add a generated address as a member of your group. No passwords, no admin hand-over.',
    },
    forwarding: {
        label: 'Forward',
        icon: Forward,
        tone: 'chip-teal',
        tagline:
            'Point an existing address, alias or distribution list at a generated one.',
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
export const CHANNEL_INTRO: Record<ForwardingChannel, string> = {
    google_group:
        'Tandem generates a private address and you add it to the group as a member. Google delivers every post to this inbox and your group settings stay exactly as they are.',
    forwarding:
        'Tandem generates a private address. Create a forwarding rule at your current provider, or add the address to a distribution list, and a copy of every message lands here.',
};

/** What to do with the generated address, shown once it exists. */
export const CHANNEL_STEPS: Record<ForwardingChannel, string[]> = {
    google_group: [
        'Open the Google Admin console, go to Directory, then Groups, and pick the group customers write to.',
        'Under Members choose Add members, paste the address above and add it as a Member.',
        'In Access settings allow external members and let anyone on the web publish posts, so people outside your organization can email the group.',
        'Google sends the new member a confirmation. It lands in this inbox, so there is nothing to click.',
    ],
    forwarding: [
        'Copy the address above.',
        'At your current provider add a forwarding rule that sends a copy of incoming mail there, or add the address to your distribution list.',
        'Gmail and Outlook first email a confirmation to the new address. It lands in this inbox; open it and confirm.',
        'Press Send test email and watch it arrive within a minute.',
    ],
};
