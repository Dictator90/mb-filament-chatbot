<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MB\FilamentChatbot\Database\Factories\StepFactory;

/**
 * One node of a scenario.
 *
 * Transitions are recorded as step keys, never as positions: steps are
 * reordered and deleted from the middle, and a link by position would send the
 * conversation somewhere else after the first drag on the canvas. A key that no
 * longer resolves ends the scenario rather than throwing — scenarios are edited
 * by hand, and one typo must not leave every visitor without an answer.
 *
 * Per-kind settings live in one config bag rather than a column per kind. A
 * dozen kinds with three or four fields each is thirty-odd columns of which any
 * row fills three: that is a list of every setting that has ever existed, not a
 * schema. What every kind shares — text, transitions, coordinates — stays in
 * columns.
 *
 * @property int $id
 * @property int $scenario_id
 * @property string $key
 * @property int $sort
 * @property int $pos_x
 * @property int $pos_y
 * @property string $type
 * @property string|null $body
 * @property array<int, mixed>|null $options
 * @property array<string, mixed>|null $config
 * @property string|null $next_key
 * @property string|null $collect_into
 */
class Step extends Model
{
    /** @use HasFactory<StepFactory> */
    use HasFactory;

    /**
     * The kind of the node a scenario starts from.
     *
     * A named constant in one place because two parties know the value — the
     * model, which finds the node, and the registry, which declares the kind.
     * Spelled out at each call site instead, they would drift, and a scenario
     * would render a start node the runtime refuses to begin at.
     */
    public const string TYPE_START = 'start';

    /**
     * The config key holding the second exit of a two-exit step
     * ({@see self::secondTarget()}).
     *
     * A constant for the same reason: the model reads it and the graph
     * serialiser writes it, and once those disagree you get an arrow that is
     * visible on the canvas and never taken by the bot.
     */
    public const string SECOND_TARGET = 'else_key';

    protected $table = 'chatbot_steps';

    protected $guarded = [];

    /**
     * The kind is deliberately absent from this list.
     *
     * Kinds come from the registry and hosts add their own, so no enum can be
     * complete — and Eloquent casts an enum through from() rather than
     * tryFrom(), which means the first unfamiliar value would throw on read as
     * well as on write and take the whole scenario down with it. As a string
     * an unknown kind draws as a defect on one node and everything around it
     * still opens.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'pos_x' => 'integer',
            'pos_y' => 'integer',
            'options' => 'array',
            'config' => 'array',
        ];
    }

    /**
     * Reads one per-kind setting.
     *
     * One shared accessor rather than a getter per kind, because a host's own
     * node needs no more than this and the package has no way to know what its
     * settings are called. Built-in step classes wrap this method where a
     * default or a range matters; the storage stays one bag either way.
     */
    public function setting(string $name, mixed $default = null): mixed
    {
        return ((array) $this->config)[$name] ?? $default;
    }

    /**
     * Branching options: a label, the key of the next step, and whatever tells
     * this option apart from its neighbours.
     *
     * One reading for every branching kind. A question's option is a button, a
     * keyword split's is a word list, a condition's is the condition itself,
     * but the shape is identical and a second reading beside this one would
     * drift from it on the first change.
     *
     * An option with no label is dropped in silence: it cannot be shown to
     * anyone, and a half-filled row is what an editor form produces all the
     * time. An option with no destination is dropped too — it would take the
     * conversation nowhere, which reads to the visitor as the bot ignoring
     * their choice. Order is preserved, because the order of the options is
     * the order of the buttons, and a visitor answering "2" means the second
     * one as it was drawn.
     *
     * @return list<array{label: string, next: string, words: list<string>, when: array<string, mixed>}>
     */
    public function choices(): array
    {
        $choices = [];

        foreach ((array) $this->options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $label = trim((string) ($option['label'] ?? ''));
            $next = trim((string) ($option['next'] ?? ''));

            if ($label === '' || $next === '') {
                continue;
            }

            $choices[] = [
                'label' => $label,
                'next' => $next,
                'words' => $this->words($option['words'] ?? []),
                'when' => is_array($option['when'] ?? null) ? $option['when'] : [],
            ];
        }

        return $choices;
    }

    /**
     * The second exit of a step that has exactly two.
     *
     * Kept in the settings rather than in a column of its own. There is one
     * transition column (next_key), and options are about branches an author
     * labels, which such a step does not have. A third home for transitions
     * would mean three places recording where a conversation goes, and the
     * disagreement between them would be invisible until a customer hit it.
     */
    public function secondTarget(): ?string
    {
        $key = trim((string) $this->setting(self::SECOND_TARGET));

        return $key === '' ? null : $key;
    }

    /**
     * What the bot says at this node — the body and nothing else.
     *
     * Options are not appended here. They travel separately as a property of
     * the reply, and each channel draws them its own way: suggestion chips in
     * the site widget, a keyboard in Telegram. A numbered list inside the text
     * was a stand-in for buttons, and printed next to real ones it reads as
     * the bot repeating itself.
     *
     * Variables are not substituted either. Substitution needs the
     * conversation, and a model that reached for it would make rendering a
     * step depend on state the editor's preview does not have.
     */
    public function render(): string
    {
        return trim((string) $this->body);
    }

    /**
     * @return BelongsTo<Scenario, $this>
     */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class, 'scenario_id');
    }

    /**
     * @return list<string>
     */
    protected function words(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $words = [];

        foreach ($value as $word) {
            if (is_array($word) || is_object($word)) {
                continue;
            }

            $word = trim((string) $word);

            if ($word !== '') {
                $words[] = $word;
            }
        }

        return $words;
    }

    protected static function newFactory(): Factory
    {
        return StepFactory::new();
    }
}
