import { describe, expect, it } from 'vitest';
import {
    actionsFor,
    defaultParams,
    describeAction,
    describeCondition,
    emptyRuleForm,
    fieldsFor,
    statusOptionsFor,
    summarizeRule,
    switchSubject,
    triggersFor,
} from '@/lib/automations';
import type {
    AutomationCatalog,
    AutomationLookups,
    AutomationRuleForm,
} from '@/types/automations';
import type { IssueLookups, WorkflowStatus } from '@/types/projects';

const status = (
    id: number,
    workflow_id: number,
    name: string,
    category: WorkflowStatus['category'],
): WorkflowStatus => ({
    id,
    uuid: `s-${id}`,
    workflow_id,
    name,
    color: 'slate',
    icon: null,
    category,
    position: id,
    is_default: false,
});

const catalog: AutomationCatalog = {
    subjects: [
        { value: 'conversation', label: 'Conversation' },
        { value: 'issue', label: 'Issue' },
    ],
    triggers: [
        {
            value: 'conversation_received',
            label: 'New conversation received',
            subject: 'conversation',
        },
        { value: 'issue_created', label: 'Issue created', subject: 'issue' },
        {
            value: 'workstream_status_changed',
            label: 'A team changes its status',
            subject: 'issue',
        },
    ],
    match: [
        { value: 'all', label: 'All' },
        { value: 'any', label: 'Any' },
    ],
    fields: [
        {
            value: 'sender',
            label: 'Sender',
            value_type: 'email',
            subject: 'conversation',
            operators: ['equals', 'ends_with'],
        },
        {
            value: 'issue_priority',
            label: 'Priority',
            value_type: 'issue_priority',
            subject: 'issue',
            operators: ['equals', 'in'],
        },
        {
            value: 'issue_team',
            label: 'Participating team',
            value_type: 'team',
            subject: 'issue',
            operators: ['equals', 'in'],
        },
        {
            value: 'issue_days_until_due',
            label: 'Days until due',
            value_type: 'number',
            subject: 'issue',
            operators: ['at_most', 'at_least'],
        },
        {
            value: 'issue_has_blockers',
            label: 'Has blockers',
            value_type: 'boolean',
            subject: 'issue',
            operators: ['is_true', 'is_false'],
        },
    ],
    operators: [
        { value: 'equals', label: 'is', value_shape: 'string' },
        { value: 'ends_with', label: 'ends with', value_shape: 'string' },
        { value: 'in', label: 'is any of', value_shape: 'array' },
        { value: 'at_most', label: 'is at most', value_shape: 'string' },
        { value: 'at_least', label: 'is at least', value_shape: 'string' },
        { value: 'is_true', label: 'is true', value_shape: 'none' },
        { value: 'is_false', label: 'is false', value_shape: 'none' },
    ],
    actions: [
        {
            value: 'add_tag',
            label: 'Add tag',
            subjects: ['conversation'],
            params: [],
        },
        {
            value: 'notify_users',
            label: 'Notify users',
            subjects: ['conversation', 'issue'],
            params: [],
        },
        {
            value: 'create_issue',
            label: 'Create issue',
            subjects: ['conversation'],
            params: [
                { name: 'product_id', type: 'product', required: false },
                { name: 'team_ids', type: 'teams', required: false },
            ],
        },
        {
            value: 'set_issue_priority',
            label: 'Set priority',
            subjects: ['issue'],
            params: [{ name: 'priority', type: 'select', required: true }],
        },
        {
            value: 'set_workstream_status',
            label: "Set a team's status",
            subjects: ['issue'],
            params: [
                { name: 'team_id', type: 'team', required: true },
                { name: 'status_id', type: 'workflow_status', required: true },
            ],
        },
        {
            value: 'add_team_to_issue',
            label: 'Add team',
            subjects: ['issue'],
            params: [
                { name: 'team_id', type: 'team', required: true },
                { name: 'is_required', type: 'boolean', required: false },
            ],
        },
        {
            value: 'add_issue_label',
            label: 'Add label',
            subjects: ['issue'],
            params: [
                { name: 'label_id', type: 'label', required: false },
                { name: 'label_name', type: 'string', required: false },
            ],
        },
    ],
    statuses: [],
    priorities: [],
    issue_priorities: [
        { value: 'high', label: 'High' },
        { value: 'urgent', label: 'Urgent' },
    ],
    status_categories: [{ value: 'started', label: 'In progress' }],
    strategies: [{ value: 'round_robin', label: 'Round robin' }],
    variables: [],
    default_cooldown_minutes: 60,
};

const issues = {
    members: [],
    teams: [
        {
            id: 1,
            slug: 'engineering',
            name: 'Engineering',
            color: 'blue',
            icon: null,
            workflow_id: 20,
            estimate_scale: 'none',
        },
        {
            id: 2,
            slug: 'design',
            name: 'Design',
            color: 'pink',
            icon: null,
            workflow_id: 21,
            estimate_scale: 'none',
        },
    ],
    products: [
        {
            id: 5,
            uuid: 'p',
            slug: 'frontier',
            name: 'Frontier',
            key: 'FRONT',
            color: 'violet',
            icon: null,
            status: 'active',
            workflow_id: 10,
        },
    ],
    modules: [],
    projects: [],
    milestones: [],
    cycles: [],
    labels: [{ id: 7, name: 'Reports', slug: 'reports', color: 'amber' }],
    statuses: [],
    default_workflow_id: 10,
    workflows: {
        10: {
            id: 10,
            kind: 'issue',
            team_id: null,
            product_id: null,
            statuses: [
                status(100, 10, 'Todo', 'unstarted'),
                status(101, 10, 'Done', 'completed'),
            ],
        },
        20: {
            id: 20,
            kind: 'team',
            team_id: 1,
            product_id: null,
            statuses: [status(200, 20, 'In review', 'started')],
        },
        21: {
            id: 21,
            kind: 'team',
            team_id: 2,
            product_id: null,
            statuses: [status(210, 21, 'Mocking up', 'started')],
        },
    },
    priorities: [],
    categories: [],
    team_ids: [1],
} as unknown as IssueLookups;

const lookups: AutomationLookups = {
    inboxes: [],
    tags: [],
    users: [],
    teams: [],
    templates: [],
    issues,
};

describe('subject filtering', () => {
    it('splits triggers, fields and actions per subject', () => {
        expect(triggersFor(catalog, 'issue').map((t) => t.value)).toEqual([
            'issue_created',
            'workstream_status_changed',
        ]);
        expect(fieldsFor(catalog, 'conversation').map((f) => f.value)).toEqual([
            'sender',
        ]);
        expect(actionsFor(catalog, 'issue').map((a) => a.value)).toEqual([
            'notify_users',
            'set_issue_priority',
            'set_workstream_status',
            'add_team_to_issue',
            'add_issue_label',
        ]);
    });

    it('starts an issue rule on an issue trigger with an issue action', () => {
        const form = emptyRuleForm(catalog, 'issue');

        expect(form.subject_type).toBe('issue');
        expect(form.trigger).toBe('issue_created');
        expect(form.actions[0].type).toBe('set_issue_priority');
        expect(form.product_id).toBeNull();
    });

    it('drops incompatible checks and actions when switching subject', () => {
        const form: AutomationRuleForm = {
            ...emptyRuleForm(catalog, 'conversation'),
            inbox_uuid: 'inbox-1',
            conditions: [
                { field: 'sender', operator: 'equals', value: 'a@b.c' },
            ],
            actions: [
                { type: 'add_tag', params: { tag_name: 'x' } },
                { type: 'notify_users', params: { user_ids: [1] } },
            ],
        };

        const next = switchSubject(form, 'issue', catalog);

        expect(next.subject_type).toBe('issue');
        expect(next.trigger).toBe('issue_created');
        expect(next.inbox_uuid).toBeNull();
        expect(next.conditions).toEqual([]);
        expect(next.actions.map((a) => a.type)).toEqual(['notify_users']);
    });

    it('keeps a fallback action when nothing survives the switch', () => {
        const next = switchSubject(
            emptyRuleForm(catalog, 'conversation'),
            'issue',
            catalog,
        );

        expect(next.actions.map((a) => a.type)).toEqual(['set_issue_priority']);
    });
});

describe('catalog-driven defaults', () => {
    it('builds params from the catalog for unknown actions', () => {
        expect(defaultParams(catalog, 'set_workstream_status')).toEqual({
            team_id: null,
            status_id: null,
        });
        expect(defaultParams(catalog, 'add_team_to_issue')).toEqual({
            team_id: null,
            is_required: true,
        });
    });

    it('narrows statuses to the chosen team workflow', () => {
        expect(statusOptionsFor(lookups).map((s) => s.name)).toEqual([
            'Todo',
            'Done',
        ]);
        expect(statusOptionsFor(lookups, 2).map((s) => s.name)).toEqual([
            'Mocking up',
        ]);
    });
});

describe('plain English for issue rules', () => {
    it('describes issue actions', () => {
        expect(
            describeAction(
                { type: 'set_issue_priority', params: { priority: 'urgent' } },
                catalog,
                lookups,
            ),
        ).toBe('set priority to urgent');
        expect(
            describeAction(
                { type: 'add_team_to_issue', params: { team_id: 2 } },
                catalog,
                lookups,
            ),
        ).toBe('add team Design');
        expect(
            describeAction(
                {
                    type: 'set_workstream_status',
                    params: { team_id: 1, status_id: 200 },
                },
                catalog,
                lookups,
            ),
        ).toBe('set Engineering’s status to In review');
        expect(
            describeAction(
                { type: 'add_issue_label', params: { label_id: 7 } },
                catalog,
                lookups,
            ),
        ).toBe('add label Reports');
        expect(
            describeAction(
                {
                    type: 'add_issue_label',
                    params: { label_id: null, label_name: 'Needs QA' },
                },
                catalog,
                lookups,
            ),
        ).toBe('add label Needs QA');
        expect(
            describeAction(
                {
                    type: 'create_issue',
                    params: { product_id: 5, team_ids: [1, 2] },
                },
                catalog,
                lookups,
            ),
        ).toBe('create an issue in Frontier for Engineering and Design');
        expect(
            describeAction(
                { type: 'link_issue', params: { mode: 'subject_match' } },
                catalog,
                lookups,
            ),
        ).toBe('link the issue named in the subject');
    });

    it('describes issue conditions', () => {
        expect(
            describeCondition(
                { field: 'issue_priority', operator: 'equals', value: 'high' },
                catalog,
                lookups,
            ),
        ).toBe('priority is high');
        expect(
            describeCondition(
                { field: 'issue_team', operator: 'in', value: ['1', '2'] },
                catalog,
                lookups,
            ),
        ).toBe('participating team is any of Engineering or Design');
        expect(
            describeCondition(
                {
                    field: 'issue_days_until_due',
                    operator: 'at_most',
                    value: '3',
                },
                catalog,
                lookups,
            ),
        ).toBe('days until due is at most 3');
        expect(
            describeCondition(
                {
                    field: 'issue_has_blockers',
                    operator: 'is_true',
                    value: null,
                },
                catalog,
                lookups,
            ),
        ).toBe('it is blocked');
    });

    it('summarises an issue rule with its product and team scope', () => {
        expect(
            summarizeRule(
                {
                    subject_type: 'issue',
                    trigger: 'issue_created',
                    match: 'all',
                    product_id: 5,
                    team_id: 1,
                    conditions: [
                        {
                            field: 'issue_priority',
                            operator: 'equals',
                            value: 'urgent',
                        },
                    ],
                    actions: [
                        { type: 'add_team_to_issue', params: { team_id: 2 } },
                    ],
                },
                catalog,
                lookups,
            ),
        ).toBe(
            'When an issue is created in Frontier for Engineering, if priority is urgent, then add team Design.',
        );
    });
});
