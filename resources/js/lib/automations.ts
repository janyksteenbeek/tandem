import type {
    AutomationAction,
    AutomationActionParams,
    AutomationCatalog,
    AutomationCondition,
    AutomationConditionValue,
    AutomationLookups,
    AutomationMatch,
    AutomationParamValue,
    AutomationRuleForm,
    AutomationSubjectType,
    CatalogAction,
    CatalogActionParam,
    CatalogField,
    CatalogOperator,
    CatalogTrigger,
    OperatorValueShape,
} from '@/types/automations';
import type { WorkflowStatus } from '@/types/projects';

/**
 * Everything the rule builder needs to describe a rule in plain English, build sensible
 * defaults and offer starter templates. Pure functions so they are trivially testable.
 */

export const EMPTY_LOOKUPS: AutomationLookups = {
    inboxes: [],
    tags: [],
    users: [],
    teams: [],
    templates: [],
    issues: null,
};

export const SUBJECT_LABELS: Record<AutomationSubjectType, string> = {
    conversation: 'Conversation',
    issue: 'Issue',
};

export function subjectOfTrigger(
    catalog: AutomationCatalog,
    trigger: string,
): AutomationSubjectType {
    return (
        catalog.triggers.find((option) => option.value === trigger)?.subject ??
        'conversation'
    );
}

export function triggersFor(
    catalog: AutomationCatalog,
    subject: AutomationSubjectType,
): CatalogTrigger[] {
    return catalog.triggers.filter(
        (option) => (option.subject ?? 'conversation') === subject,
    );
}

export function fieldsFor(
    catalog: AutomationCatalog,
    subject: AutomationSubjectType,
): CatalogField[] {
    return catalog.fields.filter(
        (field) => (field.subject ?? 'conversation') === subject,
    );
}

export function actionsFor(
    catalog: AutomationCatalog,
    subject: AutomationSubjectType,
): CatalogAction[] {
    return catalog.actions.filter((action) =>
        (action.subjects ?? ['conversation']).includes(subject),
    );
}

export function actionSupports(
    catalog: AutomationCatalog,
    type: string,
    subject: AutomationSubjectType,
): boolean {
    const action = actionFor(catalog, type);

    return action
        ? (action.subjects ?? ['conversation']).includes(subject)
        : false;
}

/**
 * Every status an issue rule may pick from: the organization's issue workflows first, then
 * each team workflow, or only one team's workflow when a team is given.
 */
export function statusOptionsFor(
    lookups: AutomationLookups,
    teamId: number | null = null,
): (WorkflowStatus & { group: string })[] {
    const issues = lookups.issues;

    if (!issues) {
        return [];
    }

    const workflows = Object.values(issues.workflows);

    if (teamId !== null) {
        const team = issues.teams.find((candidate) => candidate.id === teamId);
        const workflow = workflows.find(
            (candidate) =>
                candidate.id === team?.workflow_id ||
                candidate.team_id === teamId,
        );

        return (workflow?.statuses ?? []).map((status) => ({
            ...status,
            group: team?.name ?? 'Team',
        }));
    }

    return workflows
        .filter((workflow) => workflow.kind === 'issue')
        .flatMap((workflow) =>
            workflow.statuses.map((status) => ({
                ...status,
                group:
                    workflow.id === issues.default_workflow_id
                        ? 'Default'
                        : (issues.products.find(
                              (product) => product.workflow_id === workflow.id,
                          )?.name ?? 'Product'),
            })),
        );
}

const TRIGGER_PHRASES: Record<string, string> = {
    conversation_received: 'a new conversation arrives',
    reply_received: 'a reply arrives',
    conversation_assigned: 'a conversation is assigned',
    conversation_moved: 'a conversation is moved',
    conversation_reopened: 'a conversation is reopened',
    conversation_closed: 'a conversation is closed',
    tag_added: 'a tag is added',
    business_hours_started: 'business hours start',
    business_hours_ended: 'business hours end',
    issue_created: 'an issue is created',
    issue_assigned: 'someone is assigned',
    team_added: 'a team is added to an issue',
    workstream_status_changed: 'a team changes its status',
    overall_status_changed: 'the overall status changes',
    issue_completed: 'an issue is completed',
    dependency_resolved: 'a blocker is resolved',
    due_date_approaching: 'a due date is approaching',
    issue_overdue: 'an issue is overdue',
    comment_added: 'a comment is added',
    cycle_started: 'a cycle starts',
    cycle_completed: 'a cycle completes',
};

export const TRIGGER_HINTS: Record<string, string> = {
    conversation_received:
        'An inbound message starts a brand-new conversation.',
    reply_received:
        'An inbound message lands in a conversation that already exists.',
    conversation_assigned:
        'Someone (or another rule) assigns the conversation.',
    conversation_moved: 'The conversation is moved into another inbox.',
    conversation_reopened: 'A closed conversation comes back to life.',
    conversation_closed: 'The conversation is marked as closed.',
    tag_added: 'A tag is attached to the conversation.',
    business_hours_started:
        'Checked every minute against every open conversation when you open.',
    business_hours_ended:
        'Checked every minute against every open conversation when you close.',
    issue_created:
        'A new issue is created, by hand, from an email or by a rule.',
    issue_assigned:
        'A person is assigned to one of the issue’s team workstreams.',
    team_added: 'A team joins the issue and gets its own workstream.',
    workstream_status_changed:
        'One team moves its part of the issue to another status.',
    overall_status_changed:
        'The issue’s overall status changes, by hand or automatically.',
    issue_completed: 'The overall status becomes a completed one.',
    dependency_resolved: 'Something that blocked the issue is finished.',
    due_date_approaching:
        'Checked hourly, once per day per issue, while the due date is within two weeks.',
    issue_overdue:
        'Checked hourly, once per day per issue, after the due date passes.',
    comment_added: 'Someone posts a comment on the issue.',
    cycle_started: 'A cycle starts; fires once per issue in that cycle.',
    cycle_completed: 'A cycle completes; fires once per issue in that cycle.',
};

const ACTION_VERBS: Record<string, string> = {
    reply_with_template: 'reply with a template',
    assign_user: 'assign to someone',
    assign_team: 'assign to a team',
    move_to_inbox: 'move to an inbox',
    add_tag: 'add a tag',
    remove_tag: 'remove a tag',
    set_priority: 'set the priority',
    set_status: 'change the status',
    notify_users: 'notify people',
    add_follower: 'add a follower',
    add_comment: 'leave an internal note',
    create_issue: 'create an issue',
    link_issue: 'link an issue',
    set_issue_status: 'set the overall status',
    set_issue_priority: 'set the priority',
    set_issue_owner: 'set the owner',
    add_team_to_issue: 'add a team',
    assign_workstream: 'assign a team member',
    set_workstream_status: 'set a team’s status',
    add_issue_label: 'add a label',
    remove_issue_label: 'remove a label',
    add_issue_comment: 'add a comment',
    add_issue_follower: 'add a follower',
    move_issue_to_project: 'move to a project',
};

/**
 * Human labels for catalog-driven action parameters.
 */
export const PARAM_LABELS: Record<string, string> = {
    product_id: 'Product',
    project_id: 'Project',
    team_id: 'Team',
    team_ids: 'Teams',
    user_id: 'Person',
    user_ids: 'People',
    owner_id: 'Owner',
    label_id: 'Label',
    label_ids: 'Labels',
    label_name: 'Or a new label name',
    status_id: 'Status',
    priority: 'Priority',
    title_template: 'Title',
    include_excerpt: 'Share the latest message as an excerpt',
    is_required: 'Required before the issue can complete',
    strategy: 'Hand out work',
    mode: 'Which issue',
    identifier: 'Issue identifier',
    body: 'Comment',
    message: 'Message',
};

export const PARAM_HINTS: Record<string, string> = {
    title_template:
        'Leave empty to use the conversation subject. Variables like {{ conversation.subject }} work.',
    include_excerpt:
        'Only the rule’s creator must be able to read the conversation; nothing else leaks.',
    label_name: 'Creates the label when it does not exist yet.',
    identifier: 'e.g. FRONT-12',
};

const PLACEHOLDER = '…';

export function fieldFor(
    catalog: AutomationCatalog,
    value: string,
): CatalogField | undefined {
    return catalog.fields.find((field) => field.value === value);
}

export function operatorFor(
    catalog: AutomationCatalog,
    value: string,
): CatalogOperator | undefined {
    return catalog.operators.find((operator) => operator.value === value);
}

export function actionFor(
    catalog: AutomationCatalog,
    value: string,
): CatalogAction | undefined {
    return catalog.actions.find((action) => action.value === value);
}

export function operatorsForField(
    catalog: AutomationCatalog,
    field: string,
): CatalogOperator[] {
    const allowed = fieldFor(catalog, field)?.operators ?? [];

    return allowed
        .map((value) => operatorFor(catalog, value))
        .filter((operator): operator is CatalogOperator => Boolean(operator));
}

export function valueShapeOf(
    catalog: AutomationCatalog,
    operator: string,
): OperatorValueShape {
    return operatorFor(catalog, operator)?.value_shape ?? 'string';
}

export function triggerPhrase(
    catalog: AutomationCatalog,
    trigger: string,
): string {
    if (TRIGGER_PHRASES[trigger]) {
        return TRIGGER_PHRASES[trigger];
    }

    const label = catalog.triggers.find(
        (option) => option.value === trigger,
    )?.label;

    return label ? lowerFirst(label) : 'something happens';
}

export function actionLabel(catalog: AutomationCatalog, type: string): string {
    return actionFor(catalog, type)?.label ?? humanize(type);
}

/**
 * Convert a stored value into the shape an operator expects, keeping as much input as possible.
 */
export function coerceValue(
    value: AutomationConditionValue,
    shape: OperatorValueShape,
): AutomationConditionValue {
    if (shape === 'none') {
        return null;
    }

    if (shape === 'array') {
        if (Array.isArray(value)) {
            return value;
        }

        return value ? [value] : [];
    }

    if (Array.isArray(value)) {
        return value[0] ?? '';
    }

    return value ?? '';
}

export function emptyCondition(
    catalog: AutomationCatalog,
    subject: AutomationSubjectType = 'conversation',
): AutomationCondition {
    const fields = fieldsFor(catalog, subject);
    const preferred = subject === 'issue' ? 'issue_priority' : 'sender';
    const field =
        fields.find((candidate) => candidate.value === preferred) ??
        fields[0] ??
        catalog.fields[0];
    const operator = field?.operators[0] ?? 'equals';

    return {
        field: field?.value ?? preferred,
        operator,
        value: coerceValue('', valueShapeOf(catalog, operator)),
    };
}

/**
 * A sensible empty value for a catalog parameter, by its declared type.
 */
export function defaultParamValue(
    param: CatalogActionParam,
): AutomationParamValue {
    switch (param.type) {
        case 'teams':
        case 'users':
        case 'labels':
            return [];
        case 'boolean':
            return false;
        case 'select':
            return param.options?.[0]?.value ?? null;
        case 'string':
        case 'text':
            return '';
        default:
            return null;
    }
}

export function defaultParams(
    catalog: AutomationCatalog,
    type: string,
): AutomationActionParams {
    switch (type) {
        case 'create_issue':
            return {
                product_id: null,
                project_id: null,
                team_ids: [],
                title_template: '',
                priority: null,
                owner_id: null,
                label_ids: [],
                include_excerpt: false,
            };
        case 'link_issue':
            return { mode: 'subject_match', identifier: '' };
        case 'set_issue_priority':
            return { priority: 'high' };
        case 'add_team_to_issue':
            return { team_id: null, is_required: true };
        case 'assign_workstream':
            return { team_id: null, user_id: null, strategy: 'round_robin' };
        case 'add_issue_label':
        case 'remove_issue_label':
            return { label_id: null, label_name: '' };
        case 'reply_with_template':
            return {
                template_id: null,
                cooldown_minutes: catalog.default_cooldown_minutes,
            };
        case 'assign_team':
            return {
                team_id: null,
                strategy: catalog.strategies[0]?.value ?? 'round_robin',
            };
        case 'set_priority':
            return { priority: 'high' };
        case 'set_status':
            return { status: 'open' };
        case 'notify_users':
            return { user_ids: [], message: '' };
        case 'add_tag':
        case 'remove_tag':
            return { tag_id: null, tag_name: '' };
        case 'add_comment':
            return { body: '' };
        case 'assign_user':
        case 'add_follower':
            return { user_id: null };
        case 'move_to_inbox':
            return { inbox_uuid: null };
        default: {
            const params: AutomationActionParams = {};

            for (const param of actionFor(catalog, type)?.params ?? []) {
                params[param.name] = defaultParamValue(param);
            }

            return params;
        }
    }
}

export function emptyAction(
    catalog: AutomationCatalog,
    type = 'add_tag',
): AutomationAction {
    return { type, params: defaultParams(catalog, type) };
}

export function defaultActionType(subject: AutomationSubjectType): string {
    return subject === 'issue' ? 'set_issue_priority' : 'add_tag';
}

export function emptyRuleForm(
    catalog: AutomationCatalog,
    subject: AutomationSubjectType = 'conversation',
): AutomationRuleForm {
    const fallback =
        subject === 'issue' ? 'issue_created' : 'conversation_received';

    return {
        name: '',
        description: '',
        subject_type: subject,
        inbox_uuid: null,
        product_id: null,
        team_id: null,
        trigger: triggersFor(catalog, subject)[0]?.value ?? fallback,
        match: 'all',
        is_active: true,
        cooldown_minutes: null,
        conditions: [],
        actions: [emptyAction(catalog, defaultActionType(subject))],
    };
}

/**
 * Move a rule to the other subject: pick that subject's first trigger, drop conditions and
 * actions that do not apply there, and clear the scope that belongs to the old subject.
 */
export function switchSubject(
    form: AutomationRuleForm,
    subject: AutomationSubjectType,
    catalog: AutomationCatalog,
): AutomationRuleForm {
    if (form.subject_type === subject) {
        return form;
    }

    const fields = new Set(fieldsFor(catalog, subject).map((f) => f.value));
    const actions = form.actions.filter((action) =>
        actionSupports(catalog, action.type, subject),
    );

    return {
        ...form,
        subject_type: subject,
        trigger:
            subjectOfTrigger(catalog, form.trigger) === subject
                ? form.trigger
                : (triggersFor(catalog, subject)[0]?.value ?? form.trigger),
        inbox_uuid: null,
        product_id: null,
        team_id: null,
        conditions: form.conditions.filter((condition) =>
            fields.has(condition.field),
        ),
        actions:
            actions.length > 0
                ? actions
                : [emptyAction(catalog, defaultActionType(subject))],
    };
}

function quote(value: string): string {
    return `“${value}”`;
}

function list(values: string[], conjunction: 'or' | 'and' | 'nor'): string {
    if (values.length <= 1) {
        return values[0] ?? PLACEHOLDER;
    }

    return `${values.slice(0, -1).join(', ')} ${conjunction} ${values[values.length - 1]}`;
}

function nameOf<T extends { name: string }>(
    items: T[],
    predicate: (item: T) => boolean,
): string | null {
    return items.find(predicate)?.name ?? null;
}

function teamsOf(lookups: AutomationLookups): { id: number; name: string }[] {
    return (lookups.issues?.teams ?? lookups.teams).map((team) => ({
        id: team.id,
        name: team.name,
    }));
}

function membersOf(lookups: AutomationLookups): { id: number; name: string }[] {
    return (lookups.issues?.members ?? lookups.users).map((user) => ({
        id: user.id,
        name: user.name,
    }));
}

function describeValue(
    catalog: AutomationCatalog,
    field: CatalogField | undefined,
    raw: string,
    lookups: AutomationLookups,
): string {
    const value = raw.trim();

    if (value === '') {
        return PLACEHOLDER;
    }

    switch (field?.value_type) {
        case 'inbox':
            return (
                nameOf(lookups.inboxes, (inbox) => inbox.uuid === value) ??
                quote(value)
            );
        case 'user':
            return (
                nameOf(lookups.users, (user) => String(user.id) === value) ??
                `user #${value}`
            );
        case 'tag':
            return (
                nameOf(
                    lookups.tags,
                    (tag) =>
                        tag.slug === value ||
                        tag.name.toLowerCase() === value.toLowerCase(),
                ) ?? quote(value)
            );
        case 'status':
            return lowerFirst(
                catalog.statuses.find((option) => option.value === value)
                    ?.label ?? value,
            );
        case 'priority':
            return lowerFirst(
                catalog.priorities.find((option) => option.value === value)
                    ?.label ?? value,
            );
        case 'product':
            return (
                nameOf(
                    lookups.issues?.products ?? [],
                    (product) => String(product.id) === value,
                ) ?? `product #${value}`
            );
        case 'project':
            return (
                nameOf(
                    lookups.issues?.projects ?? [],
                    (project) => String(project.id) === value,
                ) ?? `project #${value}`
            );
        case 'team':
            return (
                nameOf(teamsOf(lookups), (team) => String(team.id) === value) ??
                `team #${value}`
            );
        case 'label':
            return (
                nameOf(
                    lookups.issues?.labels ?? [],
                    (label) => String(label.id) === value,
                ) ?? `label #${value}`
            );
        case 'workflow_status':
            return (
                nameOf(
                    statusOptionsFor(lookups),
                    (status) => String(status.id) === value,
                ) ??
                nameOf(
                    Object.values(lookups.issues?.workflows ?? {}).flatMap(
                        (workflow) => workflow.statuses,
                    ),
                    (status) => String(status.id) === value,
                ) ??
                `status #${value}`
            );
        case 'status_category':
            return lowerFirst(
                (catalog.status_categories ?? []).find(
                    (option) => option.value === value,
                )?.label ?? value,
            );
        case 'issue_priority':
            return lowerFirst(
                (catalog.issue_priorities ?? []).find(
                    (option) => option.value === value,
                )?.label ?? value,
            );
        case 'number':
            return value;
        default:
            return quote(value);
    }
}

export function describeCondition(
    condition: AutomationCondition,
    catalog: AutomationCatalog,
    lookups: AutomationLookups = EMPTY_LOOKUPS,
): string {
    const field = fieldFor(catalog, condition.field);
    const operator = operatorFor(catalog, condition.operator);
    const subject = lowerFirst(field?.label ?? humanize(condition.field));

    if (field?.value === 'business_hours') {
        if (condition.operator === 'is_false') {
            return `it is outside ${subject}`;
        }

        if (condition.operator === 'is_true') {
            return `it is during ${subject}`;
        }
    }

    if (field?.value === 'issue_has_blockers') {
        if (condition.operator === 'is_false') {
            return 'it is not blocked';
        }

        if (condition.operator === 'is_true') {
            return 'it is blocked';
        }
    }

    if (field?.value_type === 'number' && operator?.value_shape === 'string') {
        const raw = Array.isArray(condition.value)
            ? (condition.value[0] ?? '')
            : (condition.value ?? '');
        const amount = String(raw).trim();

        return `${subject} ${operator?.label ?? humanize(condition.operator)} ${amount === '' ? PLACEHOLDER : amount}`;
    }

    const verb = operator?.label ?? humanize(condition.operator);

    if (operator?.value_shape === 'none') {
        return `${subject} ${verb}`;
    }

    if (operator?.value_shape === 'array') {
        const values = (
            Array.isArray(condition.value)
                ? condition.value
                : condition.value
                  ? [condition.value]
                  : []
        ).map((item) => describeValue(catalog, field, String(item), lookups));

        return `${subject} ${verb} ${list(values, condition.operator === 'not_in' ? 'nor' : 'or')}`;
    }

    const single = Array.isArray(condition.value)
        ? (condition.value[0] ?? '')
        : (condition.value ?? '');

    return `${subject} ${verb} ${describeValue(catalog, field, String(single), lookups)}`;
}

function param(action: AutomationAction, key: string): unknown {
    return action.params?.[key];
}

function present(value: unknown): boolean {
    return value !== null && value !== undefined && String(value).trim() !== '';
}

export function describeAction(
    action: AutomationAction,
    catalog: AutomationCatalog,
    lookups: AutomationLookups = EMPTY_LOOKUPS,
): string {
    switch (action.type) {
        case 'reply_with_template': {
            const template = lookups.templates.find(
                (candidate) =>
                    String(candidate.id) ===
                    String(param(action, 'template_id')),
            );

            return template
                ? `reply with ${quote(template.name)}`
                : ACTION_VERBS[action.type];
        }
        case 'assign_user': {
            const user = nameOf(
                lookups.users,
                (candidate) =>
                    String(candidate.id) === String(param(action, 'user_id')),
            );

            return user ? `assign to ${user}` : ACTION_VERBS[action.type];
        }
        case 'add_follower': {
            const user = nameOf(
                lookups.users,
                (candidate) =>
                    String(candidate.id) === String(param(action, 'user_id')),
            );

            return user
                ? `add ${user} as a follower`
                : ACTION_VERBS[action.type];
        }
        case 'assign_team': {
            const team = nameOf(
                lookups.teams,
                (candidate) =>
                    String(candidate.id) === String(param(action, 'team_id')),
            );
            const strategy = catalog.strategies.find(
                (option) => option.value === param(action, 'strategy'),
            )?.label;

            if (!team) {
                return ACTION_VERBS[action.type];
            }

            return strategy
                ? `assign to ${team} (${lowerFirst(strategy)})`
                : `assign to ${team}`;
        }
        case 'move_to_inbox': {
            const inbox = nameOf(
                lookups.inboxes,
                (candidate) => candidate.uuid === param(action, 'inbox_uuid'),
            );

            return inbox ? `move to ${inbox}` : ACTION_VERBS[action.type];
        }
        case 'add_tag':
        case 'remove_tag': {
            const verb = action.type === 'add_tag' ? 'add tag' : 'remove tag';
            const tagId = param(action, 'tag_id');
            const tagName = param(action, 'tag_name');
            const tag = present(tagId)
                ? nameOf(
                      lookups.tags,
                      (candidate) => String(candidate.id) === String(tagId),
                  )
                : null;

            if (tag) {
                return `${verb} ${tag}`;
            }

            return present(tagName)
                ? `${verb} ${String(tagName).trim()}`
                : ACTION_VERBS[action.type];
        }
        case 'set_priority': {
            const priority = catalog.priorities.find(
                (option) => option.value === param(action, 'priority'),
            )?.label;

            return priority
                ? `set priority to ${lowerFirst(priority)}`
                : ACTION_VERBS[action.type];
        }
        case 'set_status': {
            const status = catalog.statuses.find(
                (option) => option.value === param(action, 'status'),
            )?.label;

            return status
                ? `mark as ${lowerFirst(status)}`
                : ACTION_VERBS[action.type];
        }
        case 'notify_users': {
            const ids = Array.isArray(param(action, 'user_ids'))
                ? (param(action, 'user_ids') as unknown[]).map(String)
                : [];
            const names = ids.map(
                (id) =>
                    nameOf(
                        lookups.users,
                        (candidate) => String(candidate.id) === id,
                    ) ?? `user #${id}`,
            );

            if (names.length === 0) {
                return ACTION_VERBS[action.type];
            }

            return names.length > 3
                ? `notify ${names.length} people`
                : `notify ${list(names, 'and')}`;
        }
        case 'add_comment':
            return present(param(action, 'body'))
                ? 'leave an internal note'
                : ACTION_VERBS[action.type];
        case 'create_issue': {
            const issues = lookups.issues;
            const product = nameOf(
                issues?.products ?? [],
                (candidate) =>
                    String(candidate.id) ===
                    String(param(action, 'product_id')),
            );
            const teamIds = Array.isArray(param(action, 'team_ids'))
                ? (param(action, 'team_ids') as unknown[]).map(String)
                : [];
            const teams = teamIds
                .map((id) =>
                    nameOf(
                        teamsOf(lookups),
                        (candidate) => String(candidate.id) === id,
                    ),
                )
                .filter((name): name is string => Boolean(name));
            const where = product ? ` in ${product}` : '';
            const who = teams.length > 0 ? ` for ${list(teams, 'and')}` : '';

            return `create an issue${where}${who}`;
        }
        case 'link_issue': {
            const identifier = String(param(action, 'identifier') ?? '').trim();

            return param(action, 'mode') === 'subject_match'
                ? 'link the issue named in the subject'
                : identifier
                  ? `link ${identifier.toUpperCase()}`
                  : ACTION_VERBS[action.type];
        }
        case 'set_issue_status': {
            const status = nameOf(
                statusOptionsFor(lookups),
                (candidate) =>
                    String(candidate.id) === String(param(action, 'status_id')),
            );

            return status
                ? `set the overall status to ${status}`
                : ACTION_VERBS[action.type];
        }
        case 'set_issue_priority': {
            const priority = (catalog.issue_priorities ?? []).find(
                (option) => option.value === param(action, 'priority'),
            )?.label;

            return priority
                ? `set priority to ${lowerFirst(priority)}`
                : ACTION_VERBS[action.type];
        }
        case 'set_issue_owner':
        case 'add_issue_follower': {
            const user = nameOf(
                membersOf(lookups),
                (candidate) =>
                    String(candidate.id) === String(param(action, 'user_id')),
            );

            if (!user) {
                return ACTION_VERBS[action.type];
            }

            return action.type === 'set_issue_owner'
                ? `make ${user} the owner`
                : `add ${user} as a follower`;
        }
        case 'add_team_to_issue':
        case 'assign_workstream':
        case 'set_workstream_status': {
            const team = nameOf(
                teamsOf(lookups),
                (candidate) =>
                    String(candidate.id) === String(param(action, 'team_id')),
            );

            if (!team) {
                return ACTION_VERBS[action.type];
            }

            if (action.type === 'add_team_to_issue') {
                return `add team ${team}`;
            }

            if (action.type === 'set_workstream_status') {
                const status = nameOf(
                    statusOptionsFor(
                        lookups,
                        Number(param(action, 'team_id')) || null,
                    ),
                    (candidate) =>
                        String(candidate.id) ===
                        String(param(action, 'status_id')),
                );

                return status
                    ? `set ${team}’s status to ${status}`
                    : `set ${team}’s status`;
            }

            const user = nameOf(
                membersOf(lookups),
                (candidate) =>
                    String(candidate.id) === String(param(action, 'user_id')),
            );
            const strategy = catalog.strategies.find(
                (option) => option.value === param(action, 'strategy'),
            )?.label;

            return user
                ? `assign ${user} for ${team}`
                : `assign someone from ${team}${strategy ? ` (${lowerFirst(strategy)})` : ''}`;
        }
        case 'add_issue_label':
        case 'remove_issue_label': {
            const verb =
                action.type === 'add_issue_label'
                    ? 'add label'
                    : 'remove label';
            const labelId = param(action, 'label_id');
            const labelName = param(action, 'label_name');
            const label = present(labelId)
                ? nameOf(
                      lookups.issues?.labels ?? [],
                      (candidate) => String(candidate.id) === String(labelId),
                  )
                : null;

            if (label) {
                return `${verb} ${label}`;
            }

            return present(labelName)
                ? `${verb} ${String(labelName).trim()}`
                : ACTION_VERBS[action.type];
        }
        case 'add_issue_comment':
            return present(param(action, 'body'))
                ? 'add a comment'
                : ACTION_VERBS[action.type];
        case 'move_issue_to_project': {
            const project = nameOf(
                lookups.issues?.projects ?? [],
                (candidate) =>
                    String(candidate.id) ===
                    String(param(action, 'project_id')),
            );

            return project ? `move to ${project}` : ACTION_VERBS[action.type];
        }
        default:
            return (
                ACTION_VERBS[action.type] ??
                lowerFirst(actionLabel(catalog, action.type))
            );
    }
}

export type SummarizableRule = {
    trigger: string;
    match: AutomationMatch;
    subject_type?: AutomationSubjectType;
    inbox_uuid?: string | null;
    product_id?: number | null;
    team_id?: number | null;
    conditions: AutomationCondition[];
    actions: AutomationAction[];
};

/**
 * "in Frontier for Engineering" or "in Support": the scope clause of a rule sentence.
 */
export function describeScope(
    rule: SummarizableRule,
    lookups: AutomationLookups = EMPTY_LOOKUPS,
): string {
    if ((rule.subject_type ?? 'conversation') === 'issue') {
        const product = rule.product_id
            ? nameOf(
                  lookups.issues?.products ?? [],
                  (candidate) => candidate.id === rule.product_id,
              )
            : null;
        const team = rule.team_id
            ? nameOf(
                  teamsOf(lookups),
                  (candidate) => candidate.id === rule.team_id,
              )
            : null;

        return `${product ? ` in ${product}` : ''}${team ? ` for ${team}` : ''}`;
    }

    const inbox = rule.inbox_uuid
        ? nameOf(
              lookups.inboxes,
              (candidate) => candidate.uuid === rule.inbox_uuid,
          )
        : null;

    return inbox ? ` in ${inbox}` : '';
}

/**
 * One plain-English sentence for the whole rule, e.g.
 * "When a new conversation arrives in Support, if sender ends with “@acme.com”, then add tag VIP and set priority to high."
 */
export function summarizeRule(
    rule: SummarizableRule,
    catalog: AutomationCatalog,
    lookups: AutomationLookups = EMPTY_LOOKUPS,
): string {
    const when = `When ${triggerPhrase(catalog, rule.trigger)}${describeScope(rule, lookups)}`;

    const conditions = rule.conditions.map((condition) =>
        describeCondition(condition, catalog, lookups),
    );
    const conjunction = rule.match === 'any' ? 'or' : 'and';
    const ifClause =
        conditions.length > 0 ? `, if ${list(conditions, conjunction)}` : '';

    const actions = rule.actions.map((action) =>
        describeAction(action, catalog, lookups),
    );
    const thenClause =
        actions.length > 0
            ? `${conditions.length > 0 ? ', then' : ','} ${list(actions, 'and')}`
            : ', do nothing yet';

    return `${when}${ifClause}${thenClause}.`;
}

export function formatMinutes(minutes: number | null | undefined): string {
    if (minutes === null || minutes === undefined || Number.isNaN(minutes)) {
        return 'no cooldown';
    }

    if (minutes === 0) {
        return 'no cooldown';
    }

    if (minutes % 1440 === 0) {
        const days = minutes / 1440;

        return days === 1 ? '1 day' : `${days} days`;
    }

    if (minutes % 60 === 0) {
        const hours = minutes / 60;

        return hours === 1 ? '1 hour' : `${hours} hours`;
    }

    return minutes === 1 ? '1 minute' : `${minutes} minutes`;
}

const action = (
    type: string,
    params: AutomationActionParams,
): AutomationAction => ({ type, params });

export type StarterKey = 'out_of_office' | 'route_invoices' | 'vip_priority';

export type StarterRule = {
    key: StarterKey;
    title: string;
    blurb: string;
    build: (
        catalog: AutomationCatalog,
        lookups: AutomationLookups,
    ) => AutomationRuleForm;
};

export const STARTER_RULES: StarterRule[] = [
    {
        key: 'out_of_office',
        title: 'Out-of-office responder',
        blurb: 'Auto-reply outside business hours.',
        build: (catalog, lookups) => ({
            ...emptyRuleForm(catalog),
            name: 'Out of office',
            description:
                'Let people know when to expect us when we are closed.',
            trigger: 'conversation_received',
            conditions: [
                { field: 'business_hours', operator: 'is_false', value: null },
            ],
            actions: [
                action('reply_with_template', {
                    template_id: lookups.templates[0]?.id ?? null,
                    cooldown_minutes: catalog.default_cooldown_minutes,
                }),
            ],
        }),
    },
    {
        key: 'route_invoices',
        title: 'Route invoices to a team',
        blurb: 'Route billing mail to finance.',
        build: (catalog, lookups) => ({
            ...emptyRuleForm(catalog),
            name: 'Route invoices to finance',
            description:
                'Invoices, receipts and refunds land with the right people.',
            trigger: 'conversation_received',
            match: 'any',
            conditions: [
                {
                    field: 'subject',
                    operator: 'matches_regex',
                    value: '\\b(invoice|receipt|refund)\\b',
                },
                {
                    field: 'recipient',
                    operator: 'starts_with',
                    value: 'billing@',
                },
            ],
            actions: [
                action('assign_team', {
                    team_id: lookups.teams[0]?.id ?? null,
                    strategy: 'least_busy',
                }),
                action('add_tag', { tag_id: null, tag_name: 'billing' }),
            ],
        }),
    },
    {
        key: 'vip_priority',
        title: 'VIP priority',
        blurb: 'Flag mail from key domains.',
        build: (catalog) => ({
            ...emptyRuleForm(catalog),
            name: 'VIP customers',
            description: 'Keep an eye on the accounts that matter most.',
            trigger: 'conversation_received',
            conditions: [
                { field: 'sender', operator: 'in', value: ['@bigcorp.com'] },
            ],
            actions: [
                action('add_tag', { tag_id: null, tag_name: 'VIP' }),
                action('set_priority', { priority: 'high' }),
            ],
        }),
    },
];

export function starterRule(
    key: string | null | undefined,
    catalog: AutomationCatalog,
    lookups: AutomationLookups,
): AutomationRuleForm | null {
    const starter = STARTER_RULES.find((candidate) => candidate.key === key);

    return starter ? starter.build(catalog, lookups) : null;
}

/**
 * Templates are stored as HTML but edited in a plain textarea. Convert in both directions
 * while keeping hand-written HTML untouched.
 */
export function textToHtml(text: string): string {
    const trimmed = text.trim();

    if (trimmed === '') {
        return '';
    }

    if (/<[a-z][\s\S]*>/i.test(trimmed)) {
        return trimmed;
    }

    return trimmed
        .split(/\n{2,}/)
        .map(
            (paragraph) =>
                `<p>${escapeHtml(paragraph.trim()).replace(/\n/g, '<br>')}</p>`,
        )
        .join('\n');
}

export function htmlToText(html: string): string {
    const trimmed = html.trim();

    if (trimmed === '') {
        return '';
    }

    const simple =
        /^(<p>[\s\S]*?<\/p>\s*)+$/i.test(trimmed) &&
        !/<(?!\/?p\b|br\b)[a-z]/i.test(trimmed);

    if (!simple) {
        return trimmed;
    }

    return unescapeHtml(
        trimmed
            .replace(/<\/p>\s*<p>/gi, '\n\n')
            .replace(/<br\s*\/?>/gi, '\n')
            .replace(/^<p>|<\/p>$/gi, ''),
    );
}

export function insertAtCursor(
    text: string,
    insert: string,
    selectionStart: number,
    selectionEnd: number,
): { text: string; cursor: number } {
    const before = text.slice(0, selectionStart);
    const after = text.slice(selectionEnd);

    return {
        text: `${before}${insert}${after}`,
        cursor: selectionStart + insert.length,
    };
}

export function variableToken(key: string): string {
    return `{{ ${key} }}`;
}

function escapeHtml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function unescapeHtml(value: string): string {
    return value
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&amp;/g, '&');
}

function lowerFirst(value: string): string {
    return value.charAt(0).toLowerCase() + value.slice(1);
}

function humanize(value: string): string {
    return value.replace(/_/g, ' ');
}
