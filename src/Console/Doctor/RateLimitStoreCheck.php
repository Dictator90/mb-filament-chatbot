<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Doctor;

/**
 * Where the widget's rate limiter keeps its counters, and whether that survives
 * being polled.
 *
 * Every widget endpoint is behind a limiter, and a limiter counts by writing.
 * The widget polls for new messages every few seconds, per open tab, so the
 * busiest write in the whole installation is a counter nobody ever reads —
 * one per poll, in a transaction.
 *
 * That is fine on every cache store except one. With `CACHE_STORE=database` on
 * SQLite the counter is written into the same file the application reads
 * everything else from, and SQLite admits one writer at a time: two visitors
 * with the chat open, or one visitor and an operator using the panel, is enough
 * to produce `database is locked` — a 500 from the polling endpoint, which the
 * visitor sees as a chat that opens empty and never fills in.
 *
 * It cannot be found by clicking around. A single person on a laptop never
 * collides with themselves; the fault needs two writers, which means it waits
 * for the first real visitor. That is exactly what this command is for.
 *
 * A warning rather than a failure: the installation works, and a small site may
 * genuinely never see two writers at once. What it must not do is meet this for
 * the first time in production with nothing having said so.
 */
final class RateLimitStoreCheck extends DoctorCheck
{
    public static function key(): string
    {
        return 'rate-limits';
    }

    public function title(): string
    {
        return 'Rate limit counters';
    }

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array
    {
        $store = (string) config('cache.default');
        $driver = (string) config("cache.stores.{$store}.driver", $store);

        if ($driver !== 'database') {
            return [DoctorFinding::pass("Rate limit counters are kept in the [{$store}] cache.")];
        }

        $connection = (string) (config("cache.stores.{$store}.connection") ?: config('database.default'));
        $database = (string) config("database.connections.{$connection}.driver");

        if ($database !== 'sqlite') {
            return [DoctorFinding::pass(
                "Rate limit counters are kept in the [{$store}] cache on [{$connection}].",
            )];
        }

        return [DoctorFinding::warn(
            'The widget\'s rate limiter writes its counters into the same SQLite database as everything else.',
            'The widget polls every few seconds and each poll writes a counter, but SQLite allows one writer at a '
            .'time — two visitors, or a visitor and an operator, and the polling endpoint answers "database is '
            .'locked". Point CACHE_STORE at something else (file and redis both work), or move the cache to its own '
            .'connection.',
            ["cache store: {$store}", "database connection: {$connection} (sqlite)"],
        )];
    }
}
