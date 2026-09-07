import { memo, useEffect, useRef, useState } from 'react';
import { useReactFlow } from '@xyflow/react';

import { text } from './types';

/*
 * A sticky note pinned to the canvas.
 *
 * Notes carry no behaviour and the bot never reads them; they exist because a
 * scenario is edited by people who were not there when it was drawn, and "this
 * branch is waiting on the payment provider" belongs next to the branch rather
 * than in a ticket nobody opens.
 *
 * Editing starts on double click rather than on click, because a single click
 * is how a note is selected and dragged, and a note that turned into a text box
 * the moment it was touched could not be moved at all.
 */

function NoteNode({ id, data, selected }) {
    const { updateNodeData } = useReactFlow();
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(data.text || '');
    const field = useRef(null);

    useEffect(() => {
        setDraft(data.text || '');
    }, [data.text]);

    useEffect(() => {
        if (editing) {
            field.current?.focus();
            field.current?.select();
        }
    }, [editing]);

    /*
     * The draft is written back when the box loses focus, not on every
     * keystroke. Each write is an undo entry on the canvas, and a note typed
     * out character by character would bury every real change under fifty
     * undos of itself.
     */
    function commit() {
        setEditing(false);

        if (draft !== data.text) {
            updateNodeData(id, { text: draft });
        }
    }

    return (
        <div
            className={`chatbot-graph__note ${selected ? 'is-selected' : ''}`}
            onDoubleClick={() => setEditing(true)}
        >
            {editing ? (
                <textarea
                    ref={field}
                    className="chatbot-graph__note-input nodrag nowheel"
                    value={draft}
                    onChange={(event) => setDraft(event.target.value)}
                    onBlur={commit}
                    onKeyDown={(event) => {
                        if (event.key === 'Escape') {
                            setDraft(data.text || '');
                            setEditing(false);
                        }
                    }}
                />
            ) : (
                <p className="chatbot-graph__note-text">{data.text || text('note_placeholder')}</p>
            )}
        </div>
    );
}

export default memo(NoteNode);
