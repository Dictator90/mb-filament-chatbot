<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Http\OutboundGuard;
use MB\FilamentChatbot\Bot\Steps\Concerns\WritesRecordVariables;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Contracts\HasSideEffect;
use MB\FilamentChatbot\Enums\SideEffect;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use Throwable;

/**
 * Asks another system something, and comes back either way.
 *
 * This is the most dangerous node in the package, and everything about it is
 * written from that end. It sends a request built from a URL somebody typed
 * into a web form, carrying values the visitor supplied, from inside the
 * application's own network — which is the exact shape of a server-side request
 * forgery. Every rule below exists because leaving it out turns a scenario
 * editor into a way to read this application's cloud credentials, its internal
 * admin pages, or its neighbours on the private network.
 *
 * The address is checked before anything leaves ({@see OutboundGuard}): an
 * allow-list that refuses everything until a domain is listed, https outside
 * development, and — the one that matters — a range check on the addresses the
 * name actually resolves to, not on the text of the URL.
 *
 * Redirects are not followed. A redirect is the other system choosing where
 * this request goes next, and the whole guard above ran against a host it chose
 * to leave behind: a 302 to http://169.254.169.254/ would otherwise walk past
 * every check on this page with the allow-list still perfectly satisfied.
 *
 * Only GET is retried. A repeated read costs a second request; a repeated POST
 * that timed out after the other side had already committed it books the trip
 * twice, and the visitor gets two invoices for a node that reported one failure.
 *
 * Nothing throws out of here. A refused address, a connection that never
 * opened, a certificate that did not verify, a body that was not the JSON it
 * promised — all of them take the second exit with the reason in the trace,
 * because on the other side of that exit is a scenario that can apologise and
 * fetch a person.
 */
final class HttpStep extends StepType implements HasSideEffect
{
    use WritesRecordVariables;

    /** Methods a scenario may send, in the order they are offered. */
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly OutboundGuard $guard) {}

    public static function key(): string
    {
        return 'http';
    }

    public function label(): string
    {
        return 'Ask another system';
    }

    public function icon(): string
    {
        return 'heroicon-o-globe-alt';
    }

    public function color(): string
    {
        return '#475569';
    }

    public function outputs(): StepOutputs
    {
        return StepOutputs::Pair;
    }

    /**
     * Whether this particular node changes something on the other side.
     *
     * Answered from the method rather than per kind, because the same kind
     * covers both a price lookup and an order being placed. A GET reads; a POST
     * to somebody else's system creates a record there that this application
     * cannot take back, and it deserves the same "was anyone asked first?"
     * treatment as a write into the host's own tables. Marking every outbound
     * node as a write instead would put a warning on every price lookup, and a
     * warning on everything is a warning nobody reads.
     */
    public function sideEffect(Step $step): SideEffect
    {
        $method = mb_strtoupper(trim((string) $step->setting('method', 'GET')));

        return $method === 'GET' ? SideEffect::Read : SideEffect::Write;
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function pairLabels(): array
    {
        return ['answered', 'failed'];
    }

    /**
     * @return list<string>
     */
    public function nextTypes(): array
    {
        return ['say', 'condition', 'transform', 'handoff'];
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::select('method')
                ->label('Method')
                ->default('GET')
                ->options(array_combine(self::METHODS, self::METHODS)),

            Field::text('url')
                ->label('Address')
                ->required()
                ->placeholder('https://api.example.com/orders/{{order_id}}')
                ->hint('May contain {{variables}}. Only domains allowed in the chatbot configuration can be called.'),

            Field::keyValue('headers')
                ->label('Headers'),

            Field::textarea('body')
                ->label('Body')
                ->rows(6)
                ->hint('Sent as JSON when it parses as JSON, as plain text otherwise. May contain {{variables}}.')
                ->visibleWhen('method', 'POST', 'PUT', 'PATCH'),

            Field::text('path')
                ->label('Take from the answer')
                ->placeholder('data.status')
                ->hint('A path inside the JSON answer. Leave empty to keep the whole body.'),

            Field::text('into')
                ->label('Remember the answer as')
                ->placeholder('order')
                ->required(),

            Field::number('timeout')
                ->label('Give up after, seconds')
                ->min(1)
                ->max(60),
        ];
    }

    public function run(StepContext $context): StepResult
    {
        $url = trim($context->filled('url'));
        $into = trim((string) $context->setting('into', ''));
        $method = $this->method($context);

        if ($url === '' || $into === '') {
            return StepResult::second()->traced(RunStep::FAILED, [
                'reason' => 'The node does not say what to call or what to remember the answer as.',
            ]);
        }

        $refusal = $this->guard->refusal($url);

        if ($refusal !== null) {
            return StepResult::second()->traced(RunStep::FAILED, [
                'method' => $method,
                'reason' => 'The request was refused before it was sent: '.$refusal.'.',
            ]);
        }

        try {
            $response = $this->send($context, $method, $url);
        } catch (Throwable $exception) {
            return StepResult::second()->traced(RunStep::FAILED, [
                'method' => $method,
                'reason' => $exception::class.': '.$exception->getMessage(),
            ]);
        }

        $status = $response->status();

        if (! $response->successful()) {
            return StepResult::second()->traced(RunStep::FAILED, [
                'method' => $method,
                'status' => $status,
                'reason' => 'The other system answered with status '.$status.'.',
            ]);
        }

        $body = $this->trimmed($response->body());
        $value = $this->extract($context, $body);

        if ($value === null) {
            return StepResult::second()->traced(RunStep::FAILED, [
                'method' => $method,
                'status' => $status,
                'reason' => 'The answer does not contain ['.trim((string) $context->setting('path', '')).'].',
            ]);
        }

        $this->remember($context, $into, $value);

        return StepResult::next()->traced(RunStep::PASSED, [
            'method' => $method,
            'status' => $status,
            'into' => $into,
            'bytes' => strlen($body),
        ]);
    }

    /**
     * @return list<array{severity: string, message: string}>
     */
    public function issues(Step $step, Scenario $scenario): array
    {
        $issues = [];
        $url = trim((string) $step->setting('url', ''));

        if ($url === '') {
            $issues[] = ['severity' => 'blocking', 'message' => 'This node does not say what address to call.'];
        }

        if (trim((string) $step->setting('into', '')) === '') {
            $issues[] = [
                'severity' => 'blocking',
                'message' => 'This node does not say what to remember the answer as, so nothing downstream could use it.',
            ];
        }

        /*
         * An address holding no {{variable}} can be judged now, and saying so
         * while the scenario is open is the difference between a typo found in
         * the editor and one found by a customer whose conversation quietly
         * took the "failed" exit.
         */
        if ($url !== '' && ! str_contains($url, '{{') && $this->guard->refusal($url) !== null) {
            $issues[] = [
                'severity' => 'blocking',
                'message' => 'This address cannot be called: '.$this->guard->refusal($url).'.',
            ];
        }

        return $issues;
    }

    /**
     * Sends the request, with the retry offered only where repeating is safe.
     */
    private function send(StepContext $context, string $method, string $url): Response
    {
        $request = Http::withoutRedirecting()
            ->timeout($this->timeout($context))
            ->withHeaders($this->headers($context));

        if ($method === 'GET') {
            $request = $request->retry(2, 250, throw: false);
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            return $request->send($method, $url, $this->payload($context));
        }

        return $request->send($method, $url);
    }

    /**
     * The body, as an array when it parses as JSON and as a string otherwise.
     *
     * Guessing from the text rather than from a setting on the node, because a
     * setting there is one more thing an author has to know about a system they
     * are only calling: told "JSON" while typing something that is not, they get
     * a request the other side rejects with a message this node never shows them.
     *
     * @return array<string, mixed>
     */
    private function payload(StepContext $context): array
    {
        $body = trim($context->filled('body'));

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? ['json' => $decoded] : ['body' => $body];
    }

    /**
     * Cuts the answer down to the configured ceiling.
     *
     * The ceiling is on what this node keeps and hands to the conversation, and
     * it is honest about being no more than that: an answer of any size has
     * already arrived by the time it is read. What it prevents is the part that
     * lasts — a megabyte of somebody else's page stored in a variable, carried
     * through the rest of the walk and written into the run journal, where it
     * stays.
     */
    private function trimmed(string $body): string
    {
        $ceiling = (int) config('chatbot.http.max_response_bytes', 65536);

        if ($ceiling < 1 || strlen($body) <= $ceiling) {
            return $body;
        }

        return substr($body, 0, $ceiling);
    }

    /**
     * The part of the answer the node was pointed at, or null when it is absent.
     *
     * A missing path is a failure rather than an empty value, and that is the
     * difference between "the order has no status" and "we asked the wrong
     * system". Written into the variable as an empty string, the second one
     * reads to the visitor as the first.
     */
    private function extract(StepContext $context, string $body): mixed
    {
        $path = trim((string) $context->setting('path', ''));

        if ($path === '') {
            return $body;
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return null;
        }

        return Arr::has($decoded, $path) ? Arr::get($decoded, $path) : null;
    }

    /**
     * Writes the answer under the author's name, spreading an object over
     * fields the way every other node does ({@see WritesRecordVariables}).
     */
    private function remember(StepContext $context, string $into, mixed $value): void
    {
        if (is_scalar($value) || $value === null) {
            $context->runtime->store($into, $this->textOf($value));

            return;
        }

        $row = [];

        foreach ((array) $value as $name => $field) {
            $row[(string) $name] = is_scalar($field) || $field === null
                ? $field
                : (string) json_encode($field, JSON_UNESCAPED_UNICODE);
        }

        $this->rememberRecord($context->runtime, $into, $row);
    }

    /**
     * @return array<string, string>
     */
    private function headers(StepContext $context): array
    {
        $headers = [];

        foreach ((array) $context->setting('headers', []) as $name => $value) {
            $name = trim((string) $name);

            if ($name === '' || is_array($value)) {
                continue;
            }

            $headers[$name] = $context->runtime->fill((string) $value);
        }

        return $headers;
    }

    private function method(StepContext $context): string
    {
        $method = mb_strtoupper(trim((string) $context->setting('method', 'GET')));

        return in_array($method, self::METHODS, true) ? $method : 'GET';
    }

    private function timeout(StepContext $context): int
    {
        $timeout = (int) $context->setting('timeout', 0);

        if ($timeout < 1) {
            $timeout = (int) config('chatbot.http.timeout', 10);
        }

        return max(1, min(60, $timeout));
    }
}
