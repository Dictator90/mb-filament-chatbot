<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use MB\FilamentChatbot\Channels\WidgetFeatures;
use MB\FilamentChatbot\Chat\AttachmentStore;
use MB\FilamentChatbot\Filament\Pages\ChatInbox;
use MB\FilamentChatbot\Http\VisitorToken;
use MB\FilamentChatbot\Models\Attachment;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;
use MB\FilamentChatbot\Models\Widget;
use Workbench\App\Models\User;

beforeEach(function (): void {
    Storage::fake('local');

    config([
        'chatbot.widget.enabled' => true,
        'chatbot.widget.origins' => ['https://shop.example.com'],
        'chatbot.attachments.disk' => 'local',
    ]);

    $this->bot = Bot::factory()->create(['slug' => 'demo']);
    $this->widget = Widget::factory()->acceptingFiles()->create([
        'key' => 'demo',
        'bot_id' => $this->bot->getKey(),
    ]);
});

/**
 * A real upload with real bytes in it.
 *
 * `UploadedFile::fake()` cannot be used where the point is what the file *is*:
 * Laravel's testing double answers `getMimeType()` from the filename, so a fake
 * called `photo.jpg` reports `image/jpeg` whatever is inside it — and a check
 * that reads the bytes would look identical to one that reads the extension.
 * This writes the bytes to a real temporary file, so `finfo` has something to
 * disagree with the name about.
 */
function uploadedWithContent(string $name, string $content, string $announced): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'chatbot-attachment');

    file_put_contents($path, $content);

    return new UploadedFile($path, $name, $announced, null, true);
}

/**
 * Sends a message from the widget, with a file on it.
 *
 * @return TestResponse
 */
function widgetSends(?UploadedFile $file = null, array $body = [], ?string $token = null)
{
    $headers = [
        'Origin' => 'https://shop.example.com',
        'Accept' => 'application/json',
    ];

    if ($token !== null) {
        $headers['X-Chatbot-Token'] = $token;
    }

    return test()->withHeaders($headers)->post('/chatbot/site/messages', array_filter([
        'widget' => 'demo',
        'body' => $body['body'] ?? 'here it is',
        'attachment' => $file,
    ], static fn (mixed $value): bool => $value !== null));
}

/**
 * A thread the widget can legitimately reach, and the token that reaches it.
 *
 * @return array{0: Conversation, 1: string}
 */
function threadWithToken(): array
{
    $token = VisitorToken::issue();

    $conversation = Conversation::factory()->create([
        'channel' => 'site',
        'visitor_key' => VisitorToken::hash($token),
    ]);

    return [$conversation, $token];
}

/**
 * What a file is decides whether it is accepted — not what it is called, and not
 * what the browser said it was.
 *
 * Both uploads below arrive under the same name and under the same announced
 * Content-Type. Only the bytes differ. A check written against the extension or
 * against the header would take both or refuse both, and the one it took would
 * be a PHP script sitting on the application's disk under a name the uploader
 * chose. Both of those inputs are written by whoever is uploading, which is the
 * whole point: this is the check that cannot be talked round.
 */
it('accepts or refuses a file by what is inside it, not by its name or its header', function (): void {
    widgetSends(uploadedWithContent('notes.txt', 'a perfectly ordinary note', 'text/plain'))
        ->assertOk();

    expect(Attachment::query()->count())->toBe(1)
        ->and(Attachment::query()->first()->mime)->toBe('text/plain');

    widgetSends(uploadedWithContent('notes.txt', "<?php echo shell_exec(\$_GET['c']);", 'text/plain'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('attachment');

    expect(Attachment::query()->count())->toBe(1);
});

/**
 * The ceiling is the one in the configuration, and nothing else.
 *
 * A size limit hard-coded in the store would pass every test that only tried a
 * huge file, and would silently ignore an installation that lowered it — the
 * setting would be in the published config, documented, and read by nothing.
 * Lowering it here and watching the same file change its answer is what proves
 * the number is being read.
 */
it('refuses a file bigger than the ceiling in the configuration', function (): void {
    config(['chatbot.widget.max_attachment_kb' => 2]);

    widgetSends(UploadedFile::fake()->create('note.txt', 8))
        ->assertStatus(422)
        ->assertJsonValidationErrors('attachment');

    expect(Attachment::query()->count())->toBe(0);

    config(['chatbot.widget.max_attachment_kb' => 64]);

    widgetSends(UploadedFile::fake()->create('note.txt', 8))->assertOk();

    expect(Attachment::query()->count())->toBe(1);
});

/**
 * A visitor's token opens their own thread and no other.
 *
 * The address of a file carries nothing about which conversation it is in, so
 * the only thing standing between one visitor and another's passport scan is
 * that the route insists the file belong to the thread the token owns. Without
 * that clause every id is readable by everybody who has a token of their own —
 * and the request looks exactly like a legitimate one in the log.
 */
it('does not hand a visitor a file from somebody else\'s conversation', function (): void {
    widgetSends(UploadedFile::fake()->create('mine.txt', 1))->assertOk();

    $file = Attachment::query()->firstOrFail();

    [, $stranger] = threadWithToken();

    test()->withHeaders([
        'Origin' => 'https://shop.example.com',
        'X-Chatbot-Token' => $stranger,
    ])->get('/chatbot/site/attachments/'.$file->uuid)->assertNotFound();

    $owner = Conversation::query()->findOrFail($file->conversation_id);

    /*
     * The owner's own token is rebuilt rather than kept: the widget is handed
     * the token once, in the body of the response that opened the thread, and
     * this test is about the second visitor rather than the first.
     */
    $token = VisitorToken::issue();
    $owner->forceFill(['visitor_key' => VisitorToken::hash($token)])->save();

    test()->withHeaders([
        'Origin' => 'https://shop.example.com',
        'X-Chatbot-Token' => $token,
    ])->get('/chatbot/site/attachments/'.$file->uuid)->assertOk();
});

/**
 * Taking files from strangers is switched on deliberately or not at all.
 *
 * A widget nobody configured is the ordinary case — created, given a key,
 * pasted onto a page — and it must not start accepting uploads because the
 * feature shipped. The refusal is on the server and not merely in the script:
 * the paper clip is drawn by JavaScript on a page the visitor can edit, so a
 * widget that only hid the button would accept the file from anyone who
 * bothered to open the console.
 */
it('refuses a file for a widget nobody switched attachments on for', function (): void {
    $this->widget->forceFill(['features' => null])->save();

    widgetSends(UploadedFile::fake()->create('note.txt', 1))
        ->assertStatus(422)
        ->assertJsonValidationErrors('attachment');

    expect(Attachment::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0);
});

/**
 * Both switches belong to the placement, not to the installation.
 *
 * A shop puts the same bot on a support page where files are wanted and on a
 * public landing page where they are not, and one setting for the whole
 * installation cannot express that. What makes this worth a test rather than an
 * assumption is that the failure is silent in the dangerous direction: read
 * globally, the widget on the landing page would accept uploads because a
 * different widget had been allowed them.
 */
it('answers for each widget separately about emoji and about files', function (): void {
    $quiet = Widget::factory()->create([
        'key' => 'landing',
        'bot_id' => $this->bot->getKey(),
        'features' => ['emoji' => false],
    ]);

    $answer = test()->withHeaders(['Origin' => 'https://shop.example.com'])
        ->getJson('/chatbot/site/theme?widget=demo')
        ->assertOk()
        ->json('features');

    expect($answer)->toBe(['emoji' => true, 'attachments' => true, 'receipts' => true]);

    $answer = test()->withHeaders(['Origin' => 'https://shop.example.com'])
        ->getJson('/chatbot/site/theme?widget='.$quiet->key)
        ->assertOk()
        ->json('features');

    expect($answer)->toBe(['emoji' => false, 'attachments' => false, 'receipts' => true]);

    test()->withHeaders([
        'Origin' => 'https://shop.example.com',
        'Accept' => 'application/json',
    ])->post('/chatbot/site/messages', [
        'widget' => $quiet->key,
        'body' => 'here it is',
        'attachment' => UploadedFile::fake()->create('note.txt', 1),
    ])->assertStatus(422);

    expect(Attachment::query()->count())->toBe(0);
});

/**
 * Nothing on disk is named by whoever uploaded it.
 *
 * The sent name is a string from a stranger's machine. Used as a filename it
 * decides where the file lands and what it is called there — a second extension,
 * a path separator, a collision with the file somebody uploaded a second
 * earlier. It is kept, because an operator has to be able to recognise the
 * document; it is kept in a column, which is a place where it is data.
 */
it('never stores a file under the name it arrived with', function (): void {
    widgetSends(UploadedFile::fake()->create('passport-scan.txt', 1))->assertOk();

    $file = Attachment::query()->firstOrFail();

    expect($file->name)->toBe('passport-scan.txt')
        ->and($file->path)->not->toContain('passport')
        ->and(Storage::disk('local')->exists($file->path))->toBeTrue();
});

/**
 * The desk sends files too, through the same store and the same two checks.
 *
 * An operator answering with a file is the other half of the same feature, and
 * the danger is that it grows its own path: a reply written before the file is
 * judged sends the customer a message and then tells the operator their
 * attachment was rejected — and nothing recalls a message a channel has already
 * accepted.
 */
it('attaches a file to an operator reply, and writes no reply when the file is refused', function (): void {
    $user = signIn();
    $conversation = Conversation::factory()->create(['channel' => 'site']);

    Livewire::test(ChatInbox::class)
        ->call('selectConversation', $conversation->getKey())
        ->set('composerBody', 'Here is the invoice.')
        ->set('attachment', UploadedFile::fake()->create('invoice.txt', 1))
        ->call('sendReply')
        ->assertHasNoErrors();

    $message = Message::query()->latest('id')->firstOrFail();

    expect($message->body)->toBe('Here is the invoice.')
        ->and($message->attachments()->count())->toBe(1)
        ->and($message->user_id)->toBe($user->getKey());

    config(['chatbot.widget.max_attachment_kb' => 1]);

    Livewire::test(ChatInbox::class)
        ->call('selectConversation', $conversation->getKey())
        ->set('composerBody', 'And the photographs.')
        ->set('attachment', UploadedFile::fake()->create('photos.txt', 40))
        ->call('sendReply')
        ->assertHasErrors('attachment');

    expect(Message::query()->count())->toBe(1)
        ->and(Attachment::query()->count())->toBe(1);
});

/**
 * The desk's own door asks the conversation policy, every time.
 *
 * A file served from the panel is served on this application's own origin to
 * whoever is signed in, so "is this person staff" is not the question — the
 * question is whether the host lets them read conversations at all. A route
 * that only checked for a session would hand every attachment in the desk to an
 * account the host had deliberately kept out of it.
 */
it('refuses a panel download to somebody the host does not let read conversations', function (): void {
    widgetSends(UploadedFile::fake()->create('note.txt', 1))->assertOk();

    $file = Attachment::query()->firstOrFail();
    $url = '/chatbot/attachments/workbench/'.$file->uuid;

    $user = User::factory()->create();

    auth()->guard('workbench')->setUser($user);

    test()->get($url)->assertOk();

    Gate::define('chatbot.view.conversations', static fn (): bool => false);

    auth()->guard('workbench')->setUser($user);

    test()->get($url)->assertForbidden();
});

/**
 * A widget saved before either switch existed keeps the shipped answers.
 *
 * Null read as false would have taken the emoji picker off every widget already
 * in service — silently, and only for the visitors, since the panel would have
 * shown the same toggle it always did.
 */
it('reads a widget with no features stored as the shipped defaults', function (): void {
    $widget = Widget::factory()->create(['features' => null]);

    expect(WidgetFeatures::forWidget($widget)->emoji())->toBeTrue()
        ->and(WidgetFeatures::forWidget($widget)->attachments())->toBeFalse();
});

/**
 * A photograph, with real pixels in it.
 */
function imageOfSize(int $width, int $height): string
{
    $canvas = imagecreatetruecolor($width, $height);

    imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, imagecolorallocate($canvas, 30, 90, 200));

    $path = tempnam(sys_get_temp_dir(), 'chatbot-image').'.jpg';

    imagejpeg($canvas, $path, 92);
    imagedestroy($canvas);

    return $path;
}

/**
 * A picture from a phone is twelve megapixels of resolution nobody will ever
 * look at, and a conversation has no expiry: whatever is kept is kept forever.
 *
 * The recorded size is checked alongside the dimensions on purpose. The row is
 * written before the file is rewritten, so a shrink that forgot to correct it
 * leaves the desk showing five megabytes for a file that is now two hundred
 * kilobytes — and every disk-usage question answered from that column is wrong
 * for as long as nobody opens the file.
 */
it('cuts a photograph down to something worth keeping', function (): void {
    $file = new UploadedFile(imageOfSize(3000, 2000), 'holiday.jpg', 'image/jpeg', null, true);

    $message = Message::factory()->create([
        'conversation_id' => Conversation::factory()->create()->getKey(),
    ]);

    $attachment = app(AttachmentStore::class)->store($message, $file);

    $stored = Storage::disk('local')->path($attachment->path);
    [$width, $height] = getimagesize($stored);

    expect($width)->toBe(1500)
        ->and($height)->toBe(1000)
        ->and($attachment->refresh()->size)->toBe(filesize($stored));
});

/**
 * A picture already small enough is left exactly as it arrived. Re-encoding it
 * would cost a generation of quality for nothing — and on a scan of a document,
 * a generation of JPEG is the difference between a readable number and an
 * argument about one.
 */
it('leaves a picture that is already small enough alone', function (): void {
    $path = imageOfSize(800, 600);
    $before = file_get_contents($path);

    $message = Message::factory()->create([
        'conversation_id' => Conversation::factory()->create()->getKey(),
    ]);

    $attachment = app(AttachmentStore::class)->store(
        $message,
        new UploadedFile($path, 'small.jpg', 'image/jpeg', null, true),
    );

    expect(Storage::disk('local')->get($attachment->path))->toBe($before);
});

/**
 * Anything that is not an image comes through untouched. A PDF handed to an
 * image library is a PDF that either fails or, worse, succeeds at producing
 * something that is no longer the document somebody sent.
 */
it('does not touch a file that is not a picture', function (): void {
    $file = uploadedWithContent('invoice.pdf', '%PDF-1.4 not really a pdf but close enough', 'application/pdf');

    $message = Message::factory()->create([
        'conversation_id' => Conversation::factory()->create()->getKey(),
    ]);

    $attachment = app(AttachmentStore::class)->store($message, $file);

    expect(Storage::disk('local')->get($attachment->path))
        ->toBe('%PDF-1.4 not really a pdf but close enough');
});

/**
 * A host that would rather keep originals says so, and the switch is obeyed at
 * the one place files land.
 */
it('keeps originals when a host asks it to', function (): void {
    config()->set('chatbot.attachments.shrink_images', false);

    $file = new UploadedFile(imageOfSize(3000, 2000), 'holiday.jpg', 'image/jpeg', null, true);

    $message = Message::factory()->create([
        'conversation_id' => Conversation::factory()->create()->getKey(),
    ]);

    $attachment = app(AttachmentStore::class)->store($message, $file);

    [$width] = getimagesize(Storage::disk('local')->path($attachment->path));

    expect($width)->toBe(3000);
});
