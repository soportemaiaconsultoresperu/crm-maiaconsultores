<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Operators recognised by the ConditionEvaluator.
 *
 * `value_type` (string|int|bool|date|datetime|enum) drives coercion in the
 * evaluator; the operator list below is exhaustive for the V2 engine.
 */
final class ConditionOperator
{
    public const EQ = 'eq';
    public const NEQ = 'neq';
    public const GT = 'gt';
    public const GTE = 'gte';
    public const LT = 'lt';
    public const LTE = 'lte';
    public const IN = 'in';
    public const NOT_IN = 'not_in';
    public const CONTAINS = 'contains';
    public const STARTS_WITH = 'starts_with';
    public const ENDS_WITH = 'ends_with';
    public const IS_NULL = 'is_null';
    public const IS_NOT_NULL = 'is_not_null';
    public const BEFORE = 'before';
    public const AFTER = 'after';
    public const BETWEEN = 'between';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::EQ,
            self::NEQ,
            self::GT,
            self::GTE,
            self::LT,
            self::LTE,
            self::IN,
            self::NOT_IN,
            self::CONTAINS,
            self::STARTS_WITH,
            self::ENDS_WITH,
            self::IS_NULL,
            self::IS_NOT_NULL,
            self::BEFORE,
            self::AFTER,
            self::BETWEEN,
        ];
    }
}