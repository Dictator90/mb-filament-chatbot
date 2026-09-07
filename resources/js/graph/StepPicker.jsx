import { useEffect, useMemo, useRef, useState } from 'react';

import { allTypes, basicTypes, colorOf, labelOf, nextTypes, text } from './types';

/*
 * The list of node kinds, opened from the toolbar, from a right click on the
 * canvas, and from an arrow dropped on empty space.
 *
 * One component for all three because they are the same question asked in three
 * places, and three lists would drift: the day a host registers a kind, the
 * toolbar would offer it and the dropped arrow would not.
 *
 * The order is the point. A canvas with a node selected is asking "what comes
 * after this one", and the kinds that usually do come after it are named by the
 * step type itself — answering that question with the full alphabet of
 * twenty-five kinds is not answering it.
 */

function Row({ type, onPick }) {
    return (
        <button type="button" className="chatbot-graph__picker-row" onClick={() => onPick(type.value)}>
            <span className="chatbot-graph__picker-dot" style={{ backgroundColor: colorOf(type.value) }} />
            <span className="chatbot-graph__picker-label">{labelOf(type.value)}</span>
        </button>
    );
}

export default function StepPicker({ at = null, suggestedFor = null, onPick, onClose }) {
    const [query, setQuery] = useState('');
    const [expanded, setExpanded] = useState(false);
    const box = useRef(null);
    const search = useRef(null);

    useEffect(() => {
        search.current?.focus();
    }, []);

    /*
     * Escape and a click outside both close the picker.
     *
     * Listening on the document rather than on a backdrop element: React Flow
     * sits under an absolutely positioned overlay, and a full-screen backdrop
     * would swallow the wheel and drag events the canvas needs while the picker
     * happens to be open.
     */
    useEffect(() => {
        function onKey(event) {
            if (event.key === 'Escape') {
                onClose();
            }
        }

        function onDown(event) {
            if (box.current && !box.current.contains(event.target)) {
                onClose();
            }
        }

        document.addEventListener('keydown', onKey);
        document.addEventListener('mousedown', onDown);

        return () => {
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('mousedown', onDown);
        };
    }, [onClose]);

    const groups = useMemo(() => {
        const suggested = suggestedFor ? nextTypes(suggestedFor) : [];
        const suggestedKeys = new Set(suggested.map((type) => type.value));
        const basic = basicTypes().filter((type) => !suggestedKeys.has(type.value));
        const shown = new Set([...suggestedKeys, ...basic.map((type) => type.value)]);
        const rest = allTypes().filter((type) => !shown.has(type.value));

        return { suggested, basic, rest };
    }, [suggestedFor]);

    const needle = query.trim().toLowerCase();

    /*
     * A search runs over every kind at once, including the ones behind "More".
     * A filter that only searched the visible groups would answer "no such
     * node" about a node that is installed and two clicks away.
     */
    const found = needle
        ? allTypes().filter(
              (type) =>
                  labelOf(type.value).toLowerCase().includes(needle) ||
                  String(type.value).toLowerCase().includes(needle),
          )
        : null;

    const style = at ? { left: at.x, top: at.y } : null;

    return (
        <div className={`chatbot-graph__picker ${at ? 'is-anchored' : 'is-centred'}`} style={style} ref={box}>
            <input
                ref={search}
                type="search"
                className="chatbot-graph__picker-search"
                placeholder={text('find_kind')}
                value={query}
                onChange={(event) => setQuery(event.target.value)}
            />

            <div className="chatbot-graph__picker-list">
                {found ? (
                    found.length > 0 ? (
                        found.map((type) => <Row key={type.value} type={type} onPick={onPick} />)
                    ) : (
                        <p className="chatbot-graph__picker-empty">{text('picker_empty')}</p>
                    )
                ) : (
                    <>
                        {groups.suggested.length > 0 ? (
                            <>
                                <p className="chatbot-graph__picker-group">{text('usually_next')}</p>
                                {groups.suggested.map((type) => (
                                    <Row key={type.value} type={type} onPick={onPick} />
                                ))}
                            </>
                        ) : null}

                        {groups.basic.length > 0 ? (
                            <>
                                <p className="chatbot-graph__picker-group">{text('basics')}</p>
                                {groups.basic.map((type) => (
                                    <Row key={type.value} type={type} onPick={onPick} />
                                ))}
                            </>
                        ) : null}

                        {groups.rest.length > 0 ? (
                            expanded ? (
                                <>
                                    <p className="chatbot-graph__picker-group">{text('everything_else')}</p>
                                    {groups.rest.map((type) => (
                                        <Row key={type.value} type={type} onPick={onPick} />
                                    ))}
                                </>
                            ) : (
                                <button
                                    type="button"
                                    className="chatbot-graph__picker-more"
                                    onClick={() => setExpanded(true)}
                                >
                                    {text('picker_more', { count: groups.rest.length })}
                                </button>
                            )
                        ) : null}
                    </>
                )}
            </div>
        </div>
    );
}
