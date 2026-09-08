<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\QualityTests\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use MB\FilamentChatbot\Models\QualityTest;

/**
 * Why a check went red — the only screen anybody opens on purpose.
 *
 * Three things in this order, because that is the order they are needed: what
 * did not match, what the bot actually said, and which nodes the conversation
 * went through. The first answers "is this the bot or the check", the second is
 * quoted to whoever wrote the wording, and the third is read next to the canvas
 * with a finger on the branch that should have been taken.
 *
 * **The transcript is stored on the check, not fetched from the rehearsal
 * thread.** The rehearsal is deleted and rebuilt on every run, so a screen that
 * read the conversation would show the last run of *some* check and nothing at
 * all for one that has not run today.
 */
class QualityTestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('chatbot::panel.quality.detail.mismatches'))
                ->description(__('chatbot::panel.quality.detail.mismatches_help'))
                ->schema([
                    TextEntry::make('last_mismatches')
                        ->hiddenLabel()
                        ->state(fn (QualityTest $record): array => self::mismatches($record))
                        ->listWithLineBreaks()
                        ->placeholder(__('chatbot::panel.quality.detail.nothing_missed')),
                ]),

            Section::make(__('chatbot::panel.quality.detail.transcript'))
                ->description(__('chatbot::panel.quality.detail.transcript_help'))
                ->schema([
                    TextEntry::make('last_transcript')
                        ->hiddenLabel()
                        ->state(fn (QualityTest $record): array => self::transcript($record))
                        ->listWithLineBreaks()
                        ->size(TextSize::Small)
                        ->placeholder(__('chatbot::panel.quality.detail.never_run')),
                ]),

            Section::make(__('chatbot::panel.quality.detail.visited'))
                ->description(__('chatbot::panel.quality.detail.visited_help'))
                ->collapsed()
                ->schema([
                    TextEntry::make('visited')
                        ->hiddenLabel()
                        ->state(fn (QualityTest $record): string => implode(
                            ' → ',
                            self::visited($record),
                        ))
                        ->placeholder(__('chatbot::panel.quality.detail.no_nodes')),
                ]),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function mismatches(QualityTest $record): array
    {
        $lines = [];

        foreach ((array) $record->last_mismatches as $mismatch) {
            if (! is_array($mismatch)) {
                $lines[] = (string) $mismatch;

                continue;
            }

            $lines[] = __('chatbot::panel.quality.detail.mismatch_line', [
                'expected' => (string) ($mismatch['expected'] ?? ''),
                'actual' => (string) ($mismatch['actual'] ?? ''),
            ]);
        }

        return $lines;
    }

    /**
     * The rehearsal, labelled by who was speaking.
     *
     * Prefixed rather than laid out as chat bubbles: this is read to find one
     * wrong sentence, and a wall of bubbles makes that harder, not easier.
     *
     * @return list<string>
     */
    private static function transcript(QualityTest $record): array
    {
        $lines = [];

        foreach ((array) $record->last_transcript as $line) {
            if (! is_array($line)) {
                $lines[] = (string) $line;

                continue;
            }

            $who = ($line['from'] ?? '') === 'bot'
                ? __('chatbot::panel.quality.detail.bot')
                : __('chatbot::panel.quality.detail.visitor');

            $lines[] = $who.': '.(string) ($line['body'] ?? '');
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function visited(QualityTest $record): array
    {
        return array_map(
            static fn (mixed $key): string => (string) $key,
            array_values((array) $record->last_visited),
        );
    }
}
