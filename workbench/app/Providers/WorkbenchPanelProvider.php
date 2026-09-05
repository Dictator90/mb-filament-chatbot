<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use MB\FilamentChatbot\ChatbotPlugin;

/**
 * A bare panel with nothing in it but the package.
 *
 * This is the point of the workbench: the only way to find out whether the
 * package works in someone else's application is to install it into one that
 * knows nothing about chat. Anything the panel needs and does not get from the
 * plugin is a gap a host would hit on day one.
 */
class WorkbenchPanelProvider extends PanelProvider
{
    /**
     * Declares the guard the panel signs people in on.
     *
     * Here rather than in the other workbench provider because the suite loads
     * this one and not that one, and a guard the panel names but the suite never
     * defines fails as "guard [workbench] is not defined" — far from anything
     * that explains it. The declaration and the `->authGuard()` call below now
     * cannot drift apart.
     *
     * A panel on its own guard is the ordinary shape, not an exotic one: staff
     * sign in against one table and customers against another, and that is what
     * `->authGuard()` is for. It matters here because `auth()->user()` resolves
     * the *application default* guard, never the panel's. Against a host shaped
     * this way that call answers `null` while somebody is plainly signed in, and
     * every consequence is silent — the desk vanishes from the menu, the unread
     * badge counts a guest, "mine" matches nothing, an operator's reply is filed
     * with no author. Nothing throws.
     *
     * It was found by installing the package into a real application whose panel
     * does exactly this, and it could not have been found here while the
     * workbench signed people in on the default guard like everything else.
     */
    public function register(): void
    {
        $this->app['config']->set('auth.guards.workbench', [
            'driver' => 'session',
            'provider' => 'users',
        ]);

        parent::register();
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('workbench')
            ->path('admin')
            ->authGuard('workbench')
            ->login()
            ->colors(['primary' => Color::Indigo])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->plugins([
                ChatbotPlugin::make(),
            ]);
    }
}
