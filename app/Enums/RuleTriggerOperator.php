<?php

declare(strict_types=1);

namespace App\Enums;

enum RuleTriggerOperator: string
{
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
    case GreaterThan = 'greater_than';
    case LessThan = 'less_than';
    case GreaterThanOrEqual = 'greater_than_or_equal';
    case LessThanOrEqual = 'less_than_or_equal';
    case Is = 'is';
    case IsNot = 'is_not';
    case IsEmpty = 'is_empty';
    case IsNotEmpty = 'is_not_empty';

    public function label(): string
    {
        return match ($this) {
            self::Contains => 'Contains',
            self::NotContains => 'Does Not Contain',
            self::Equals => 'Equals',
            self::NotEquals => 'Does Not Equal',
            self::StartsWith => 'Starts With',
            self::EndsWith => 'Ends With',
            self::GreaterThan => 'Greater Than',
            self::LessThan => 'Less Than',
            self::GreaterThanOrEqual => 'Greater Than or Equal',
            self::LessThanOrEqual => 'Less Than or Equal',
            self::Is => 'Is',
            self::IsNot => 'Is Not',
            self::IsEmpty => 'Is Empty',
            self::IsNotEmpty => 'Is Not Empty',
        };
    }

    public function requiresValue(): bool
    {
        return match ($this) {
            self::IsEmpty, self::IsNotEmpty => false,
            default => true,
        };
    }
}
