<?php

namespace App\Services\Automations;

use App\Enums\AutomationConditionField;
use App\Enums\AutomationConditionOperator;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Str;
use Throwable;

/**
 * Evaluates a rule's condition list against a conversation. Every condition is reported
 * individually so the UI can show a dry-run and the audit log stays explainable.
 */
class ConditionEvaluator
{
    public function __construct(protected BusinessHours $businessHours) {}

    /**
     * @param  array<int, array{field?: mixed, operator?: mixed, value?: mixed}>  $conditions
     * @param  array{message?: Message|null}  $context
     * @return array{matched: bool, match: string, conditions: array<int, array{field: string, operator: string, value: mixed, actual: mixed, passed: bool, error?: string}>}
     */
    public function evaluate(array $conditions, string $match, Conversation $conversation, array $context = []): array
    {
        $match = $match === 'any' ? 'any' : 'all';
        $results = [];

        foreach ($conditions as $condition) {
            $results[] = $this->evaluateCondition($condition, $conversation, $context);
        }

        $passed = array_filter($results, fn (array $result) => $result['passed']);

        $matched = $results === []
            || ($match === 'all' ? count($passed) === count($results) : $passed !== []);

        return ['matched' => $matched, 'match' => $match, 'conditions' => $results];
    }

    /**
     * @param  array{field?: mixed, operator?: mixed, value?: mixed}  $condition
     * @param  array{message?: Message|null}  $context
     * @return array{field: string, operator: string, value: mixed, actual: mixed, passed: bool, error?: string}
     */
    public function evaluateCondition(array $condition, Conversation $conversation, array $context = []): array
    {
        $fieldKey = is_string($condition['field'] ?? null) ? $condition['field'] : '';
        $operatorKey = is_string($condition['operator'] ?? null) ? $condition['operator'] : '';
        $value = $condition['value'] ?? null;

        $result = ['field' => $fieldKey, 'operator' => $operatorKey, 'value' => $value, 'actual' => null, 'passed' => false];

        $field = AutomationConditionField::tryFrom($fieldKey);
        $operator = AutomationConditionOperator::tryFrom($operatorKey);

        if (! $field || ! $operator) {
            return $result + ['error' => 'Unknown field or operator.'];
        }

        if (! $field->supports($operator)) {
            return $result + ['error' => sprintf('Operator "%s" is not supported for "%s".', $operator->value, $field->value)];
        }

        try {
            $actual = $this->actual($field, $conversation, $context);
            $result['actual'] = $actual;
            $result['passed'] = $this->compare($field, $operator, $actual, $value);
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Resolve the live value of a field.
     *
     * @param  array{message?: Message|null}  $context
     * @return string|int|bool|array<int, string>|null
     */
    protected function actual(AutomationConditionField $field, Conversation $conversation, array $context): string|int|bool|array|null
    {
        return match ($field) {
            AutomationConditionField::Inbox => $conversation->inbox->uuid,
            AutomationConditionField::Sender => $this->inboundMessage($conversation, $context)?->fromAddress()
                ?? ($conversation->participants[0]['email'] ?? null),
            AutomationConditionField::Recipient => $this->inboundMessage($conversation, $context)?->recipientAddresses() ?? [],
            AutomationConditionField::Subject => $conversation->subject,
            AutomationConditionField::Body => $this->bodyOf($conversation, $context),
            AutomationConditionField::Tag => $conversation->tags()->pluck('slug')->map(fn (string $slug) => Str::lower($slug))->values()->all(),
            AutomationConditionField::Assignee => $conversation->assignee_id,
            AutomationConditionField::BusinessHours => $conversation->organization !== null && $this->businessHours->isOpen($conversation->organization),
            AutomationConditionField::Status => $conversation->status->value,
            AutomationConditionField::Priority => $conversation->priority->key(),
        };
    }

    /**
     * @param  string|int|bool|array<int, string>|null  $actual
     */
    protected function compare(AutomationConditionField $field, AutomationConditionOperator $operator, string|int|bool|array|null $actual, mixed $expected): bool
    {
        $isEmpty = $actual === null || $actual === '' || $actual === [];

        switch ($operator) {
            case AutomationConditionOperator::IsSet:
                return ! $isEmpty;
            case AutomationConditionOperator::IsEmpty:
                return $isEmpty;
            case AutomationConditionOperator::IsTrue:
                return $actual === true;
            case AutomationConditionOperator::IsFalse:
                return $actual === false;
        }

        $actuals = $this->normalizeActuals($actual);
        $expectedList = $this->normalizeExpected($field, $expected);

        $matched = match ($operator) {
            AutomationConditionOperator::Equals,
            AutomationConditionOperator::NotEquals,
            AutomationConditionOperator::In,
            AutomationConditionOperator::NotIn => $this->anyPair($actuals, $expectedList, fn (string $a, string $e) => $this->equals($field, $a, $e)),
            AutomationConditionOperator::Contains,
            AutomationConditionOperator::NotContains => $this->anyPair($actuals, $expectedList, fn (string $a, string $e) => $e !== '' && str_contains($a, $e)),
            AutomationConditionOperator::StartsWith => $this->anyPair($actuals, $expectedList, fn (string $a, string $e) => $e !== '' && str_starts_with($a, $e)),
            AutomationConditionOperator::EndsWith => $this->anyPair($actuals, $expectedList, fn (string $a, string $e) => $e !== '' && str_ends_with($a, $e)),
            AutomationConditionOperator::MatchesRegex => $this->anyPair($actuals, $expectedList, fn (string $a, string $e) => $this->matchesRegex($a, $e)),
        };

        return $operator->isNegated() ? ! $matched : $matched;
    }

    protected function equals(AutomationConditionField $field, string $actual, string $expected): bool
    {
        if ($field->valueType() === 'email') {
            if (str_starts_with($expected, '@')) {
                return str_ends_with($actual, $expected);
            }

            if (! str_contains($expected, '@')) {
                return str_ends_with($actual, '@'.$expected);
            }
        }

        if ($field === AutomationConditionField::Tag) {
            return $actual === Str::lower(Str::slug($expected));
        }

        return $actual === $expected;
    }

    protected function matchesRegex(string $actual, string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }

        $delimited = preg_match('/^\/.*\/[a-z]*$/s', $pattern) === 1 ? $pattern : '~'.str_replace('~', '\~', $pattern).'~iu';

        $result = @preg_match($delimited, $actual);

        return $result === 1;
    }

    /**
     * @param  array<int, string>  $actuals
     * @param  array<int, string>  $expected
     * @param  callable(string, string): bool  $test
     */
    protected function anyPair(array $actuals, array $expected, callable $test): bool
    {
        foreach ($actuals as $actual) {
            foreach ($expected as $value) {
                if ($test($actual, $value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  string|int|bool|array<int, string>|null  $actual
     * @return array<int, string>
     */
    protected function normalizeActuals(string|int|bool|array|null $actual): array
    {
        if ($actual === null) {
            return [];
        }

        if (is_bool($actual)) {
            return [$actual ? 'true' : 'false'];
        }

        return collect(is_array($actual) ? $actual : [$actual])
            ->map(fn ($item) => Str::lower(trim((string) $item)))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeExpected(AutomationConditionField $field, mixed $expected): array
    {
        $values = is_array($expected) ? $expected : [$expected];

        return collect($values)
            ->filter(fn ($item) => is_scalar($item))
            ->map(fn ($item) => Str::lower(trim((string) $item)))
            ->filter(fn (string $item) => $item !== '')
            ->map(fn (string $item) => $field === AutomationConditionField::Tag ? Str::lower(Str::slug($item)) : $item)
            ->values()
            ->all();
    }

    /**
     * @param  array{message?: Message|null}  $context
     */
    protected function bodyOf(Conversation $conversation, array $context): ?string
    {
        $message = $this->inboundMessage($conversation, $context);

        if ($message === null) {
            return $conversation->snippet;
        }

        return $message->text_body ?? $message->snippet ?? $conversation->snippet;
    }

    /**
     * @param  array{message?: Message|null}  $context
     */
    protected function inboundMessage(Conversation $conversation, array $context): ?Message
    {
        $message = $context['message'] ?? null;

        if ($message instanceof Message && $message->isInbound() && $message->conversation_id === $conversation->id) {
            return $message;
        }

        return $conversation->messages()->where('direction', 'inbound')->latest('sent_at')->latest('id')->first();
    }
}
