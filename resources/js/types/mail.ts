export type EmailAddress = { name: string | null; email: string };

export type UserSummary = {
    id: number;
    name: string;
    email: string;
    initials: string;
    avatar: string | null;
};

export type Tag = { id: number; name: string; slug: string; color: string };

export type ConversationStatus = 'open' | 'pending' | 'snoozed' | 'closed';
export type ConversationPriority = 'none' | 'low' | 'normal' | 'high' | 'urgent';
export type MailProvider = 'gmail' | 'imap' | 'forwarding';
export type InboxType = 'personal' | 'shared';
export type InboxRole = 'admin' | 'agent' | 'viewer';

export type ConversationListItem = {
    uuid: string;
    inbox_id: number;
    inbox_uuid: string;
    inbox_name: string;
    shared_inbox_ids: number[];
    subject: string | null;
    snippet: string | null;
    status: ConversationStatus;
    priority: ConversationPriority;
    is_read: boolean;
    is_starred: boolean;
    in_inbox: boolean;
    is_archived: boolean;
    is_trashed: boolean;
    is_spam: boolean;
    unread_count: number;
    message_count: number;
    comment_count: number;
    has_attachments: boolean;
    has_draft: boolean;
    snoozed_until: string | null;
    last_message_at: string | null;
    last_activity_at: string | null;
    last_direction: 'inbound' | 'outbound';
    counterpart: EmailAddress | null;
    participants: EmailAddress[];
    assignee: UserSummary | null;
    tags: Tag[];
};

export type Attachment = {
    uuid: string;
    filename: string;
    mime_type: string;
    size_bytes: number;
    content_id: string | null;
    is_inline: boolean;
    is_image: boolean;
    url: string;
    download_url: string;
};

export type DeliveryStatus = 'none' | 'queued' | 'sending' | 'sent' | 'failed' | 'bounced';

export type Message = {
    uuid: string;
    direction: 'inbound' | 'outbound';
    from: EmailAddress | null;
    to: EmailAddress[];
    cc: EmailAddress[];
    bcc: EmailAddress[];
    reply_to: EmailAddress[];
    subject: string | null;
    snippet: string | null;
    html_body: string | null;
    text_body: string | null;
    sent_at: string | null;
    is_read: boolean;
    is_draft: boolean;
    is_starred: boolean;
    has_attachments: boolean;
    delivery_status: DeliveryStatus;
    delivery_error: string | null;
    author: UserSummary | null;
    attachments: Attachment[];
    message_id: string | null;
};

export type Comment = {
    uuid: string;
    body: string;
    mentions: number[];
    user: UserSummary;
    created_at: string;
    edited_at: string | null;
};

export type ConversationEvent = {
    id: number;
    type: string;
    payload: Record<string, unknown>;
    actor: UserSummary | null;
    created_at: string;
};

export type DraftAttachment = {
    uuid: string;
    filename: string;
    mime_type: string;
    size_bytes: number;
    is_inline: boolean;
    content_id?: string | null;
    url?: string | null;
};

export type DraftKind = 'new' | 'reply' | 'reply_all' | 'forward';

export type Draft = {
    uuid: string;
    conversation_uuid: string | null;
    inbox_uuid: string;
    kind: DraftKind;
    mail_account_uuid: string | null;
    sender_identity_id: number | null;
    in_reply_to_uuid: string | null;
    to: EmailAddress[];
    cc: EmailAddress[];
    bcc: EmailAddress[];
    subject: string | null;
    html_body: string | null;
    is_shared: boolean;
    user: UserSummary;
    locked_by: UserSummary | null;
    attachments: DraftAttachment[];
    last_saved_at: string | null;
    updated_at: string | null;
};

export type ConversationShare = {
    inbox_uuid: string;
    inbox_name: string;
    shared_by: UserSummary | null;
    note: string | null;
    created_at: string | null;
};

export type Conversation = ConversationListItem & {
    organization_id: number | null;
    mail_account: { uuid: string; email: string; provider: MailProvider } | null;
    origin_inbox: { uuid: string; name: string } | null;
    shares: ConversationShare[];
    followers: UserSummary[];
    is_following: boolean;
    messages: Message[];
    comments: Comment[];
    events: ConversationEvent[];
    drafts: Draft[];
    closed_at: string | null;
    first_message_at: string | null;
    can: { act: boolean; share: boolean; move: boolean; delete: boolean };
};

export type SenderIdentity = {
    id: number;
    name: string;
    email: string;
    is_default: boolean;
    signature_html: string | null;
    reply_to?: string | null;
};

export type InboxAccount = {
    uuid: string;
    provider: MailProvider;
    email: string;
    display_name: string | null;
    status: string;
    last_synced_at: string | null;
    last_error: string | null;
    identities: SenderIdentity[];
};

export type Inbox = {
    id: number;
    uuid: string;
    type: InboxType;
    name: string;
    slug: string;
    color: string;
    icon: string | null;
    description?: string | null;
    email_address: string | null;
    organization_id?: number | null;
    role: InboxRole | null;
    accounts: InboxAccount[];
};

export type NavInbox = {
    id: number;
    uuid: string;
    type: InboxType;
    name: string;
    email: string | null;
    color: string;
    icon: string | null;
    count: number;
    providers: MailProvider[];
    health: 'ok' | 'attention';
};

export type MailNav = {
    personal: NavInbox[];
    shared: NavInbox[];
    assigned_to_me: number;
    personal_unread: number;
};

export type ViewOption = { value: string; label: string };

export type SavedView = {
    id: number;
    name: string;
    icon: string | null;
    filters: Record<string, unknown>;
    is_shared: boolean;
    inbox_id: number | null;
};

export type ShareTarget = { uuid: string; name: string; color: string; email: string | null };

export type ConversationFilters = {
    q?: string;
    assignee?: string;
    status?: string;
    priority?: string;
    tag?: string | string[];
    unread?: string;
    attachments?: string;
    from?: string;
    after?: string;
    before?: string;
    sort?: string;
};

export type CursorPage<T> = {
    data: T[];
    links: { first: string | null; last: string | null; prev: string | null; next: string | null };
    meta: { path: string; per_page: number; next_cursor: string | null; prev_cursor: string | null };
};

export type AppNotification = {
    id: string;
    data: {
        kind: 'assigned' | 'mention' | 'comment' | 'reply' | 'automation' | string;
        title: string;
        body: string | null;
        conversation_uuid?: string;
        inbox_uuid?: string;
        actor?: { id: number; name: string } | null;
    };
    read_at: string | null;
    created_at: string;
};
