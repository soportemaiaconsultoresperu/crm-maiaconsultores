<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Enums\ConditionOperator;
use App\Models\AutomationCondition;
use App\Models\AutomationConditionGroup;
use App\Models\AutomationRule;
use Carbon\CarbonInterface;

/**
 * Evaluates an AutomationRule's conditions against a domain-event payload.
 *
 * Group-level: conditions inside a group are joined with the group's
 * logical_operator (AND|OR). At least one condition must match for the
 * rule to be considered.
 *
 * Top-level: groups are joined with AND — every group must match for the
 * rule to fire.
 *
 * `simulation` mode evaluates identically to live; the only difference is
 * the caller (the listener) does not record an execution row.
 */
class ConditionEvaluator
{
    /**
     * Evaluate the rule against the event payload. Returns true when the
     * rule should fire.
     *
     * @param  array<string, mixed>  $eventPayload  payload from the event's
     *                                             payload() method.
     */
    public function matches(AutomationRule $rule, array $eventPayload): bool
    {
        $groups = $rule->conditionGroups()->with('conditions')->get();

        if ($groups->isEmpty()) {
            // A rule with no conditions fires for every event of the
            // matching trigger_event (intentional — useful for side-effect
            // only rules like "every time lead is created, send webhook").
            return true;
        }

        foreach ($groups as $group) {
            if (! $this->groupMatches($group, $eventPayload)) {
                return false;
            }
        }

        return true;
    }

    private function groupMatches(AutomationConditionGroup $group, array $eventPayload): bool
    {
        $conditions = $group->conditions;

        if ($conditions->isEmpty()) {
            // An empty group is treated as a no-op match (matches whatever
            // any sibling group requires), but with AND-across-groups we
            // accept it silently.
            return true;
        }

        $operator = strtoupper($group->logical_operator ?: 'AND');

        foreach ($conditions as $condition) {
            $matches = $this->conditionMatches($condition, $eventPayload);

            if ($operator === 'OR' && $matches) {
                return true;
            }

            if ($operator === 'AND' && ! $matches) {
                return false;
            }
        }

        return $operator === 'AND';
    }

    private function conditionMatches(AutomationCondition $condition, array $eventPayload): bool
    {
        $actual = $this->resolveField($condition->field, $eventPayload);
        $expected = $this->coerce($condition->value, $condition->value_type);

        return match ($condition->operator) {
            ConditionOperator::EQ => $this->compare($actual, $expected) === 0,
            ConditionOperator::NEQ => $this->compare($actual, $expected) !== 0,
            ConditionOperator::GT => $this->compare($actual, $expected) > 0,
            ConditionOperator::GTE => $this->compare($actual, $expected) >= 0,
            ConditionOperator::LT => $this->compare($actual, $expected) < 0,
            ConditionOperator::LTE => $this->compare($actual, $expected) <= 0,
            ConditionOperator::IN => $this->isIn($actual, $expected),
            ConditionOperator::NOT_IN => ! $this->isIn($actual, $expected),
            ConditionOperator::CONTAINS => is_string($actual) && is_string($expected)
                && str_contains(strtolower($actual), strtolower($expected)),
            ConditionOperator::STARTS_WITH => is_string($actual) && is_string($expected)
                && str_starts_with(strtolower($actual), strtolower($expected)),
            ConditionOperator::ENDS_WITH => is_string($actual) && is_string($expected)
                && str_ends_with(strtolower($actual), strtolower($expected)),
            ConditionOperator::IS_NULL => $actual === null || $actual === '',
            ConditionOperator::IS_NOT_NULL => $actual !== null && $actual !== '',
            ConditionOperator::BEFORE => $this->compare($actual, $expected) < 0,
            ConditionOperator::AFTER => $this->compare($actual, $expected) > 0,
            ConditionOperator::BETWEEN => $this->isBetween($actual, $expected),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $eventPayload
     */
    private function resolveField(string $field, array $eventPayload): mixed
    {
        if (! str_contains($field, '.')) {
            return $eventPayload[$field] ?? null;
        }

        $parts = explode('.', $field);
        $value = $eventPayload;

        foreach ($parts as $part) {
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
            } else {
                return null;
            }
        }

        return $value;
    }

    private function coerce(?string $value, ?string $valueType): mixed
    {
        if ($value === null || $valueType === null) {
            return $value;
        }

        return match ($valueType) {
            'int', 'integer' => (int) $value,
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'date', 'datetime' => $this->parseDate($value),
            'enum' => $value,
            'string' => $value,
            'array', 'list', 'csv' => $this->parseList($value),
            default => $value,
        };
    }

    /**
     * @param  mixed  $actual
     * @param  mixed  $expected
     */
    private function compare(mixed $actual, mixed $expected): int
    {
        if ($actual instanceof \DateTimeInterface && $expected instanceof \DateTimeInterface) {
            return $actual->getTimestamp() <=> $expected->getTimestamp();
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual <=> (float) $expected;
        }

        if ($actual instanceof CarbonInterface && $expected instanceof CarbonInterface) {
            return $actual->getTimestamp() <=> $expected->getTimestamp();
        }

        return strcmp((string) ($actual ?? ''), (string) ($expected ?? ''));
    }

    /**
     * @param  mixed  $actual
     * @param  mixed  $expected
     */
    private function isIn(mixed $actual, mixed $expected): bool
    {
        if (! is_array($expected)) {
            return false;
        }

        foreach ($expected as $candidate) {
            if ($this->compare($actual, $candidate) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  mixed  $actual
     * @param  mixed  $expected  [min, max]
     */
    private function isBetween(mixed $actual, mixed $expected): bool
    {
        if (! is_array($expected) || count($expected) !== 2) {
            return false;
        }

        [$min, $max] = $expected;

        return $this->compare($actual, $min) >= 0 && $this->compare($actual, $max) <= 0;
    }

    private function parseDate(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value);
    }

    /**
     * @return list<string>
     */
    private function parseList(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
    }
}