<?php

namespace App\Services\Projects;

/**
 * The fields, operators and value shapes an issue filter may use. Shared by IssueQuery (to apply
 * them) and SaveIssueViewRequest (to validate saved views), so the two can never drift apart.
 */
class IssueFilterCatalog
{
    public const MATCH = ['all', 'any'];

    /**
     * field => [value type, allowed operators]
     *
     * @var array<string, array{0: string, 1: array<int, string>}>
     */
    public const FIELDS = [
        'status' => ['id', ['in', 'not_in']],
        'status_category' => ['category', ['in', 'not_in']],
        'priority' => ['priority', ['in', 'not_in']],
        'owner' => ['user', ['in', 'not_in', 'is_empty', 'is_not_empty']],
        'assignee' => ['user', ['in', 'not_in', 'is_empty', 'is_not_empty']],
        'team' => ['id', ['in', 'not_in', 'is_empty', 'is_not_empty']],
        'lead_team' => ['id', ['in', 'not_in', 'is_empty', 'is_not_empty']],
        'product' => ['id', ['in', 'not_in', 'is_empty', 'is_not_empty']],
        'module' => ['id', ['in', 'not_in', 'is_empty', 'is_not_empty']],
        'project' => ['id', ['in', 'not_in', 'is_empty', 'is_not_empty']],
        'milestone' => ['id', ['in', 'not_in', 'is_empty', 'is_not_empty']],
        'cycle' => ['id', ['in', 'not_in', 'is_empty', 'is_not_empty']],
        'release' => ['id', ['in', 'not_in', 'is_empty', 'is_not_empty']],
        'label' => ['id', ['in', 'not_in', 'is_empty', 'is_not_empty']],
        'parent' => ['id', ['in', 'is_empty', 'is_not_empty']],
        'created_by' => ['user', ['in', 'not_in']],
        'follower' => ['user', ['in']],
        'blocked' => ['boolean', ['is_true', 'is_false']],
        'ready_to_complete' => ['boolean', ['is_true', 'is_false']],
        'has_sub_issues' => ['boolean', ['is_true', 'is_false']],
        'due_date' => ['date', ['before', 'after', 'between', 'is_empty', 'is_not_empty', 'overdue']],
        'start_date' => ['date', ['before', 'after', 'between', 'is_empty', 'is_not_empty']],
        'created_at' => ['date', ['before', 'after', 'between']],
        'updated_at' => ['date', ['before', 'after', 'between']],
        'completed_at' => ['date', ['before', 'after', 'between', 'is_empty', 'is_not_empty']],
        'estimate' => ['number', ['eq', 'gt', 'lt', 'is_empty', 'is_not_empty']],
        'title' => ['text', ['contains', 'not_contains']],
    ];

    public const SORTS = ['manual', 'priority', 'status', 'created_at', 'updated_at', 'due_date', 'title', 'estimate', 'identifier', 'last_activity_at'];

    public const GROUPS = ['none', 'status', 'status_category', 'priority', 'owner', 'assignee', 'team', 'project', 'product', 'module', 'cycle', 'label', 'milestone', 'due_date'];

    public const NO_VALUE_OPERATORS = ['is_empty', 'is_not_empty', 'is_true', 'is_false', 'overdue'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fields' => collect(self::FIELDS)->map(fn (array $def, string $field) => ['field' => $field, 'value_type' => $def[0], 'operators' => $def[1]])->values()->all(),
            'sorts' => self::SORTS,
            'groups' => self::GROUPS,
            'match' => self::MATCH,
        ];
    }

    /**
     * @param  array<string, mixed>  $clause
     */
    public function isValidClause(array $clause): bool
    {
        $field = $clause['field'] ?? null;
        $operator = $clause['op'] ?? $clause['operator'] ?? null;

        if (! is_string($field) || ! isset(self::FIELDS[$field]) || ! is_string($operator)) {
            return false;
        }

        if (! in_array($operator, self::FIELDS[$field][1], true)) {
            return false;
        }

        if (in_array($operator, self::NO_VALUE_OPERATORS, true)) {
            return true;
        }

        return array_key_exists('value', $clause) && $clause['value'] !== null && $clause['value'] !== '' && $clause['value'] !== [];
    }

    /**
     * Normalise user-provided filters into `{match, groups: [{match, clauses: [...]}]}`,
     * dropping anything unknown. Accepts the legacy flat `{field: value}` shape too.
     *
     * @param  array<string, mixed>|null  $filters
     * @return array{q: string|null, match: string, groups: array<int, array{match: string, clauses: array<int, array{field: string, op: string, value: mixed}>}>}
     */
    public function normalize(?array $filters): array
    {
        $filters ??= [];
        $match = $filters['match'] ?? 'all';
        $match = in_array($match, self::MATCH, true) ? $match : 'all';
        $groups = [];

        foreach ((array) ($filters['groups'] ?? []) as $group) {
            if (! is_array($group)) {
                continue;
            }

            $clauses = [];

            foreach ((array) ($group['clauses'] ?? []) as $clause) {
                if (is_array($clause) && $this->isValidClause($clause)) {
                    $clauses[] = [
                        'field' => $clause['field'],
                        'op' => $clause['op'] ?? $clause['operator'],
                        'value' => $clause['value'] ?? null,
                    ];
                }
            }

            if ($clauses !== []) {
                $groupMatch = $group['match'] ?? 'all';
                $groups[] = ['match' => in_array($groupMatch, self::MATCH, true) ? $groupMatch : 'all', 'clauses' => $clauses];
            }
        }

        return ['q' => filled($filters['q'] ?? null) ? (string) $filters['q'] : null, 'match' => $match, 'groups' => $groups];
    }
}
