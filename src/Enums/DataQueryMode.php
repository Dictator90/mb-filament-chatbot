<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The shapes a data resource may be asked for.
 *
 * Each one is allowed per resource rather than globally: a resource that answers
 * "how many unpaid invoices" without ever listing them is a legitimate and much
 * cheaper declaration than one that hands rows out, and it stops being that the
 * moment List is switched on for it by default.
 */
enum DataQueryMode: string implements HasLabel
{
    case List = 'list';

    case First = 'first';

    /**
     * Return only how many rows match.
     *
     * Cheap for the database and safe for the reader — no field ever leaves — but
     * still a disclosure: a count answers yes-or-no questions about a single
     * record when the filter is narrow enough. It obeys the same access scope as
     * the other modes for that reason.
     */
    case Count = 'count';

    public function getLabel(): string
    {
        return __('chatbot::panel.enums.data_query_mode.'.$this->value);
    }
}
