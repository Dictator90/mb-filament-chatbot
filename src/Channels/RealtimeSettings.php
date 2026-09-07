<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels;

/**
 * What a widget needs to open a socket, and nothing more.
 *
 * Everything here is already public the moment the widget connects: an app key
 * is an identifier, not a credential — the secret it is paired with never
 * leaves the server and is only ever used to sign a subscription this package
 * has already decided to allow. The distinction matters because this object is
 * handed to a script on somebody else's page, so anything mistakenly put in it
 * is published.
 *
 * Reading the host's own broadcasting connection rather than keeping a second
 * copy of it: two spellings of one address disagree eventually, and when they
 * do the widget connects to nothing while the panel insists it is configured.
 */
final class RealtimeSettings
{
    /**
     * @param  array<string, mixed>  $values
     */
    private function __construct(private readonly array $values) {}

    public static function resolve(): self
    {
        if (! config('chatbot.realtime.enabled', false)) {
            return new self(['enabled' => false]);
        }

        $connection = (string) (config('chatbot.realtime.driver') ?: config('broadcasting.default'));
        $options = (array) config("broadcasting.connections.{$connection}.options", []);
        $key = (string) config("broadcasting.connections.{$connection}.key", '');
        $host = (string) ($options['host'] ?? '');

        /*
         * A connection that names no key or no host is not "partly configured",
         * it is off. Announced as off rather than half-announced, so the widget
         * never opens a socket that cannot complete a handshake and then sits
         * through the connect timeout before falling back to the poll it could
         * have used from the start.
         */
        if ($key === '' || $host === '') {
            return new self(['enabled' => false]);
        }

        return new self([
            'enabled' => true,
            'key' => $key,
            'host' => $host,
            'port' => (int) ($options['port'] ?? 443),
            'scheme' => ($options['scheme'] ?? 'https') === 'https' ? 'wss' : 'ws',
            'cluster' => (string) ($options['cluster'] ?? ''),
            'ping_seconds' => (int) config('chatbot.realtime.ping_seconds', 30),
            'timeout_seconds' => (int) config('chatbot.realtime.connect_timeout_seconds', 5),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    public function enabled(): bool
    {
        return ($this->values['enabled'] ?? false) === true;
    }
}
