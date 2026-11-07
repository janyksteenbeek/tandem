import type { Tag, UserSummary } from '@/types';

export type IssuePriority = 'none' | 'low' | 'medium' | 'high' | 'urgent';
export type StatusCategory =
    | 'backlog'
    | 'unstarted'
    | 'started'
    | 'completed'
    | 'canceled';
export type EstimateScale = 'none' | 'fibonacci' | 'linear' | 'tshirt';

export type WorkflowStatus = {
    id: number;
    uuid: string;
    workflow_id: number;
    name: string;
    color: string;
    icon: string | null;
    category: StatusCategory;
    position: number;
    is_default: boolean;
};

export type Workflow = {
    id: number;
    uuid: string;
    kind: 'issue' | 'team';
    name: string;
    team_id: number | null;
    product_id: number | null;
    auto_start: boolean;
    auto_complete: boolean;
    statuses: WorkflowStatus[];
    transitions: { from: number; to: number }[];
};

export type TeamSummary = {
    id: number;
    slug: string;
    name: string;
    color: string;
    icon: string | null;
};

export type TeamMemberSummary = UserSummary & { role: 'lead' | 'member' };

export type CycleSettings = {
    enabled: boolean;
    length_weeks: number;
    cooldown_weeks: number;
    start_weekday: number;
    upcoming_count: number;
    auto_carry_over: boolean;
};

export type Team = TeamSummary & {
    description: string | null;
    members: TeamMemberSummary[];
    is_member: boolean;
    cycle_settings: CycleSettings;
    estimate_scale: EstimateScale;
    active_cycle: CycleSummary | null;
    can: { lead: boolean; manage: boolean };
};

export type ProductSummary = {
    id: number;
    uuid: string;
    slug: string;
    name: string;
    key: string;
    color: string;
    icon: string | null;
    status: 'active' | 'archived';
    issues_count: number;
    open_issues_count: number;
};

export type ModuleSummary = {
    id: number;
    uuid: string;
    slug: string;
    name: string;
    description?: string | null;
    icon: string | null;
    color: string | null;
    product_id: number;
    parent_id: number | null;
    position: number;
    issues_count: number;
    open_issues_count: number;
    owner?: UserSummary | null;
    teams?: TeamSummary[];
    children?: ModuleSummary[];
};

export type Label = Tag & { product_id?: number | null };

export type EntityLink = { id: number; title: string; url: string };

export type Product = ProductSummary & {
    description: string | null;
    owner: UserSummary | null;
    teams: TeamSummary[];
    members: (UserSummary & { role: 'admin' | 'member' })[];
    modules: ModuleSummary[];
    labels: Label[];
    links: EntityLink[];
    issue_workflow_id: number | null;
    created_at: string | null;
    can: {
        update: boolean;
        manage_members: boolean;
        manage_workflow: boolean;
        delete: boolean;
    };
};

export type ProjectStatus =
    | 'backlog'
    | 'planned'
    | 'in_progress'
    | 'paused'
    | 'completed'
    | 'canceled';
export type ProjectHealth = 'unknown' | 'on_track' | 'at_risk' | 'off_track';

export type ProjectSummary = {
    id: number;
    uuid: string;
    slug: string;
    name: string;
    color: string;
    icon: string | null;
    status: ProjectStatus;
    health: ProjectHealth;
    priority: number;
    visibility: 'private' | 'organization';
    start_date: string | null;
    target_date: string | null;
    completed_at: string | null;
    lead: UserSummary | null;
    product: ProductSummary | null;
    teams: TeamSummary[];
    /** 0..1 */
    progress: number;
    counts: {
        issues: number;
        completed: number;
        started: number;
        canceled: number;
    };
};

export type Milestone = {
    id: number;
    uuid: string;
    name: string;
    description: string | null;
    target_date: string | null;
    status: 'planned' | 'active' | 'completed' | 'missed';
    position: number;
    project_id: number | null;
    initiative_id: number | null;
    issues_count: number;
    completed_issues_count: number;
    progress: number;
};

export type ProjectProgressDetail = {
    total: number;
    backlog: number;
    unstarted: number;
    started: number;
    completed: number;
    canceled: number;
    percent: number;
    estimate_total: number;
    estimate_done: number;
    by_team: {
        team_id: number;
        team: string;
        workstreams: number;
        completed: number;
    }[];
    computed_at: string;
};

export type Project = ProjectSummary & {
    description_html: string | null;
    members: (UserSummary & { role: 'lead' | 'member' })[];
    modules: ModuleSummary[];
    milestones: Milestone[];
    initiatives: {
        id: number;
        uuid: string;
        slug: string;
        name: string;
        color: string;
    }[];
    links: EntityLink[];
    progress_detail: ProjectProgressDetail | null;
    created_at: string | null;
    can: { update: boolean; manage_members: boolean; delete: boolean };
};

export type CycleSummary = {
    id: number;
    uuid: string;
    team_id: number;
    number: number;
    name: string;
    goals: string | null;
    starts_at: string;
    ends_at: string;
    status: 'upcoming' | 'active' | 'completed';
    started_at: string | null;
    completed_at: string | null;
    workstreams_count: number;
    completed_workstreams_count: number;
    carried_over_count: number;
    progress: number;
    team?: TeamSummary;
};

export type Release = {
    id: number;
    uuid: string;
    name: string;
    version: string | null;
    status: 'planned' | 'in_progress' | 'released' | 'canceled';
    planned_at: string | null;
    released_at: string | null;
    issues_count: number;
    completed_issues_count: number;
};

export type Workstream = {
    id: number;
    uuid: string;
    issue_id: number;
    team: TeamSummary;
    responsibility: string | null;
    status: WorkflowStatus;
    assignees: UserSummary[];
    estimate: number | null;
    due_date: string | null;
    is_required: boolean;
    cycle: CycleSummary | null;
    blocked_by_count: number;
    position: number;
    started_at: string | null;
    completed_at: string | null;
    updated_at: string | null;
};

export type IssueTeam = TeamSummary & {
    status_category: StatusCategory;
    status_color: string;
    status_name: string;
    blocked: boolean;
};

export type IssueRef = {
    uuid: string;
    identifier: string;
    title: string;
    status_category?: StatusCategory;
    status?: WorkflowStatus | null;
};

export type IssueListItem = {
    uuid: string;
    identifier: string;
    number: number;
    title: string;
    status: WorkflowStatus;
    status_category: StatusCategory;
    is_ready_to_complete: boolean;
    suggested_status_id: number | null;
    priority: IssuePriority;
    owner: UserSummary | null;
    lead_team: TeamSummary | null;
    /** Union of every workstream's assignees, deduplicated. */
    assignees: UserSummary[];
    teams: IssueTeam[];
    /** Present on team pages: that team's workstream. */
    workstream: Workstream | null;
    product: ProductSummary | null;
    project: ProjectSummary | null;
    modules: { id: number; slug: string; name: string; color: string | null }[];
    milestone: { id: number; uuid: string; name: string } | null;
    labels: Label[];
    estimate: number | null;
    start_date: string | null;
    due_date: string | null;
    parent: IssueRef | null;
    counts: {
        sub_issues: number;
        sub_completed: number;
        comments: number;
        attachments: number;
        links: number;
        workstreams: number;
        blocked_by: number;
        blocked_workstreams: number;
    };
    sort_order: number | null;
    started_at: string | null;
    completed_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    last_activity_at: string | null;
};

export type IssueRelationKind =
    | 'blocks'
    | 'blocked_by'
    | 'related'
    | 'duplicate';

export type IssueRelation = {
    id: number;
    kind: IssueRelationKind;
    issue: IssueRef;
};

export type WorkstreamDependencyRow = {
    id: number | null;
    workstream_uuid: string;
    team: TeamSummary;
    kind: 'issue' | 'workstream';
    issue: IssueRef | null;
    depends_on_team: TeamSummary | null;
    resolved: boolean;
};

export type IssueAttachment = {
    uuid: string;
    filename: string;
    mime_type: string;
    size_bytes: number;
    is_image: boolean;
    url: string;
    download_url: string;
    uploaded_by: UserSummary | null;
    source: { conversation_id: number } | null;
    created_at: string | null;
};

export type Reaction = {
    emoji: string;
    count: number;
    users: { id: number | undefined; name: string | undefined }[];
    mine: boolean;
};

export type IssueComment = {
    uuid: string;
    parent_uuid: string | null;
    body: string;
    mentions: number[];
    user: UserSummary | null;
    reactions: Reaction[];
    attachments: IssueAttachment[];
    created_at: string | null;
    edited_at: string | null;
    can: { update: boolean; delete: boolean };
    /** Set client-side for optimistic inserts. */
    client_id?: string;
};

export type Activity = {
    id: number;
    type: string;
    payload: Record<string, unknown>;
    actor: UserSummary | null;
    team: TeamSummary | null;
    created_at: string;
    issue?: IssueRef;
};

export type CompletionReadiness = {
    total: number;
    required: number;
    required_completed: number;
    required_canceled: number;
    completed: number;
    started: number;
    blocked: number;
    ready: boolean;
    suggested: StatusCategory | null;
};

export type SharedContent = {
    uuid: string;
    kind: 'excerpt' | 'attachment';
    body_html: string | null;
    attachment: IssueAttachment | null;
    created_at: string;
};

export type LinkedConversation = {
    uuid: string;
    inbox_uuid: string;
    inbox_name: string;
    inbox_color: string;
    subject: string | null;
    snippet: string | null;
    status: string;
    counterpart: { name: string | null; email: string } | null;
    last_activity_at: string | null;
    kind: 'linked' | 'created_from';
    note: string | null;
    linked_by: UserSummary | null;
    linked_at: string | null;
};

export type LinkedConversations = {
    conversations: LinkedConversation[];
    hidden_count: number;
};

export type Issue = IssueListItem & {
    description_html: string | null;
    workstreams: Workstream[];
    workstream_dependencies: WorkstreamDependencyRow[];
    readiness: CompletionReadiness;
    suggested_status: WorkflowStatus | null;
    status_overridden_at: string | null;
    sub_issues: IssueListItem[];
    relations: IssueRelation[];
    attachments: IssueAttachment[];
    comments: IssueComment[];
    shared_content: SharedContent[];
    followers: UserSummary[];
    is_following: boolean;
    created_by: UserSummary | null;
    links: EntityLink[];
    link_count: number;
    canceled_at: string | null;
    can: {
        update: boolean;
        set_status: boolean;
        override_completion: boolean;
        manage_teams: boolean;
        comment: boolean;
        move: boolean;
        delete: boolean;
        link_conversations: boolean;
    };
};

export type FilterOperator =
    | 'in'
    | 'not_in'
    | 'is_empty'
    | 'is_not_empty'
    | 'is_true'
    | 'is_false'
    | 'before'
    | 'after'
    | 'between'
    | 'overdue'
    | 'eq'
    | 'gt'
    | 'lt'
    | 'contains'
    | 'not_contains';

export type FilterField =
    | 'status'
    | 'status_category'
    | 'priority'
    | 'owner'
    | 'assignee'
    | 'team'
    | 'lead_team'
    | 'product'
    | 'module'
    | 'project'
    | 'milestone'
    | 'cycle'
    | 'release'
    | 'label'
    | 'parent'
    | 'created_by'
    | 'follower'
    | 'blocked'
    | 'ready_to_complete'
    | 'has_sub_issues'
    | 'due_date'
    | 'start_date'
    | 'created_at'
    | 'updated_at'
    | 'completed_at'
    | 'estimate'
    | 'title';

export type FilterClause = {
    field: FilterField;
    op: FilterOperator;
    value: string | number | (string | number)[] | null;
};

export type FilterGroup = { match: 'all' | 'any'; clauses: FilterClause[] };

export type IssueFilters = {
    q?: string | null;
    match: 'all' | 'any';
    groups: FilterGroup[];
};

export type IssueViewMode = 'list' | 'board' | 'calendar' | 'timeline';
export type IssueGroupBy =
    | 'none'
    | 'status'
    | 'status_category'
    | 'priority'
    | 'owner'
    | 'assignee'
    | 'team'
    | 'project'
    | 'product'
    | 'module'
    | 'cycle'
    | 'label'
    | 'milestone'
    | 'due_date';
export type IssueSortField =
    | 'manual'
    | 'priority'
    | 'status'
    | 'created_at'
    | 'updated_at'
    | 'due_date'
    | 'title'
    | 'estimate'
    | 'identifier'
    | 'last_activity_at';
export type IssueSort = { field: IssueSortField; dir: 'asc' | 'desc' };
export type IssueColumn =
    | 'priority'
    | 'identifier'
    | 'status'
    | 'title'
    | 'labels'
    | 'product'
    | 'module'
    | 'project'
    | 'teams'
    | 'cycle'
    | 'estimate'
    | 'due_date'
    | 'assignees'
    | 'created_at'
    | 'updated_at';

export type IssueDisplay = {
    mode: IssueViewMode;
    group_by: IssueGroupBy;
    sort: IssueSort;
    show_completed: 'all' | 'recent' | 'none';
    columns: IssueColumn[] | null;
};

export type IssueView = {
    id: number;
    uuid: string;
    name: string;
    icon: string | null;
    color: string | null;
    scope: 'personal' | 'team' | 'organization';
    team_id: number | null;
    filters: IssueFilters | null;
    display: Partial<IssueDisplay> | null;
    can_manage?: boolean;
};

export type IssueContainer = {
    type:
        | 'organization'
        | 'product'
        | 'module'
        | 'project'
        | 'team'
        | 'cycle'
        | 'view'
        | 'my'
        | 'release'
        | 'milestone';
    id: number | string | null;
    name: string;
    url: string;
};

export type WorkflowLookup = {
    id: number;
    kind: 'issue' | 'team';
    team_id: number | null;
    product_id: number | null;
    statuses: WorkflowStatus[];
};

export type IssueLookups = {
    members: UserSummary[];
    teams: (TeamSummary & {
        workflow_id: number | null;
        estimate_scale: EstimateScale;
    })[];
    products: (ProductSummary & { workflow_id: number })[];
    modules: ModuleSummary[];
    projects: ProjectSummary[];
    milestones: Milestone[];
    cycles: CycleSummary[];
    labels: Label[];
    statuses: WorkflowStatus[];
    default_workflow_id: number;
    workflows: Record<number, WorkflowLookup>;
    priorities: { value: IssuePriority; label: string }[];
    categories: { value: StatusCategory; label: string }[];
    team_ids: number[];
};

export type BoardColumn = {
    status_id: number;
    issues: IssueListItem[];
    total: number;
    next_cursor: string | null;
};

export type MyIssuesBucket =
    | 'assigned'
    | 'owned'
    | 'workstreams'
    | 'created'
    | 'subscribed'
    | 'due_soon'
    | 'overdue'
    | 'completed'
    | 'recent';

export type NavEntity = {
    type:
        | 'product'
        | 'module'
        | 'project'
        | 'team'
        | 'cycle'
        | 'issue'
        | 'view';
    key: string;
    id: number;
    name: string;
    identifier?: string;
    color: string | null;
    icon: string | null;
    url: string;
};

export type ProjectsNav = {
    counts: {
        assigned: number;
        owned: number;
        due_today: number;
        triage: number;
    };
    favorites: NavEntity[];
    recent: NavEntity[];
    products: (ProductSummary & {
        modules: {
            id: number;
            slug: string;
            name: string;
            color: string | null;
            parent_id: number | null;
        }[];
    })[];
    projects: ProjectSummary[];
    teams: (TeamSummary & {
        is_member: boolean;
        active_cycle: CycleSummary | null;
    })[];
    cycles: CycleSummary[];
    views: IssueView[];
    team_ids: number[];
};

/** What the global Create Issue dialog opens with. */
export type CreateIssuePrefill = {
    title?: string;
    description_html?: string;
    team_ids?: number[];
    product_id?: number | null;
    module_ids?: number[];
    project_id?: number | null;
    milestone_id?: number | null;
    parent_identifier?: string | null;
    status_id?: number | null;
    priority?: IssuePriority;
    owner_id?: number | null;
    lead_team_id?: number | null;
    assignee_ids?: number[];
    label_ids?: number[];
    due_date?: string | null;
    conversation_uuid?: string;
    conversation_subject?: string | null;
};
