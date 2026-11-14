<?php

namespace Wave;

use App\Models\Forms;
use DevDojo\Themes\Models\Theme;
use Exception;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Intervention\Image\Laravel\Facades\Image;
use Laravel\Folio\Folio;
use Livewire\Livewire;
use Wave\Console\Commands\CancelExpiredSubscriptions;
use Wave\Console\Commands\CreatePluginCommand;
use Wave\Facades\Wave as WaveFacade;
use Wave\Http\Livewire\Billing\Checkout;
use Wave\Http\Livewire\Billing\Update;
use Wave\Http\Middleware\Cancelled;
use Wave\Http\Middleware\Subscribed;
use Wave\Http\Middleware\TokenMiddleware;
use Wave\Http\Middleware\TrialEnded;
use Wave\Http\Middleware\VerifyPaddleWebhookSignature;
use Wave\Plugins\PluginServiceProvider;

class WaveServiceProvider extends ServiceProvider
{
    public function register()
    {

        $loader = AliasLoader::getInstance();
        $loader->alias('Wave', WaveFacade::class);

        $this->app->singleton('wave', function () {
            return new Wave;
        });

        $this->loadHelpers();

        $this->loadLivewireComponents();

        $waveMiddleware = [
            Authenticate::class,
            TrialEnded::class,
            Cancelled::class,
        ];

        $this->app->router->aliasMiddleware('paddle-webhook-signature', VerifyPaddleWebhookSignature::class);
        $this->app->router->aliasMiddleware('subscribed', Subscribed::class);
        $this->app->router->aliasMiddleware('token_api', TokenMiddleware::class);

        $this->app->router->middlewareGroup('wave', $waveMiddleware);

        // Register the PluginServiceProvider
        $this->app->register(PluginServiceProvider::class);
    }

    public function boot(Router $router, Dispatcher $event)
    {

        Relation::morphMap([
            'users' => config('wave.user_model'),
        ]);

        $this->registerFilamentComponentsFriendlyNames();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'wave');
        $this->loadMigrationsFrom(realpath(__DIR__.'/../database/migrations'));
        $this->loadBladeDirectives();
        $this->setDefaultThemeColors();

        FilamentColor::register([
            'danger' => Color::Red,
            'gray' => Color::Zinc,
            'info' => Color::Blue,
            'primary' => config('wave.primary_color'),
            'success' => Color::Green,
            'warning' => Color::Amber,
        ]);

        Validator::extend('imageable', function ($attribute, $value, $params, $validator) {
            try {
                Image::read($value);

                return true;
            } catch (Exception $e) {
                return false;
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                CancelExpiredSubscriptions::class,
                CreatePluginCommand::class,
            ]);
            // $this->excludeInactiveThemes();
        }

        Relation::morphMap([
            'user' => config('auth.providers.model'),
            'form' => Forms::class,
            // Add other mappings as needed
        ]);

        $this->registerWaveFolioDirectory();
        $this->registerWaveComponentDirectory();
    }

    protected function loadHelpers()
    {
        foreach (glob(__DIR__.'/Helpers/*.php') as $filename) {
            require_once $filename;
        }
    }

    protected function loadMiddleware()
    {
        foreach (glob(__DIR__.'/Http/Middleware/*.php') as $filename) {
            require_once $filename;
        }
    }

    protected function loadBladeDirectives()
    {

        // app()->afterResolving('blade.compiler', function (BladeCompiler $bladeCompiler) {
        // @admin directives
        Blade::if('admin', function () {
            return ! auth()->guest() && auth()->user()->isAdmin();
        });

        // @subscriber directives
        Blade::if('subscriber', function () {
            return ! auth()->guest() && auth()->user()->subscriber();
        });

        // @notsubscriber directives
        Blade::if('notsubscriber', function () {
            return ! auth()->guest() && ! auth()->user()->subscriber();
        });

        // Subscribed Directives
        Blade::if('subscribed', function ($plan) {
            return ! auth()->guest() && auth()->user()->subscribedToPlan($plan);
        });

        // home directives
        Blade::if('home', function () {
            return request()->is('/');
        });

        // Trial Directives
        Blade::directive('trial', function ($plan) {
            return '<?php if (!auth()->guest() && auth()->user()->onTrial()) { ?>';
        });

        Blade::directive('nottrial', function () {
            return '<?php } else { ?>';
        });

        Blade::directive('endtrial', function () {
            return '<?php } ?>';
        });

        // role Directives
        Blade::directive('role', function ($role) {
            return "<?php if (!auth()->guest() && auth()->user()->hasRole($role)) { ?>";
        });

        Blade::directive('notrole', function () {
            return '<?php } else { ?>';
        });

        Blade::directive('endrole', function () {
            return '<?php } ?>';
        });

    }

    protected function registerFilamentComponentsFriendlyNames()
    {
        // Blade::component('filament::components.avatar', 'avatar');
        Blade::component('filament::components.dropdown.index', 'dropdown');
        Blade::component('filament::components.dropdown.list.index', 'dropdown.list');
        Blade::component('filament::components.dropdown.list.item', 'dropdown.list.item');
    }

    protected function registerWaveFolioDirectory()
    {
        if (File::exists(base_path('wave/resources/views/pages'))) {
            Folio::path(base_path('wave/resources/views/pages'))->middleware([
                '*' => [
                    //
                ],
            ]);
        }
    }

    protected function registerWaveComponentDirectory()
    {
        Blade::anonymousComponentPath(base_path('wave/resources/views/components'));
    }

    private function loadLivewireComponents()
    {
        Livewire::component('billing.checkout', Checkout::class);
        Livewire::component('billing.update', Update::class);
    }

    protected function setDefaultThemeColors()
    {
        if (config('wave.demo')) {
            $theme = $this->getActiveTheme();

            if (isset($theme->id)) {
                if (Cookie::get('theme')) {
                    $theme_cookied = Theme::where('folder', '=', Cookie::get('theme'))->first();
                    if (isset($theme_cookied->id)) {
                        $theme = $theme_cookied;
                    }
                }

                $default_theme_color = match ($theme->folder) {
                    'anchor' => '#000000',
                    'blank' => '#090909',
                    'cove' => '#0069ff',
                    'drift' => '#000000',
                    'fusion' => '#0069ff'
                };

                Config::set('wave.primary_color', $default_theme_color);
            }
        }
    }

    protected function getActiveTheme()
    {
        return \Wave\Theme::where('active', 1)->first();
    }
}
