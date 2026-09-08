<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Doctor;

use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use MB\FilamentChatbot\Chat\ChatDesk;
use MB\FilamentChatbot\Contracts\OperatorAudience;
use MB\FilamentChatbot\Models\Conversation;
use Throwable;

/**
 * Whether anybody would actually hear that a conversation is waiting.
 *
 * The bell swallows every failure it meets, and it has to: it rings from inside
 * the path that stores a customer's message, and a notifications table that is
 * not there must not cost them the message. The price of that is a bell which
 * fails exactly like a quiet desk. Three separate mistakes — no table, no
 * `Notifiable` on the operator model, an audience that resolves to nobody — all
 * look like an afternoon with no enquiries, and none of them appear in a log.
 *
 * So this check asks the three questions out loud. It is the only place in the
 * package where the silence is examined rather than tolerated.
 */
final class OperatorBellCheck extends DoctorCheck
{
    public static function key(): string
    {
        return 'bell';
    }

    public function title(): string
    {
        return 'Operator bell';
    }

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array
    {
        return array_values(array_filter([
            $this->table(),
            $this->notifiable(),
            $this->audience(),
        ]));
    }

    protected function table(): DoctorFinding
    {
        if (Schema::hasTable('notifications')) {
            return DoctorFinding::pass('The notifications table is there.');
        }

        return DoctorFinding::fail(
            'There is no notifications table, so no bell can ever be stored.',
            'Run `php artisan make:notifications-table` and migrate. Until then a handed-over conversation waits '
            .'in silence until somebody happens to open the desk.',
        );
    }

    protected function notifiable(): DoctorFinding
    {
        $model = Conversation::operatorModel();

        if (! class_exists($model)) {
            return DoctorFinding::fail(
                "The operator model [{$model}] does not exist.",
                'Point `chatbot.user_model` at the class that stands for a member of staff, or fix '
                .'`auth.providers.users.model`. Everything that reaches for an operator resolves this class: the '
                .'assignee of a conversation, the author of a reply, and the audience of the bell.',
            );
        }

        if (in_array(Notifiable::class, class_uses_recursive($model), true)) {
            return DoctorFinding::pass("Operators are [{$model}], and they can be notified.");
        }

        return DoctorFinding::fail(
            "The operator model [{$model}] does not use the Notifiable trait.",
            'Add `use Illuminate\Notifications\Notifiable;` to it. A database notification goes to a notifiable, '
            .'and a model without the trait cannot receive one at all — the bell will be silently dead.',
        );
    }

    /**
     * Asked against a real conversation rather than in the abstract, because
     * "who hears it" is a question about a thread and not about the config.
     *
     * A host audience may narrow by channel, by tenant, by shift. The only
     * honest way to find out whether it answers nobody is to ask it about
     * something it would actually be asked about, and an unassigned conversation
     * is the case that matters: an enquiry from a website arrives that way, and
     * an audience empty there is a desk that never learns about new customers.
     */
    protected function audience(): ?DoctorFinding
    {
        $conversation = Conversation::query()->whereNull('assigned_to_id')->latest('id')->first();

        if ($conversation === null) {
            return null;
        }

        $audience = app(OperatorAudience::class);

        try {
            $recipients = [...$audience->for($conversation)];
        } catch (Throwable $e) {
            return DoctorFinding::fail(
                'The operator audience threw: '.$e->getMessage(),
                'The bell swallows this, so nothing rings and nothing is logged. '.$audience::class.' has to answer '
                .'without failing — it runs inside the path that stores a customer\'s message.',
            );
        }

        if ($recipients !== []) {
            $names = count($recipients);

            return DoctorFinding::pass(
                "An unassigned conversation rings {$names} ".($names === 1 ? 'person' : 'people')
                .' through '.$audience::class.'.',
            );
        }

        if ($audience::class !== ChatDesk::class) {
            return DoctorFinding::warn(
                'The bound operator audience ('.$audience::class.') names nobody for an unassigned conversation.',
                'That is a supported answer if the host escalates those threads its own way. If not, an enquiry '
                .'from a website rings nobody at all.',
            );
        }

        return DoctorFinding::warn(
            'Nobody would hear an unassigned conversation.',
            'The shipped desk rings everyone who may view conversations and can open the panel, capped at '
            .'`chatbot.notifications.max_recipients`. An empty answer means either the cap was passed — the '
            .'operator model is a users table with customers in it — or nothing grants the view. Point '
            .'`chatbot.user_model` at staff, or bind OperatorAudience to your own rota.',
        );
    }
}
