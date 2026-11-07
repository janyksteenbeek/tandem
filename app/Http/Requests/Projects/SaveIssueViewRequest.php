<?php

namespace App\Http\Requests\Projects;

use App\Enums\Projects\IssueViewScope;
use App\Http\Requests\OrganizationScopedRequest;
use App\Services\Projects\IssueFilterCatalog;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveIssueViewRequest extends OrganizationScopedRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organization = $this->organization();

        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:32'],
            'color' => ['nullable', 'string', 'max:32'],
            'scope' => ['nullable', Rule::enum(IssueViewScope::class)],
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')->where('organization_id', $organization->id)],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('organization_id', $organization->id)],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('organization_id', $organization->id)],
            'filters' => ['nullable', 'array'],
            'display' => ['nullable', 'array'],
            'display.mode' => ['nullable', Rule::in(['list', 'board', 'calendar', 'timeline'])],
            'display.group_by' => ['nullable', Rule::in(IssueFilterCatalog::GROUPS)],
            'display.sort' => ['nullable', 'array'],
            'display.sort.field' => ['nullable', Rule::in(IssueFilterCatalog::SORTS)],
            'display.sort.dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'display.columns' => ['nullable', 'array'],
            'display.show_completed' => ['nullable', Rule::in(['all', 'recent', 'none'])],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $catalog = app(IssueFilterCatalog::class);

                foreach ((array) $this->input('filters.groups', []) as $g => $group) {
                    foreach ((array) ($group['clauses'] ?? []) as $c => $clause) {
                        if (! is_array($clause) || ! $catalog->isValidClause($clause)) {
                            $validator->errors()->add("filters.groups.$g.clauses.$c", 'That filter is not valid.');
                        }
                    }
                }

                if ($this->input('scope') === 'team' && ! $this->filled('team_id')) {
                    $validator->errors()->add('team_id', 'Pick the team to share this view with.');
                }
            },
        ];
    }
}
