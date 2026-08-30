<?php

namespace App\Providers\Filament;

use App\Http\Middleware\RequireTwoFactor;
use BezhanSalleh\GoogleAnalytics\Widgets\ActiveUsersOneDayWidget;
use BezhanSalleh\GoogleAnalytics\Widgets\ActiveUsersSevenDayWidget;
use BezhanSalleh\GoogleAnalytics\Widgets\ActiveUsersTwentyEightDayWidget;
use BezhanSalleh\GoogleAnalytics\Widgets\MostVisitedPagesWidget;
use BezhanSalleh\GoogleAnalytics\Widgets\PageViewsWidget;
use BezhanSalleh\GoogleAnalytics\Widgets\SessionsByCountryWidget;
use BezhanSalleh\GoogleAnalytics\Widgets\SessionsByDeviceWidget;
use BezhanSalleh\GoogleAnalytics\Widgets\SessionsDurationWidget;
use BezhanSalleh\GoogleAnalytics\Widgets\SessionsWidget;
use BezhanSalleh\GoogleAnalytics\Widgets\TopReferrersListWidget;
use BezhanSalleh\GoogleAnalytics\Widgets\VisitorsWidget;
use Config;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
// use Filament\Widgets;
// use BezhanSalleh\FilamentGoogleAnalytics\Widgets;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Wave\Widgets;
use Wave\Widgets\AnalyticsPlaceholderWidget;
use Wave\Widgets\PostsPagesWidget;
use Wave\Widgets\UsersWidget;
use Wave\Widgets\WaveInfoWidget;
use Wave\Widgets\WelcomeWidget;

class AdminPanelProvider extends PanelProvider
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-presentation-chart-line';
    }

    private $dynamicWidgets = [];

    public function panel(Panel $panel): Panel
    {
        $this->renderAnalyticsIfCredentialsExist();

        Blade::component('wave::admin.components.label', 'label');

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->colors([
                'danger' => Color::Red,
                'gray' => Color::Zinc,
                'info' => Color::Blue,
                'primary' => config('wave.primary_color'),
                'success' => Color::Green,
                'warning' => Color::Amber,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            // "Return to sos-vault" — a top-of-sidebar link back out of the admin
            // panel into the main app (the user dashboard), so operators aren't
            // stranded in /admin with no obvious way back to the product UI.
            ->navigationItems([
                NavigationItem::make('Return to sos-vault')
                    ->url('/dashboard', shouldOpenInNewTab: false)
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->sort(-1),
            ])
            // ->discoverWidgets(in: app_path('BezhanSalleh\FilamentGoogleAnalytics\Widgets'), for: 'BezhanSalleh\\FilamentGoogleAnalytics\\Widgets')
            ->widgets([
                WaveInfoWidget::class,
                WelcomeWidget::class,
                UsersWidget::class,
                PostsPagesWidget::class,
                ...$this->dynamicWidgets,

                // Google Analytics Widgets that are available here: https://filamentphp.com/plugins/bezhansalleh-google-analytics
                PageViewsWidget::class,
                VisitorsWidget::class,
                ActiveUsersOneDayWidget::class,
                ActiveUsersSevenDayWidget::class,
                ActiveUsersTwentyEightDayWidget::class,
                SessionsWidget::class,
                SessionsDurationWidget::class,
                SessionsByCountryWidget::class,
                SessionsByDeviceWidget::class,
                MostVisitedPagesWidget::class,
                TopReferrersListWidget::class,
            ])
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
                // \App\Http\Middleware\WaveEditTab::class
            ])
            ->authMiddleware([
                Authenticate::class,
                RequireTwoFactor::class,
            ])
            // Surface database notifications (bell icon) so background jobs — e.g.
            // the queued AI-model download — can report progress and completion
            // back to the admin who started them, polling every 30s.
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->brandLogo(fn () => view('wave::admin.logo'))
            ->darkModeBrandLogo(fn () => view('wave::admin.logo-dark'));
    }

    // This function will render if user has account crenditals file
    // located at storage/app/analytics/service-account-credentials.json
    // Find More details here: https://github.com/spatie/laravel-analytics
    private function renderAnalyticsIfCredentialsExist()
    {
        if (file_exists(storage_path('app/analytics/service-account-credentials.json'))) {
            Config::set('filament-google-analytics.page_views.filament_dashboard', true);
            Config::set('filament-google-analytics.active_users_one_day.filament_dashboard', true);
            Config::set('filament-google-analytics.active_users_seven_day.filament_dashboard', true);
            Config::set('filament-google-analytics.active_users_twenty_eight_day.filament_dashboard', true);
            Config::set('filament-google-analytics.most_visited_pages.filament_dashboard', true);
            Config::set('filament-google-analytics.top_referrers_list.filament_dashboard', true);
        } elseif (! isAppliance()) {
            // Google Analytics is a SaaS-only concern; never show the "set up
            // analytics" placeholder chart on the self-hosted appliance.
            $this->dynamicWidgets = [AnalyticsPlaceholderWidget::class];
        }
    }
}
