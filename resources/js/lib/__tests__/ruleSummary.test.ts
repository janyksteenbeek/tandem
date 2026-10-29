import { describe, expect, it } from 'vitest';
import {
    coerceValue,
    describeAction,
    describeCondition,
    emptyRuleForm,
    formatMinutes,
    htmlToText,
    insertAtCursor,
    operatorsForField,
    starterRule,
    summarizeRule,
    textToHtml,
} from '@/lib/automations';
import type { AutomationCatalog, AutomationLookups } from '@/types/automations';

const catalog: AutomationCatalog = {
    triggers: [
        { value: 'conversation_received', label: 'New conversation received' },
        { value: 'tag_added', label: 'Tag added' },
    ],
    match: [
        { value: 'all', label: 'All conditions match' },
        { value: 'any', label: 'Any condition matches' },
    ],
    fields: [
        { value: 'inbox', label: 'Inbox', value_type: 'inbox', operators: ['equals', 'not_equals', 'in', 'not_in'] },
        { value: 'sender', label: 'Sender', value_type: 'email', operators: ['equals', 'ends_with', 'in', 'is_set'] },
        { value: 'subject', label: 'Subject', value_type: 'text', operators: ['contains', 'matches_regex', 'is_empty'] },
        { value: 'tag', label: 'Tag', value_type: 'tag', operators: ['equals', 'in'] },
        { value: 'assignee', label: 'Assignee', value_type: 'user', operators: ['equals', 'is_set'] },
        { value: 'business_hours', label: 'Business hours', value_type: 'boolean', operators: ['is_true', 'is_false'] },
        { value: 'priority', label: 'Priority', value_type: 'priority', operators: ['equals', 'in'] },
    ],
    operators: [
        { value: 'equals', label: 'is', value_shape: 'string' },
        { value: 'not_equals', label: 'is not', value_shape: 'string' },
        { value: 'contains', label: 'contains', value_shape: 'string' },
        { value: 'ends_with', label: 'ends with', value_shape: 'string' },
        { value: 'matches_regex', label: 'matches regex', value_shape: 'string' },
        { value: 'in', label: 'is any of', value_shape: 'array' },
        { value: 'not_in', label: 'is none of', value_shape: 'array' },
        { value: 'is_set', label: 'is set', value_shape: 'none' },
        { value: 'is_empty', label: 'is empty', value_shape: 'none' },
        { value: 'is_true', label: 'is true', value_shape: 'none' },
        { value: 'is_false', label: 'is false', value_shape: 'none' },
    ],
    actions: [
        { value: 'reply_with_template', label: 'Reply with template', params: [] },
        { value: 'assign_team', label: 'Assign to team', params: [] },
        { value: 'add_tag', label: 'Add tag', params: [] },
        { value: 'set_priority', label: 'Set priority', params: [] },
        { value: 'notify_users', label: 'Notify users', params: [] },
        { value: 'move_to_inbox', label: 'Move to inbox', params: [] },
    ],
    statuses: [
        { value: 'open', label: 'Open' },
        { value: 'closed', label: 'Closed' },
    ],
    priorities: [
        { value: 'normal', label: 'Normal' },
        { value: 'high', label: 'High' },
    ],
    strategies: [
        { value: 'round_robin', label: 'Round robin' },
        { value: 'least_busy', label: 'Least busy' },
    ],
    variables: [],
    default_cooldown_minutes: 1440,
};

const lookups: AutomationLookups = {
    inboxes: [{ uuid: 'inbox-support', name: 'Support', color: 'teal' }],
    tags: [{ id: 7, name: 'VIP', slug: 'vip', color: 'rose' }],
    users: [
        { id: 1, name: 'Ada Lovelace', avatar: null },
        { id: 2, name: 'Grace Hopper', avatar: null },
    ],
    teams: [{ id: 4, name: 'Finance', color: null, member_count: 3 }],
    templates: [{ id: 3, name: 'Out of office', category: null, subject: null, inbox: null }],
};

describe('summarizeRule', () => {
    it('describes a full rule as one sentence', () => {
        const sentence = summarizeRule(
            {
                trigger: 'conversation_received',
                match: 'all',
                inbox_uuid: 'inbox-support',
                conditions: [{ field: 'sender', operator: 'ends_with', value: '@acme.com' }],
                actions: [
                    { type: 'add_tag', params: { tag_id: 7 } },
                    { type: 'set_priority', params: { priority: 'high' } },
                ],
            },
            catalog,
            lookups,
        );

        expect(sentence).toBe('When a new conversation arrives in Support, if sender ends with “@acme.com”, then add tag VIP and set priority to high.');
    });

    it('joins conditions with "or" when any condition may match', () => {
        const sentence = summarizeRule(
            {
                trigger: 'tag_added',
                match: 'any',
                conditions: [
                    { field: 'subject', operator: 'contains', value: 'invoice' },
                    { field: 'sender', operator: 'in', value: ['@a.com', '@b.com', '@c.com'] },
                ],
                actions: [{ type: 'assign_team', params: { team_id: 4, strategy: 'least_busy' } }],
            },
            catalog,
            lookups,
        );

        expect(sentence).toBe(
            'When a tag is added, if subject contains “invoice” or sender is any of “@a.com”, “@b.com” or “@c.com”, then assign to Finance (least busy).',
        );
    });

    it('skips the if-clause when there are no conditions and uses placeholders for missing values', () => {
        const sentence = summarizeRule(
            {
                trigger: 'conversation_received',
                match: 'all',
                conditions: [],
                actions: [{ type: 'move_to_inbox', params: { inbox_uuid: null } }],
            },
            catalog,
            lookups,
        );

        expect(sentence).toBe('When a new conversation arrives, move to an inbox.');
    });
});

describe('describeCondition', () => {
    it('reads business hours naturally', () => {
        expect(describeCondition({ field: 'business_hours', operator: 'is_false', value: null }, catalog)).toBe('it is outside business hours');
        expect(describeCondition({ field: 'business_hours', operator: 'is_true', value: null }, catalog)).toBe('it is during business hours');
    });

    it('resolves lookups for inboxes, users, tags and priorities', () => {
        expect(describeCondition({ field: 'inbox', operator: 'equals', value: 'inbox-support' }, catalog, lookups)).toBe('inbox is Support');
        expect(describeCondition({ field: 'assignee', operator: 'equals', value: '2' }, catalog, lookups)).toBe('assignee is Grace Hopper');
        expect(describeCondition({ field: 'tag', operator: 'equals', value: 'vip' }, catalog, lookups)).toBe('tag is VIP');
        expect(describeCondition({ field: 'priority', operator: 'in', value: ['high', 'normal'] }, catalog)).toBe('priority is any of high or normal');
    });

    it('uses "nor" for exclusion lists and no value for valueless operators', () => {
        expect(describeCondition({ field: 'inbox', operator: 'not_in', value: ['inbox-support', 'x'] }, catalog, lookups)).toBe('inbox is none of Support nor “x”');
        expect(describeCondition({ field: 'subject', operator: 'is_empty', value: null }, catalog)).toBe('subject is empty');
        expect(describeCondition({ field: 'sender', operator: 'equals', value: '' }, catalog)).toBe('sender is …');
    });
});

describe('describeAction', () => {
    it('names templates, people and teams', () => {
        expect(describeAction({ type: 'reply_with_template', params: { template_id: 3 } }, catalog, lookups)).toBe('reply with “Out of office”');
        expect(describeAction({ type: 'notify_users', params: { user_ids: [1, 2] } }, catalog, lookups)).toBe('notify Ada Lovelace and Grace Hopper');
        expect(describeAction({ type: 'notify_users', params: { user_ids: [1, 2, 1, 2] } }, catalog, lookups)).toBe('notify 4 people');
        expect(describeAction({ type: 'add_tag', params: { tag_name: 'billing' } }, catalog, lookups)).toBe('add tag billing');
    });

    it('falls back to a verb when params are incomplete', () => {
        expect(describeAction({ type: 'reply_with_template', params: {} }, catalog, lookups)).toBe('reply with a template');
        expect(describeAction({ type: 'notify_users', params: { user_ids: [] } }, catalog, lookups)).toBe('notify people');
    });
});

describe('builder helpers', () => {
    it('coerces values between operator shapes', () => {
        expect(coerceValue('a', 'array')).toEqual(['a']);
        expect(coerceValue(['a', 'b'], 'string')).toBe('a');
        expect(coerceValue(['a'], 'none')).toBeNull();
        expect(coerceValue(null, 'string')).toBe('');
    });

    it('lists only operators the field supports', () => {
        expect(operatorsForField(catalog, 'business_hours').map((operator) => operator.value)).toEqual(['is_true', 'is_false']);
        expect(operatorsForField(catalog, 'nope')).toEqual([]);
    });

    it('builds an empty rule with one action and a starter from a key', () => {
        const empty = emptyRuleForm(catalog);

        expect(empty.actions).toHaveLength(1);
        expect(empty.trigger).toBe('conversation_received');

        const starter = starterRule('out_of_office', catalog, lookups);

        expect(starter?.conditions).toEqual([{ field: 'business_hours', operator: 'is_false', value: null }]);
        expect(starter?.actions[0]?.params.template_id).toBe(3);
        expect(starterRule('unknown', catalog, lookups)).toBeNull();
    });

    it('formats cooldowns', () => {
        expect(formatMinutes(null)).toBe('no cooldown');
        expect(formatMinutes(90)).toBe('90 minutes');
        expect(formatMinutes(120)).toBe('2 hours');
        expect(formatMinutes(1440)).toBe('1 day');
    });
});

describe('template text helpers', () => {
    it('round-trips plain text through simple html', () => {
        const html = textToHtml('Hi {{ contact.first_name }},\n\nThanks & see you on {{ next_business_day }}.\nBye');

        expect(html).toBe('<p>Hi {{ contact.first_name }},</p>\n<p>Thanks &amp; see you on {{ next_business_day }}.<br>Bye</p>');
        expect(htmlToText(html)).toBe('Hi {{ contact.first_name }},\n\nThanks & see you on {{ next_business_day }}.\nBye');
    });

    it('leaves hand-written html alone', () => {
        const html = '<p>Hello</p><ul><li>One</li></ul>';

        expect(textToHtml(html)).toBe(html);
        expect(htmlToText(html)).toBe(html);
    });

    it('inserts a variable at the cursor', () => {
        expect(insertAtCursor('Hi , bye', '{{ contact.name }}', 3, 3)).toEqual({ text: 'Hi {{ contact.name }}, bye', cursor: 21 });
    });
});
