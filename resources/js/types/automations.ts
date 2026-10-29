export type CatalogOption = { value: string; label: string };

export type ConditionValueType = 'inbox' | 'email' | 'text' | 'tag' | 'user' | 'boolean' | 'status' | 'priority';

export type OperatorValueShape = 'string' | 'array' | 'none';

export type CatalogField = {
    value: string;
    label: string;
    value_type: ConditionValueType;
    operators: string[];
};

export type CatalogOperator = {
    value: string;
    label: string;
    value_shape: OperatorValueShape;
};

export type CatalogActionParam = {
    name: string;
    type: string;
    required: boolean;
    options?: CatalogOption[];
};

export type CatalogAction = {
    value: string;
    label: string;
    params: CatalogActionParam[];
};

export type TemplateVariable = { key: string; label: string };

export type AutomationCatalog = {
    triggers: CatalogOption[];
    match: CatalogOption[];
    fields: CatalogField[];
    operators: CatalogOperator[];
    actions: CatalogAction[];
    statuses: CatalogOption[];
    priorities: CatalogOption[];
    strategies: CatalogOption[];
    variables: TemplateVariable[];
    default_cooldown_minutes: number;
};

export type AutomationMatch = 'all' | 'any';

export type AutomationConditionValue = string | string[] | null;

export type AutomationCondition = {
    field: string;
    operator: string;
    value: AutomationConditionValue;
};

export type AutomationParamValue = string | number | boolean | null | (string | number)[];

export type AutomationActionParams = Record<string, AutomationParamValue>;

export type AutomationAction = {
    type: string;
    params: AutomationActionParams;
};

export type AutomationInboxRef = { uuid: string; name: string };

export type AutomationRuleSummary = {
    uuid: string;
    name: string;
    description: string | null;
    trigger: CatalogOption;
    match: AutomationMatch;
    is_active: boolean;
    position: number;
    cooldown_minutes: number | null;
    run_count: number;
    last_run_at: string | null;
    inbox: AutomationInboxRef | null;
    condition_count: number;
    action_types: string[];
    created_at: string | null;
    updated_at: string | null;
};

export type AutomationRuleDetail = AutomationRuleSummary & {
    inbox_uuid: string | null;
    conditions: AutomationCondition[];
    actions: AutomationAction[];
};

export type AutomationRunStatus = 'matched' | 'skipped' | 'failed';

export type AutomationRunConditionResult = {
    field: string;
    operator: string;
    value: AutomationConditionValue;
    actual: unknown;
    passed: boolean;
    error?: string | null;
};

export type AutomationRunActionResult = {
    type: string;
    status: 'done' | 'skipped' | 'sent' | string;
    reason?: string | null;
    [key: string]: unknown;
};

export type AutomationRunDetails = {
    match?: AutomationMatch;
    reason?: string;
    error?: string;
    exception?: string;
    conditions?: AutomationRunConditionResult[];
    actions?: AutomationRunActionResult[];
    [key: string]: unknown;
};

export type AutomationRun = {
    id: number;
    trigger: string;
    status: AutomationRunStatus;
    details: AutomationRunDetails | null;
    conversation: { uuid: string; subject: string | null } | null;
    created_at: string;
};

export type AutomationRunPage = {
    data: AutomationRun[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
};

export type AutomationInboxOption = { uuid: string; name: string; color: string | null };

export type AutomationTagOption = { id: number; name: string; slug: string; color: string | null };

export type AutomationUserOption = { id: number; name: string; avatar: string | null };

export type AutomationTeamOption = { id: number; name: string; color: string | null; member_count: number };

export type AutomationTemplateOption = {
    id: number;
    name: string;
    category: string | null;
    subject: string | null;
    inbox: AutomationInboxRef | null;
};

export type AutomationLookups = {
    inboxes: AutomationInboxOption[];
    tags: AutomationTagOption[];
    users: AutomationUserOption[];
    teams: AutomationTeamOption[];
    templates: AutomationTemplateOption[];
};

export type AutomationRuleForm = {
    name: string;
    description: string;
    inbox_uuid: string | null;
    trigger: string;
    match: AutomationMatch;
    is_active: boolean;
    cooldown_minutes: number | null;
    conditions: AutomationCondition[];
    actions: AutomationAction[];
};

export type DryRunResult = {
    rule: { uuid: string; name: string };
    conversation: { uuid: string; subject: string | null };
    matched: boolean;
    match: AutomationMatch;
    conditions: AutomationRunConditionResult[];
    would_run: boolean;
};

export type MessageTemplate = {
    id: number;
    name: string;
    category: string | null;
    subject: string | null;
    body_html: string;
    inbox: AutomationInboxRef | null;
    inbox_uuid: string | null;
    created_at: string | null;
    updated_at: string | null;
};

export type TemplatePreview = {
    conversation: { uuid: string; subject: string | null } | null;
    subject: string | null;
    body_html: string;
    variables: Record<string, string>;
};
