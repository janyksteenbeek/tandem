import type { InboxRole, InboxType, MailProvider, SenderIdentity } from '@/types/mail';

export type MailAccountStatus = 'pending' | 'connected' | 'syncing' | 'error' | 'needs_reauth' | 'disconnected';

export type ImapConnectionSettings = {
    host: string;
    port: number;
    encryption: ImapEncryption;
    username: string;
    validate_cert?: boolean;
    append_sent?: boolean;
};

export type ImapEncryption = 'ssl' | 'tls' | 'starttls' | 'none';

export type MailAccountSettings = {
    imap?: ImapConnectionSettings;
    smtp?: ImapConnectionSettings;
    [key: string]: unknown;
};

export type MailAccountSummary = {
    uuid: string;
    provider: MailProvider;
    provider_label: string;
    email: string;
    display_name: string | null;
    status: MailAccountStatus;
    status_label: string;
    healthy: boolean;
    last_synced_at: string | null;
    last_error: string | null;
    received_count: number;
};

export type MailAccount = MailAccountSummary & {
    last_sync_started_at: string | null;
    initial_sync_completed_at: string | null;
    watch_expires_at: string | null;
    error_count: number;
    inbox: { uuid: string; name: string; type: InboxType };
    settings: MailAccountSettings;
    identities: SenderIdentity[];
    inbound_token_set: boolean;
};

export type SharedInboxChannels = {
    uuid: string;
    name: string;
    color: string;
    email_address: string | null;
    accounts: MailAccount[];
};

export type SyncRun = {
    id: number;
    type: string;
    status: string;
    started_at: string;
    finished_at: string | null;
    duration_ms: number | null;
    stats: Record<string, number | string> | null;
    error: string | null;
};

export type MailFolder = {
    id: number;
    name: string;
    type: string;
    sync_enabled: boolean;
    last_synced_at: string | null;
    message_count: number;
};

export type AccountHealth = {
    account: MailAccount;
    runs: SyncRun[];
    queue: { pending: number };
    folders: MailFolder[];
};

export type ConnectionTestResponse = {
    ok: boolean;
    message: string | null;
    details: Record<string, unknown>;
};

export type MemberOption = {
    id: number;
    name: string;
    email: string;
    initials: string;
    avatar: string | null;
};

export type TeamOption = {
    id: number;
    name: string;
    slug: string;
    color: string;
};

export type InboxTeamGrant = {
    id: number;
    name: string;
    color: string;
    role: InboxRole;
};

export type InboxUserGrant = MemberOption & { role: InboxRole };

export type InboxSettings = {
    uuid: string;
    name: string;
    slug: string;
    color: string;
    description: string | null;
    email_address: string | null;
    can_manage: boolean;
    teams: InboxTeamGrant[];
    users: InboxUserGrant[];
    accounts: MailAccountSummary[];
    conversation_count: number;
};

export type InboxRoleOption = { value: InboxRole; label: string };

export type TeamRole = 'lead' | 'member';

export type TeamMemberSettings = MemberOption & { role: TeamRole };

export type TeamSettings = {
    id: number;
    slug: string;
    name: string;
    color: string;
    description: string | null;
    members: TeamMemberSettings[];
    inboxes: { uuid: string; name: string; color: string }[];
};

export type OrganizationSummary = { id: number; name: string; slug: string };

export type BusinessHoursRange = { start: string; end: string };

export type BusinessHoursDay = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun';

export type BusinessHours = {
    timezone: string | null;
    days: Record<BusinessHoursDay, BusinessHoursRange[]>;
    holidays: string[];
};

export type OutboundEncryption = 'tls' | 'ssl' | 'none';

export type OutboundSmtpSettings = {
    host: string | null;
    port: number;
    encryption: OutboundEncryption;
    username: string | null;
    password_set: boolean;
    from_name: string | null;
};

export type IntegrationOrganization = OrganizationSummary & {
    timezone: string;
    google_oauth_client_id: string | null;
    google_oauth_client_secret_set: boolean;
    google_oauth_client_secret_hint: string | null;
    google_pubsub_topic: string | null;
    business_hours: Partial<BusinessHours> | null;
    outbound: OutboundSmtpSettings;
};

export type GoogleIntegrationInfo = {
    redirect_uri: string;
    push_endpoint: string;
    scopes: string[];
    env_fallback: boolean;
};
