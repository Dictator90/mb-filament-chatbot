<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Http;

/**
 * Decides whether a scenario may call a given address, before anything is sent.
 *
 * The check lives in a class of its own rather than inside the node for one
 * reason: the interesting half of it cannot be proved otherwise. What has to be
 * shown is that a domain sitting legitimately in the allow-list, whose name
 * resolves to the loopback address, is refused — and no test can arrange real
 * DNS to say that. Here the resolution is one overridable method, so a test can
 * answer 127.0.0.1 for an ordinary-looking host and watch the guard refuse it.
 *
 * The order of the checks is the order of the risks, and every one of them has
 * to hold for the request to leave.
 */
class OutboundGuard
{
    /**
     * The reason this URL may not be called, or null when it may be.
     *
     * **The allow-list is consulted in every environment, and an empty one
     * refuses everything.** The reverse default — allow anything until somebody
     * lists a restriction — turns a forgotten setting into a node any panel user
     * can point at an internal address, and the node's whole purpose is leaving
     * the application, so there is no safe "just this once".
     *
     * **Plain http is refused outside local and testing.** A scenario's request
     * carries whatever the conversation has learned — a reference number, a
     * phone, a name — and on the open network that is readable and, worse,
     * rewritable in flight by whoever sits between.
     *
     * **Private and reserved addresses are refused after the name has been
     * resolved, never by looking at the text of the URL.** This is the check
     * everything else is arranged around. A host named in the allow-list is an
     * ordinary-looking string; what it resolves to is somebody else's decision,
     * changeable at any time and invisible here. `internal.partner.example`
     * pointing at 127.0.0.1 reaches this application's own web server, and
     * 169.254.169.254 is the cloud metadata service — one request to which
     * returns the machine's credentials. Read as a string, all three look the
     * same as any other domain.
     *
     * A name that does not resolve is refused as well. Sending the request
     * anyway would mean handing the address to the HTTP client unchecked and
     * letting its own resolver decide, which is exactly the check being skipped.
     */
    public function refusal(string $url): ?string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'the address is not a full URL with a scheme and a host';
        }

        $scheme = mb_strtolower((string) $parts['scheme']);
        $host = mb_strtolower(trim((string) $parts['host'], '[]'));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return "[{$scheme}] is not an address this node can call";
        }

        if ($scheme !== 'https' && ! $this->plainHttpAllowed()) {
            return 'plain http is only allowed in local and testing environments';
        }

        if (! $this->listed($host)) {
            return "[{$host}] is not in the allowed domains for outbound requests";
        }

        if ($this->privateNetworksAllowed()) {
            return null;
        }

        $addresses = $this->addressesFor($host);

        if ($addresses === []) {
            return "[{$host}] could not be resolved to an address";
        }

        foreach ($addresses as $address) {
            if (! $this->routable($address)) {
                return "[{$host}] resolves to [{$address}], which is a private or reserved address";
            }
        }

        return null;
    }

    /**
     * The addresses a host name stands for.
     *
     * An address written into the URL as digits is returned as itself: there is
     * nothing to resolve, and it still has to face the range check below —
     * `https://169.254.169.254/` is the plainest way to ask for the metadata
     * service and would otherwise walk past a resolver that never ran.
     *
     * Both families are looked up. An IPv6-only answer that skipped the check
     * would let ::1 and fc00::/7 through the one door this guard exists to
     * close.
     *
     * @return list<string>
     */
    protected function addressesFor(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = gethostbynamel($host);
        $addresses = $addresses === false ? [] : $addresses;

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = (string) $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Whether an address is one the outside world routes to.
     *
     * PHP's own range flags are used rather than a hand-written table of
     * prefixes, because the table is what gets it wrong: the reserved set alone
     * is 0.0.0.0/8, 127.0.0.0/8, 169.254.0.0/16, 240.0.0.0/4 and their IPv6
     * counterparts, and a list of them copied into this file is a list that
     * ages.
     */
    protected function routable(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * Whether the host is one of the allowed domains, or below one.
     *
     * A listed domain covers its subdomains, because that is what a person
     * writing "example.com" into a settings file means. The match is anchored
     * on a dot for the obvious reason: without it, "notexample.com" ends with
     * "example.com" and would be allowed by a list that never mentioned it.
     */
    protected function listed(string $host): bool
    {
        foreach ((array) config('chatbot.http.allowed_domains', []) as $domain) {
            if (! is_string($domain)) {
                continue;
            }

            $domain = mb_strtolower(trim($domain, ". \t\n\r\0\x0B"));

            if ($domain === '') {
                continue;
            }

            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    protected function privateNetworksAllowed(): bool
    {
        return (bool) config('chatbot.http.allow_private_networks', false);
    }

    protected function plainHttpAllowed(): bool
    {
        return app()->environment(['local', 'testing']);
    }
}
