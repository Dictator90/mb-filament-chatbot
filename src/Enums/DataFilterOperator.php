<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The comparisons a data-resource filter may use.
 *
 * A closed set, and each field says which of them it accepts. The list is not a
 * convenience: an operator nobody allowed is refused rather than swapped for a
 * neighbouring one, because a filter quietly widened from equals to contains
 * returns other people's rows and still looks like a correct answer.
 */
enum DataFilterOperator: string implements HasLabel
{
    case Equals = 'equals';

    case NotEquals = 'not_equals';

    case In = 'in';

    case NotIn = 'not_in';

    case LessThan = 'lt';

    case LessOrEqual = 'lte';

    case GreaterThan = 'gt';

    case GreaterOrEqual = 'gte';

    /**
     * Substring match, and the most expensive operator here in two ways.
     *
     * It is offered only to fields explicitly marked searchable, and the value is
     * escaped for LIKE before it is bound. Without the escaping, a single "%"
     * typed into the chat turns a filter into a full scan that matches every row
     * — a table scan the visitor chose, and a result set nobody restricted.
     */
    case Contains = 'contains';

    case StartsWith = 'starts_with';

    case Between = 'between';

    case IsNull = 'is_null';

    case IsNotNull = 'is_not_null';

    /**
     * Operators that need no value at all, so a runtime knows not to demand one.
     *
     * @return array<int, self>
     */
    public static function valueless(): array
    {
        return [self::IsNull, self::IsNotNull];
    }

    /**
     * Operators that read a value as text and therefore need a searchable field.
     *
     * @return array<int, self>
     */
    public static function textual(): array
    {
        return [self::Contains, self::StartsWith];
    }

    public function getLabel(): string
    {
        return __('chatbot::panel.enums.data_filter_operator.'.$this->value);
    }
}
