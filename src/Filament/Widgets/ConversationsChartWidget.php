<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Widgets;

use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MB\FilamentChatbot\Filament\Widgets\Concerns\ReadsChatStatistics;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * Conversations per day, one line per channel.
 *
 * A line per channel rather than one line of totals, and rather than a stacked
 * bar, because the failure this chart is read for is a channel going quiet: a
 * webhook that stopped being delivered, a token that expired, a bot somebody
 * removed from a group. In a total — and equally in a stack, where the other
 * channels close over the gap — the day a channel dies looks like an ordinary
 * slow day, and nobody finds out until a customer complains that they wrote
 * weeks ago. As separate lines it is a line reaching zero and staying there.
 *
 * The chart shows what a host already has. Only the channels that carried a
 * conversation in the window get a line, so a single-channel installation sees
 * exactly the plain line it would have got anyway.
 */
class ConversationsChartWidget extends ChartWidget
{
    use ReadsChatStatistics;

    /**
     * Two weeks: long enough for a weekly rhythm to be visible twice, short
     * enough that a column is still a day rather than a smear.
     */
    protected const int WINDOW_DAYS = 14;

    /**
     * Line colours, given as literals because a Chart.js dataset needs a colour
     * a browser understands and Filament's palette names are not that. Deep
     * enough to tell apart on both a light and a dark dashboard.
     *
     * @var list<string>
     */
    private const array SERIES_COLOURS = [
        'rgb(37, 99, 235)',
        'rgb(22, 163, 74)',
        'rgb(217, 119, 6)',
        'rgb(219, 39, 119)',
        'rgb(8, 145, 178)',
        'rgb(124, 58, 237)',
    ];

    protected ?string $heading = 'Conversations per day';

    protected ?string $description = 'Threads somebody spoke in, by the channel they arrived on.';

    protected ?string $maxHeight = '260px';

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * One row per day and channel, days with nothing in them included.
     *
     * Public for the same reason the stats widget's counters are: a test that
     * reads the rendered chart proves the labels and nothing about the figures.
     *
     * Every day in the window is present even when nothing happened on it. Left
     * out, Chart.js draws a straight line from the day before to the day after
     * and a quiet weekend becomes a gentle slope — which is exactly the shape a
     * healthy channel has, so a channel that went silent for three days looks
     * like one that was merely slow.
     *
     * @return array{days: list<string>, channels: array<string, list<int>>}
     */
    public function daily(): array
    {
        return $this->remember('daily', function (): array {
            $since = $this->since(self::WINDOW_DAYS - 1);
            $day = $this->dayExpression();

            $rows = Conversation::query()
                ->whereNotNull('last_message_at')
                ->where('created_at', '>=', $since)
                ->selectRaw($day.' as day')
                ->selectRaw('channel')
                ->selectRaw('count(*) as total')
                ->groupByRaw($day.', channel')
                ->get();

            $days = [];

            for ($cursor = $since->copy(); $cursor->lessThanOrEqualTo(now()); $cursor = $cursor->addDay()) {
                $days[] = $cursor->format('Y-m-d');
            }

            $channels = [];

            foreach ($rows as $row) {
                $channel = (string) $row->channel;
                $channels[$channel] ??= array_fill_keys($days, 0);

                $stamp = substr((string) $row->day, 0, 10);

                if (array_key_exists($stamp, $channels[$channel])) {
                    $channels[$channel][$stamp] = (int) $row->total;
                }
            }

            return [
                'days' => $days,
                'channels' => array_map(array_values(...), $channels),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $daily = $this->daily();
        $colours = self::SERIES_COLOURS;
        $datasets = [];

        foreach ($daily['channels'] as $channel => $totals) {
            $colour = $colours[count($datasets) % count($colours)];

            $datasets[] = [
                'label' => $this->channelLabel($channel),
                'data' => $totals,
                'borderColor' => $colour,
                'backgroundColor' => $colour,
                'tension' => 0.3,
                'fill' => false,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => array_map(
                static fn (string $day): string => substr($day, 8, 2).'.'.substr($day, 5, 2),
                $daily['days'],
            ),
        ];
    }

    /**
     * @return array<string, mixed>|RawJs|null
     */
    protected function getOptions(): array|RawJs|null
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    /*
                     * Conversations are whole things; Chart.js would otherwise
                     * label a quiet fortnight in halves and quarters.
                     */
                    'ticks' => ['precision' => 0],
                ],
            ],
        ];
    }

    /**
     * What the driver calls itself, falling back to the stored key.
     *
     * The fallback is not decoration. Conversations outlive the package that
     * brought their channel — a host removes a messenger integration and the
     * threads stay — and a chart that asked a missing driver for its name would
     * fail to draw at all, taking the other channels down with it.
     */
    private function channelLabel(string $channel): string
    {
        return app(ChatbotRegistry::class)->channel($channel)?->label()
            ?? Str::headline($channel);
    }

    /**
     * The SQL for "the day this row belongs to", spelled for the connection in
     * use.
     *
     * Grouping in SQL rather than pulling the window into PHP and bucketing it
     * there: a busy installation has thousands of conversations a fortnight,
     * and a dashboard that loaded all of them to count them would get slower
     * the better the chat did. The expression has to be written per driver
     * because there is no portable one — `date()` is a function on MySQL and
     * SQLite and does not exist on PostgreSQL, where a cast is the answer.
     *
     * Days are cut by the stored timestamp, which is the application's own
     * clock: Laravel writes and reads timestamps in `app.timezone` without
     * converting, so the boundaries are the ones the host's staff mean.
     */
    private function dayExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql', 'sqlsrv' => 'cast(created_at as date)',
            default => 'date(created_at)',
        };
    }
}
