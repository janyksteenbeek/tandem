<?php

namespace App\Http\Requests\Automations;

use App\Enums\AutomationActionType;
use App\Enums\AutomationConditionField;
use App\Enums\AutomationConditionOperator;
use App\Enums\AutomationTrigger;
use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Models\Inbox;
use App\Models\MessageTemplate;
use App\Models\Tag;
use App\Models\Team;
use App\Services\Automations\TeamAssigner;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveAutomationRuleRequest extends OrganizationScopedRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organization = $this->organization();

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'inbox_uuid' => ['nullable', 'string', Rule::exists('inboxes', 'uuid')->where('organization_id', $organization->id)->whereNull('deleted_at')],
            'trigger' => ['required', Rule::enum(AutomationTrigger::class)],
            'match' => ['required', Rule::in(['all', 'any'])],
            'is_active' => ['sometimes', 'boolean'],
            'cooldown_minutes' => ['nullable', 'integer', 'min:0', 'max:525600'],
            'conditions' => ['present', 'array', 'max:25'],
            'conditions.*' => ['array:field,operator,value'],
            'conditions.*.field' => ['required', Rule::enum(AutomationConditionField::class)],
            'conditions.*.operator' => ['required', Rule::enum(AutomationConditionOperator::class)],
            'conditions.*.value' => ['nullable'],
            'conditions.*.value.*' => ['string', 'max:500'],
            'actions' => ['required', 'array', 'min:1', 'max:15'],
            'actions.*' => ['array:type,params'],
            'actions.*.type' => ['required', Rule::enum(AutomationActionType::class)],
            'actions.*.params' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ($this->input('conditions', []) as $index => $condition) {
                    $this->validateCondition($validator, $index, is_array($condition) ? $condition : []);
                }

                foreach ($this->input('actions', []) as $index => $action) {
                    $this->validateAction($validator, $index, is_array($action) ? $action : []);
                }
            },
        ];
    }

    /**
     * Normalised payload ready for the model.
     *
     * @return array<string, mixed>
     */
    public function rulePayload(): array
    {
        $inbox = $this->validated('inbox_uuid')
            ? Inbox::query()->where('organization_id', $this->organization()->id)->where('uuid', $this->validated('inbox_uuid'))->first()
            : null;

        $conditions = $this->validated('conditions');
        $actions = $this->validated('actions');

        return [
            'inbox_id' => $inbox?->id,
            'name' => $this->validated('name'),
            'description' => $this->validated('description'),
            'trigger' => AutomationTrigger::from($this->validated('trigger')),
            'match' => $this->validated('match'),
            'is_active' => $this->boolean('is_active', true),
            'cooldown_minutes' => $this->validated('cooldown_minutes'),
            'conditions' => collect(is_array($conditions) ? $conditions : [])
                ->map(fn (array $condition) => [
                    'field' => $condition['field'],
                    'operator' => $condition['operator'],
                    'value' => $this->normalizeConditionValue($condition),
                ])
                ->values()
                ->all(),
            'actions' => collect(is_array($actions) ? $actions : [])
                ->map(fn (array $action) => ['type' => $action['type'], 'params' => $action['params'] ?? []])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    protected function normalizeConditionValue(array $condition): mixed
    {
        $operator = AutomationConditionOperator::from($condition['operator']);
        $value = $condition['value'] ?? null;

        return match ($operator->valueShape()) {
            'none' => null,
            'array' => array_values(array_map(fn ($item) => (string) $item, is_array($value) ? $value : [$value])),
            default => is_array($value) ? (string) ($value[0] ?? '') : (string) $value,
        };
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    protected function validateCondition(Validator $validator, int|string $index, array $condition): void
    {
        $field = AutomationConditionField::tryFrom((string) ($condition['field'] ?? ''));
        $operator = AutomationConditionOperator::tryFrom((string) ($condition['operator'] ?? ''));

        if (! $field || ! $operator) {
            return;
        }

        if (! $field->supports($operator)) {
            $validator->errors()->add("conditions.$index.operator", sprintf('The "%s" operator cannot be used with the %s field.', $operator->label(), strtolower($field->label())));

            return;
        }

        $value = $condition['value'] ?? null;

        switch ($operator->valueShape()) {
            case 'array':
                if (! is_array($value) || $value === [] || array_filter($value, fn ($item) => ! is_scalar($item)) !== []) {
                    $validator->errors()->add("conditions.$index.value", 'This operator needs a list of values.');
                }
                break;
            case 'string':
                if (is_array($value) || $value === null || trim((string) $value) === '') {
                    $validator->errors()->add("conditions.$index.value", 'A value is required for this condition.');
                } elseif ($operator === AutomationConditionOperator::MatchesRegex && ! $this->isValidRegex((string) $value)) {
                    $validator->errors()->add("conditions.$index.value", 'The regular expression is invalid.');
                }
                break;
        }

        if ($operator->valueShape() === 'none') {
            return;
        }

        $values = collect(is_array($value) ? $value : [$value])->filter(fn ($item) => is_scalar($item))->map(fn ($item) => (string) $item);

        match ($field) {
            AutomationConditionField::Status => $values->each(function (string $status) use ($validator, $index) {
                if (! ConversationStatus::tryFrom($status)) {
                    $validator->errors()->add("conditions.$index.value", 'Unknown conversation status.');
                }
            }),
            AutomationConditionField::Priority => $values->each(function (string $priority) use ($validator, $index) {
                if (! in_array(strtolower($priority), array_column(ConversationPriority::options(), 'value'), true)) {
                    $validator->errors()->add("conditions.$index.value", 'Unknown priority.');
                }
            }),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $action
     */
    protected function validateAction(Validator $validator, int|string $index, array $action): void
    {
        $type = AutomationActionType::tryFrom((string) ($action['type'] ?? ''));

        if (! $type) {
            return;
        }

        $params = is_array($action['params'] ?? null) ? $action['params'] : [];
        $organization = $this->organization();
        $prefix = "actions.$index.params";

        $memberIds = fn () => $organization->members()->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        switch ($type) {
            case AutomationActionType::ReplyWithTemplate:
                $templateId = $params['template_id'] ?? null;

                if (! is_numeric($templateId) || ! MessageTemplate::query()->where('organization_id', $organization->id)->whereKey((int) $templateId)->exists()) {
                    $validator->errors()->add("$prefix.template_id", 'Choose a message template of this organization.');
                }

                if (array_key_exists('cooldown_minutes', $params) && $params['cooldown_minutes'] !== null && (! is_numeric($params['cooldown_minutes']) || (int) $params['cooldown_minutes'] < 0)) {
                    $validator->errors()->add("$prefix.cooldown_minutes", 'The cooldown must be a positive number of minutes.');
                }
                break;

            case AutomationActionType::AssignUser:
            case AutomationActionType::AddFollower:
                if (! is_numeric($params['user_id'] ?? null) || ! in_array((int) $params['user_id'], $memberIds(), true)) {
                    $validator->errors()->add("$prefix.user_id", 'Choose a member of this organization.');
                }
                break;

            case AutomationActionType::AssignTeam:
                if (! is_numeric($params['team_id'] ?? null) || ! Team::query()->where('organization_id', $organization->id)->whereKey((int) $params['team_id'])->exists()) {
                    $validator->errors()->add("$prefix.team_id", 'Choose a team of this organization.');
                }

                if (! in_array($params['strategy'] ?? null, TeamAssigner::STRATEGIES, true)) {
                    $validator->errors()->add("$prefix.strategy", 'Choose an assignment strategy.');
                }
                break;

            case AutomationActionType::MoveToInbox:
                if (! is_string($params['inbox_uuid'] ?? null) || ! Inbox::query()->where('organization_id', $organization->id)->where('uuid', $params['inbox_uuid'])->exists()) {
                    $validator->errors()->add("$prefix.inbox_uuid", 'Choose an inbox of this organization.');
                }
                break;

            case AutomationActionType::AddTag:
            case AutomationActionType::RemoveTag:
                $hasId = is_numeric($params['tag_id'] ?? null);
                $hasName = is_string($params['tag_name'] ?? null) && trim($params['tag_name']) !== '';

                if (! $hasId && ! $hasName) {
                    $validator->errors()->add("$prefix.tag_id", 'Choose a tag or enter a tag name.');
                } elseif ($hasId && ! Tag::query()->where('organization_id', $organization->id)->whereKey((int) $params['tag_id'])->exists()) {
                    $validator->errors()->add("$prefix.tag_id", 'Choose a tag of this organization.');
                } elseif ($hasName && mb_strlen(trim($params['tag_name'])) > 60) {
                    $validator->errors()->add("$prefix.tag_name", 'Tag names may not exceed 60 characters.');
                }
                break;

            case AutomationActionType::SetPriority:
                if (! is_string($params['priority'] ?? null) || ! in_array(strtolower($params['priority']), array_column(ConversationPriority::options(), 'value'), true)) {
                    $validator->errors()->add("$prefix.priority", 'Choose a priority.');
                }
                break;

            case AutomationActionType::SetStatus:
                $status = is_string($params['status'] ?? null) ? ConversationStatus::tryFrom($params['status']) : null;

                if (! $status || $status === ConversationStatus::Snoozed) {
                    $validator->errors()->add("$prefix.status", 'Choose open, pending or closed.');
                }
                break;

            case AutomationActionType::NotifyUsers:
                $ids = $params['user_ids'] ?? null;

                if (! is_array($ids) || $ids === [] || array_diff(array_map('intval', array_filter($ids, 'is_numeric')), $memberIds()) !== [] || count(array_filter($ids, 'is_numeric')) !== count($ids)) {
                    $validator->errors()->add("$prefix.user_ids", 'Choose one or more members of this organization.');
                }

                if (array_key_exists('message', $params) && $params['message'] !== null && (! is_string($params['message']) || mb_strlen($params['message']) > 500)) {
                    $validator->errors()->add("$prefix.message", 'The message may not exceed 500 characters.');
                }
                break;

            case AutomationActionType::AddComment:
                if (! is_string($params['body'] ?? null) || trim($params['body']) === '' || mb_strlen($params['body']) > 5000) {
                    $validator->errors()->add("$prefix.body", 'Enter a comment of at most 5000 characters.');
                }
                break;
        }
    }

    protected function isValidRegex(string $pattern): bool
    {
        $delimited = preg_match('/^\/.*\/[a-z]*$/s', $pattern) === 1 ? $pattern : '~'.str_replace('~', '\~', $pattern).'~iu';

        return @preg_match($delimited, '') !== false;
    }
}
