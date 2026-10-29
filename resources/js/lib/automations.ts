import type {
    AutomationAction,
    AutomationActionParams,
    AutomationCatalog,
    AutomationCondition,
    AutomationConditionValue,
    AutomationLookups,
    AutomationMatch,
    AutomationRuleForm,
    CatalogAction,
    CatalogField,
    CatalogOperator,
    OperatorValueShape,
} from '@/types/automations';

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
};

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
): AutomationCondition {
    const field =
        catalog.fields.find((candidate) => candidate.value === 'sender') ??
        catalog.fields[0];
    const operator = field?.operators[0] ?? 'equals';

    return {
        field: field?.value ?? 'sender',
        operator,
        value: coerceValue('', valueShapeOf(catalog, operator)),
    };
}

export function defaultParams(
    catalog: AutomationCatalog,
    type: string,
): AutomationActionParams {
    switch (type) {
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
        default:
            return {};
    }
}

export function emptyAction(
    catalog: AutomationCatalog,
    type = 'add_tag',
): AutomationAction {
    return { type, params: defaultParams(catalog, type) };
}

export function emptyRuleForm(catalog: AutomationCatalog): AutomationRuleForm {
    return {
        name: '',
        description: '',
        inbox_uuid: null,
        trigger: catalog.triggers[0]?.value ?? 'conversation_received',
        match: 'all',
        is_active: true,
        cooldown_minutes: null,
        conditions: [],
        actions: [emptyAction(catalog)],
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

    if (field?.value_type === 'boolean') {
        if (condition.operator === 'is_false') {
            return `it is outside ${subject}`;
        }

        if (condition.operator === 'is_true') {
            return `it is during ${subject}`;
        }
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
    inbox_uuid?: string | null;
    conditions: AutomationCondition[];
    actions: AutomationAction[];
};

/**
 * One plain-English sentence for the whole rule, e.g.
 * "When a new conversation arrives in Support, if sender ends with “@acme.com”, then add tag VIP and set priority to high."
 */
export function summarizeRule(
    rule: SummarizableRule,
    catalog: AutomationCatalog,
    lookups: AutomationLookups = EMPTY_LOOKUPS,
): string {
    const inbox = rule.inbox_uuid
        ? nameOf(
              lookups.inboxes,
              (candidate) => candidate.uuid === rule.inbox_uuid,
          )
        : null;
    const when = `When ${triggerPhrase(catalog, rule.trigger)}${inbox ? ` in ${inbox}` : ''}`;

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
        blurb: 'Reply automatically outside business hours. One reply per sender per day, never to robots.',
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
        blurb: 'Anything that smells like billing goes to the finance team and gets tagged.',
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
        blurb: 'Mail from your most important domains gets tagged and bumped to the top.',
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
