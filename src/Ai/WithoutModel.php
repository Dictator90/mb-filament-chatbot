<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai;

/**
 * Runs a rehearsal with no model behind the bot.
 *
 * A rehearsal drives the real engine, which is the whole reason to trust it —
 * and that includes the nodes that ask a provider. Left alone, a nightly run of
 * a few hundred quality checks makes a few hundred paid calls before anybody is
 * awake, and it spends the same monthly budget the customers talk to. The
 * checks are also graded on what came back, so the answer that passed on Monday
 * fails on Tuesday for a wording nobody changed.
 *
 * So the rehearsal runs against the state the package ships in: no model, every
 * model node taking its "could not" exit, keyword routing deciding where a
 * message goes. That is not a lesser test. The package's rule is that a
 * scenario must remain useful with the model switched off, and this is the path
 * that rule is about — the one every conversation falls back to on the day the
 * provider has an outage or the budget runs out.
 *
 * An installation that would rather grade its model says so with
 * `chatbot.quality.use_model`, and then pays for it out of the same budget as
 * everything else, because it is the same provider and the same invoice.
 *
 * Configuration is what carries it, exactly as `src/Channels/WithoutDelivery.php`
 * carries the suppression of outgoing messages: this runs in production as well
 * as in a suite, and production has no test doubles in it. The flag is read
 * where gateways are built rather than where they are used, because a node may
 * name its own provider and a rehearsal must not be able to reach one by naming
 * it.
 */
final class WithoutModel
{
    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    public function run(callable $work): mixed
    {
        $previous = config('chatbot.ai.suppressed', false);

        config(['chatbot.ai.suppressed' => true]);

        try {
            return $work();
        } finally {
            config(['chatbot.ai.suppressed' => $previous]);
        }
    }
}
