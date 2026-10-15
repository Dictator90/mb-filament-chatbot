<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai;

/**
 * A model that can hold an exchange and ask for things to be done.
 *
 * A second contract beside {@see AiGateway} rather than three more methods on
 * it, because the small surface of that one is what lets a host satisfy it with
 * an HTTP endpoint, a library, its own client or nothing at all. Its docblock
 * says tool calling and conversation state were left out so that a driver which
 * cannot express them would not have to fake it — and that reasoning still
 * holds. What changed is that some drivers *can* express them, and a scenario
 * where the model leads the conversation needs exactly those two things.
 *
 * So the question "can this installation run an agent?" is an `instanceof`, and
 * a driver that is only a gateway is not broken — it answers questions, and the
 * agent node takes its second exit with a reason naming the driver. The
 * alternative was a `supportsTools()` flag on the base contract, which every
 * host implementation would have had to learn about and answer, mostly `false`.
 *
 * Like its sibling, **a conversation gateway never throws**: everything comes
 * back as {@see AiReply}, declined or with the tool calls the model asked for.
 */
interface AiConversationGateway extends AiGateway
{
    public function converse(AiTurn $turn): AiReply;
}
