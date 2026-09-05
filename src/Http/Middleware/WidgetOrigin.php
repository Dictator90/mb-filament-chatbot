<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides which sites may talk to the widget endpoints, and answers CORS.
 *
 * **These routes carry no CSRF token, and cannot.** They are called from another
 * origin by a script tag on a customer's marketing page: there is no session to
 * put a token in and no server-rendered form to read one from. What replaces it
 * is this allow-list plus the visitor token — the first says which sites may ask
 * at all, the second says which conversation the asker owns. A forged
 * cross-site request is therefore stopped by not being on the list, not by a
 * token the browser would happily have attached anyway.
 *
 * **An empty allow-list blocks everything.** The opposite default would make a
 * forgotten setting into an open endpoint: anyone could embed the widget on
 * their own site, open conversations here and spend this installation's model
 * budget having them answered, and it would look exactly like ordinary traffic
 * from the panel.
 *
 * **A request with no Origin header is refused too.** The widget is by
 * definition cross-origin and every request it makes carries a custom header,
 * which forces a preflight and puts an Origin on the real request as well. What
 * arrives without one is therefore not the widget — it is a server, a script or
 * a crawler, none of which the allow-list can vouch for.
 */
class WidgetOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('chatbot.widget.enabled', true)) {
            return $this->refuse('The chat widget is switched off.');
        }

        $origin = trim((string) $request->headers->get('Origin', ''));

        if (! $this->allows($origin)) {
            return $this->refuse('This origin may not use the chat widget.');
        }

        /*
         * A preflight is answered here rather than routed. It arrives as OPTIONS
         * on the same path, carries no token and no body, and running it through
         * the controller would mean every endpoint growing a branch for a request
         * that is asking permission rather than doing anything.
         */
        $response = $request->isMethod('OPTIONS')
            ? response()->noContent()
            : $next($request);

        return $this->cors($response, $origin);
    }

    /**
     * Exact origin matching: scheme, host and port together.
     *
     * Not a host comparison and not a suffix match. `https://shop.example.com`
     * and `http://shop.example.com` are different origins to the browser and
     * must be different here too, or an allow-list entry quietly covers the
     * plaintext version of the site as well. Suffix matching would be worse
     * still: `example.com` would admit `example.com.attacker.net`.
     */
    private function allows(string $origin): bool
    {
        if ($origin === '') {
            return false;
        }

        $allowed = (array) config('chatbot.widget.origins', []);

        foreach ($allowed as $candidate) {
            if ($this->normalise((string) $candidate) === $this->normalise($origin)) {
                return true;
            }
        }

        return false;
    }

    private function normalise(string $origin): string
    {
        return rtrim(mb_strtolower(trim($origin)), '/');
    }

    /**
     * Echoes the one origin that was allowed, never a wildcard.
     *
     * `Vary: Origin` goes with it, because without it a shared cache can serve
     * the headers computed for one site to a request from another — turning a
     * correct allow-list into an accidental one.
     */
    private function cors(Response $response, string $origin): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Vary', 'Origin');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set(
            'Access-Control-Allow-Headers',
            'Accept, Content-Type, X-Chatbot-Token',
        );
        $response->headers->set('Access-Control-Max-Age', '600');

        return $response;
    }

    private function refuse(string $reason): Response
    {
        return response()->json(['message' => $reason], 403);
    }
}
