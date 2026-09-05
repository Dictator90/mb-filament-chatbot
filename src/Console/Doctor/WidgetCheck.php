<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Doctor;

/**
 * Is the site widget switched on and able to answer anybody.
 *
 * The empty origin list is a failure and not a warning, and the severity is the
 * whole reason this check exists. An empty list blocks every site — that is the
 * deliberate, safe default the config ships with — so a widget switched on and
 * never given an origin is switched on and mute. Nothing on the panel says so,
 * the embed code is on the page, and the only symptom is a widget that does not
 * open, which reads as a broken widget rather than as a setting nobody filled
 * in.
 *
 * A wildcard in production is the opposite mistake and only a warning: it
 * works, and it lets any site embed this installation's widget and bill its
 * model budget. That is a decision somebody may have made on purpose for a
 * network of sites, so it is said out loud rather than refused.
 */
final class WidgetCheck extends DoctorCheck
{
    public static function key(): string
    {
        return 'widget';
    }

    public function title(): string
    {
        return 'Site widget';
    }

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array
    {
        if (! (bool) config('chatbot.widget.enabled', false)) {
            return [DoctorFinding::skipped('The site widget is switched off.')];
        }

        $origins = array_values(array_filter(
            (array) config('chatbot.widget.origins', []),
            static fn (mixed $origin): bool => is_string($origin) && trim($origin) !== '',
        ));

        if ($origins === []) {
            return [DoctorFinding::fail(
                'The widget is switched on and no origin may embed it.',
                'List the sites in chatbot.widget.origins. An empty list blocks every one of them, and the widget simply never opens.',
            )];
        }

        if (in_array('*', $origins, true) && app()->environment('production')) {
            return [DoctorFinding::warn(
                'Any site may embed the widget in production.',
                'Replace the wildcard in chatbot.widget.origins with the sites that should have it. Anyone else embedding it spends this installation\'s model budget.',
                $origins,
            )];
        }

        $count = count($origins);

        return [DoctorFinding::pass(
            $count.($count === 1 ? ' origin may' : ' origins may').' embed the widget.',
            $origins,
        )];
    }
}
