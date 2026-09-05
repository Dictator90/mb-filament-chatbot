<?php

declare(strict_types=1);

namespace MB\FilamentChatbot;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use MB\FilamentChatbot\Channels\Drivers\SiteDriver;
use MB\FilamentChatbot\Channels\Drivers\TelegramDriver;
use MB\FilamentChatbot\Channels\MessageRouter;
use MB\FilamentChatbot\Channels\Outbox;
use MB\FilamentChatbot\Http\VisitorToken;
use MB\FilamentChatbot\Models\Message;
use MB\FilamentChatbot\Observers\MessageObserver;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * Everything the channels need in order to exist.
 *
 * A provider of its own rather than more methods on the main one, because these
 * registrations are the ones a host is most likely to want to take over: an
 * application that routes chat through its own gateway replaces the drivers, one
 * that mounts the widget under a different path replaces the routes, and one
 * that has its own rate limiting replaces the limiters. Split out, all three are
 * `$this->app->register()` of a subclass; folded into the main provider, they
 * would be reachable only by forking it.
 */
class ChannelsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Singletons because both are stateless collaborators resolved from an
         * observer that fires on every message written, and from a controller on
         * every widget poll. The outbox in particular only holds the registry.
         */
        $this->app->singleton(Outbox::class);
        $this->app->singleton(MessageRouter::class);
    }

    public function boot(): void
    {
        $this->registerBuiltInChannels();
        $this->registerRateLimiters();

        Message::observe(MessageObserver::class);

        $this->loadRoutesFrom(__DIR__.'/../routes/chatbot.php');
    }

    /**
     * Adds the package's own drivers without displacing the host's.
     *
     * A claimed key is left alone, exactly as the built-in steps and slots are:
     * whether this provider boots before or after the host's own depends only on
     * the order of the composer manifest, so registering unconditionally would
     * honour a host's replacement driver on some installations and silently
     * revert it on others — sending customer messages through the wrong gateway
     * in production while working correctly on the developer's machine.
     */
    protected function registerBuiltInChannels(): void
    {
        $registry = $this->app->make(ChatbotRegistry::class);

        foreach ([SiteDriver::class, TelegramDriver::class] as $driver) {
            $instance = $this->app->make($driver);

            if ($registry->hasChannel($instance->key())) {
                continue;
            }

            $registry->registerChannel($instance);
        }
    }

    /**
     * Named limiters, one per endpoint.
     *
     * **Keyed by the visitor's token where there is one, and by IP otherwise.**
     * IP alone is wrong in both directions: an office, a school or a mobile
     * carrier puts thousands of different people behind one address, and one
     * chatty visitor there would lock out everybody else; meanwhile anyone who
     * actually wants to flood the endpoint changes address more easily than they
     * change anything else. The token is what a real conversation has and a
     * flood does not.
     *
     * The token is hashed into the key rather than used raw, because limiter
     * keys reach the cache — and a shared Redis holding live credentials in its
     * key names is the same leak the visitor_key column exists to avoid.
     */
    protected function registerRateLimiters(): void
    {
        $limits = (array) config('chatbot.widget.rate_limits', []);

        $endpoints = [
            'chatbot-site-history' => 120,
            'chatbot-site-send' => 20,
            'chatbot-site-read' => 60,
            'chatbot-site-typing' => 60,
            'chatbot-webhook' => 300,
        ];

        foreach ($endpoints as $name => $fallback) {
            $perMinute = max(1, (int) ($limits[$name] ?? $fallback));

            RateLimiter::for($name, static fn (Request $request): Limit => Limit::perMinute($perMinute)
                ->by(self::limiterKey($request, $name)));
        }
    }

    private static function limiterKey(Request $request, string $name): string
    {
        $token = VisitorToken::fromRequest($request);

        $identity = $token === null
            ? (string) $request->ip()
            : VisitorToken::hash($token);

        return $name.'|'.$identity;
    }
}
