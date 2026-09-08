<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Recipes;

/**
 * A scenario worth starting from, offered on the canvas.
 *
 * An empty canvas with one "Start" node on it does not suggest how a
 * conversation is put together out of fifteen boxes. That is the whole problem
 * these solve: the author gets a working shape on the board, drags it about,
 * renames the words to their own, and has learned what a form node or a
 * keyword branch is by taking one apart rather than by reading about it.
 *
 * **Nodes and nothing else.** A recipe does not create a scenario, does not
 * name it, does not switch it on and never touches the database. It hands over
 * a set of steps; what happens next is the canvas's ordinary save, with the
 * same defect checks and the same publish button as anything the author drew by
 * hand. A recipe that wrote its own scenario row would be a second way of
 * creating one, and the second way is the one that stops matching.
 *
 * **The keys are the author's problem, and deliberately so.** A recipe's node
 * keys are what they are; dropped onto a canvas that already has a node called
 * `start`, they collide, and it is the insertion that renames them — see
 * `resources/js/graph/Editor.jsx`. Renaming here instead would mean a recipe
 * whose internal arrows point at names it does not know.
 */
interface Recipe
{
    /**
     * The value stored nowhere and passed once, from the list to the insert.
     *
     * Prefixed for a host's own recipes — "crm.refund" rather than "refund" —
     * so that a later version of this package cannot take the name out from
     * under one.
     */
    public static function key(): string;

    public function label(): string;

    /**
     * One line saying what the shape is for. Shown beside the name in the list,
     * and it is the only thing the author has to go on before inserting.
     */
    public function description(): string;

    /**
     * The nodes, in the shape a step row takes.
     *
     * Written as plain arrays rather than as models because nothing is saved:
     * the canvas turns them into nodes and arrows through exactly the same
     * translation it uses for a stored scenario, so a recipe on the board is
     * the same drawing it would be after a save.
     *
     * @return list<array<string, mixed>>
     */
    public function steps(): array;
}
