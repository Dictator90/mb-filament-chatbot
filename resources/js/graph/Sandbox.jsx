import { useEffect, useRef, useState } from 'react';

/*
 * Talking to the bot without leaving the canvas.
 *
 * The conversation is held by the real engine on the server; this panel types
 * into it and prints what came back. Nothing here decides anything about the
 * bot — no branch is evaluated, no answer is matched, no node is chosen — which
 * is the only way a sandbox can disagree with production loudly instead of
 * quietly agreeing with the drawing.
 *
 * The trace is the half people actually need. A transcript says what the bot
 * said; only the trace says which branch it took to say it, and those two
 * answers diverge exactly where a scenario is wrong.
 */

/**
 * The scenario column is shown only once a conversation has left this canvas.
 *
 * A slug repeated on every line of a trace that never went anywhere is noise,
 * and the one thing worth seeing — that the bot jumped into another scenario —
 * is what the column is for.
 */
function crossesScenarios(trace) {
    return new Set(trace.map((step) => step.scenario)).size > 1;
}

export default function Sandbox({ sandbox, dirty, busy, onSay, onReset }) {
    const [said, setSaid] = useState('');
    const tail = useRef(null);

    const transcript = sandbox.transcript || [];
    const trace = sandbox.trace || [];
    const named = crossesScenarios(trace);

    useEffect(() => {
        tail.current?.scrollIntoView({ block: 'end' });
    }, [transcript.length]);

    function send(event) {
        event.preventDefault();

        const line = said.trim();

        if (line === '' || busy) {
            return;
        }

        setSaid('');
        onSay(line);
    }

    return (
        <aside className="chatbot-graph__sandbox">
            <header className="chatbot-graph__side-head">
                <div>
                    <p className="chatbot-graph__side-kind">Try it</p>
                    <span className="chatbot-graph__hint">
                        {sandbox.state ? `The conversation is ${sandbox.state}.` : 'Say something to start.'}
                    </span>
                </div>

                <button type="button" className="chatbot-graph__button" onClick={onReset} disabled={busy}>
                    Restart
                </button>
            </header>

            {/*
                Saving is what the engine reads. Somebody who has just rewritten
                a node and is testing the version before it would otherwise
                conclude the change did nothing.
            */}
            {dirty ? (
                <p className="chatbot-graph__warning is-notice">
                    The bot answers from the saved scenario. Save to try what is on the canvas now.
                </p>
            ) : null}

            <div className="chatbot-graph__transcript">
                {transcript.length === 0 ? (
                    <p className="chatbot-graph__empty">
                        Whatever you say enters this scenario at its start node, whatever the routing would have
                        done with it.
                    </p>
                ) : null}

                {transcript.map((line, index) => (
                    <p className={`chatbot-graph__line is-${line.from}`} key={index}>
                        {line.body}
                    </p>
                ))}

                {/*
                    The options the bot is waiting on. They are rendered per
                    channel and are no part of the message body, so without this
                    an `ask` node arrives here as one bare line with nothing to
                    answer it — the author sees the question, sees no menu, and
                    concludes the node is broken rather than the panel.

                    Picking one sends its label as if it had been typed, which is
                    exactly what a button in the widget does.
                */}
                {(sandbox.choices || []).length > 0 ? (
                    <div className="chatbot-graph__choices">
                        {(sandbox.choices || []).map((choice, index) => (
                            <button
                                type="button"
                                className="chatbot-graph__choice"
                                key={index}
                                disabled={busy}
                                onClick={() => onSay(choice.label)}
                            >
                                {choice.label}
                            </button>
                        ))}
                    </div>
                ) : null}

                <span ref={tail} />
            </div>

            <form className="chatbot-graph__composer" onSubmit={send}>
                <input
                    type="text"
                    value={said}
                    placeholder="Say something to the bot"
                    onChange={(event) => setSaid(event.target.value)}
                />
                <button type="submit" className="chatbot-graph__button is-primary" disabled={busy}>
                    Send
                </button>
            </form>

            {trace.length > 0 ? (
                <section className="chatbot-graph__section">
                    <h3 className="chatbot-graph__section-title">Nodes it walked</h3>

                    <ol className="chatbot-graph__trace">
                        {trace.map((step, index) => (
                            <li key={`${step.seq}-${index}`}>
                                <code>
                                    {named ? `${step.scenario}/` : ''}
                                    {step.key}
                                </code>
                                <span className="chatbot-graph__hint">{step.outcome}</span>
                            </li>
                        ))}
                    </ol>
                </section>
            ) : null}
        </aside>
    );
}
