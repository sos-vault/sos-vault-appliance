<?php
use App\Models\ITSMProvider;
use App\Models\Vault;
use App\Providers\VaultTools;
use App\Services\JiraService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Encryption\Encrypter;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconSize;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Livewire\Volt\Component;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;

middleware('auth');
name('settings.itsm');

new class extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms, InteractsWithTable;

    public ?array $data = [];

    public $vid;

    public $provider;

    public $url;

    public $user;

    public $password;

    public $customer_field;

    public bool $passwordExists = false;

    private $html;

    public function mount(): void
    {
        if (! checkAccess(auth()->user(), 'ITSM Integration')) {
            $this->redirect(route('settings.profile'));

            return;
        }

        $vtools = new VaultTools(auth()->user());
        $vid = $vtools->getVaultId();
        $vault = $vid ? Vault::find($vid) : null;

        if (! isset($vault)) {
            $message = "Couldn't find your vault. Cannot continue.";
            notifyError($message);

            return;
        }

        $this->vid = $vault->id;

        $this->provider = 'JSM';

        $itsm = ITSMProvider::where('uid', auth()->user()->id)
            ->where('vid', $this->vid)
            ->where('provider', $this->provider)
            ->first();

        if (isset($itsm) && ! empty($itsm)) {
            $this->passwordExists = ! empty($itsm->password);
            $this->form->fill([
                'provider' => $itsm->provider,
                'url' => $itsm->url,
                'user' => $itsm->user,
                'password' => '', // never pre-fill encrypted ciphertext; leave blank to keep existing
                'customer_field' => $itsm->customer_field,
            ]);
        } else {
            $this->form->fill([
                'provider' => $this->provider,
            ]);
        }
    }

    public function clearState(): void
    {
        $this->passwordExists = false;
        $this->form->fill([
            'provider' => $this->provider,
            'url' => '',
            'user' => '',
            'password' => '',
            'customer_field' => '',
        ]);
    }

    public function saveITSM($provider, $data)
    {
        $encrypter = new Encrypter(
            key: getSvaultKey('svault0'),
            cipher: config('app.cipher'),
        );

        $itsm = ITSMProvider::where('provider', $provider)
            ->where('uid', auth()->user()->id)
            ->first();

        $uid = auth()->user()->id;

        if (isset($itsm) && ! empty($itsm)) {
            $doSave = false;
            foreach ($data as $key => $value) {
                if ($key === 'password') {
                    if (empty($value)) {
                        continue; // blank means "keep existing password"
                    }
                    $value = $encrypter->encrypt($value);
                }
                if ($value !== $itsm->{$key}) {
                    Log::info("$key changed");
                    $itsm->{$key} = $value;
                    $doSave = true;
                }
            }
            if ($doSave) {
                $itsm->save();
                addEvent((object) ['message' => 'ITSM credentials updated', 'provider' => $provider], 'CHG_ITSM', 'SUCCESS', 'ACTIVITY', 0, $this->vid ?? 0, $uid, $uid);
            }
            $this->passwordExists = ! empty($itsm->password);
            Notification::make()
                ->title(__('settings.itsm_saved_ok', ['provider' => $provider]))
                ->success()
                ->send();
        } else {
            ITSMProvider::create([
                'vid' => $this->vid,
                'uid' => $uid,
                'gid' => $uid,
                'provider' => $provider,
                'url' => $data['url'] ?? null,
                'user' => $data['user'] ?? null,
                'password' => ! empty($data['password']) ? $encrypter->encrypt($data['password']) : null,
                'customer_field' => $data['customer_field'] ?? null,
            ]);
            addEvent((object) ['message' => 'ITSM credentials added', 'provider' => $provider], 'ADD_ITSM', 'SUCCESS', 'ACTIVITY', 0, $this->vid ?? 0, $uid, $uid);
            $this->passwordExists = ! empty($data['password']);
            Notification::make()
                ->title(__('settings.itsm_saved_ok', ['provider' => $provider]))
                ->success()
                ->send();
        }
    }

    public function deleteITSM($provider): void
    {
        $itsm = ITSMProvider::where('provider', $provider)
            ->where('uid', auth()->user()->id)
            ->first();

        if (isset($itsm) && ! empty($itsm)) {
            $itsm->delete();
            $uid = auth()->user()->id;
            addEvent((object) ['message' => 'ITSM credentials deleted', 'provider' => $provider], 'DEL_ITSM', 'SUCCESS', 'ACTIVITY', 0, $this->vid ?? 0, $uid, $uid);
            $this->clearState();
            Notification::make()
                ->title(__('settings.itsm_deleted_ok', ['provider' => $provider]))
                ->success()
                ->send();
        }
    }

    public function testITSM(string $provider): void
    {
        $uid = auth()->user()->id;
        $jira = app(JiraService::class);
        $ok = $jira->testConnection(auth()->user());

        if ($ok) {
            Notification::make()
                ->title(__('settings.itsm_test_ok', ['provider' => $provider]))
                ->success()
                ->send();
            addEvent((object) ['message' => "ITSM connection test OK for {$provider}"], 'TEST_ITSM', 'SUCCESS', 'ACTIVITY', 0, $this->vid ?? 0, $uid, $uid);
        } else {
            notifyError(__('settings.itsm_test_failed', ['provider' => $provider]));
            addEvent((object) ['message' => "ITSM connection test FAILED for {$provider}"], 'TEST_ITSM', 'FAILED', 'ACTIVITY', 0, $this->vid ?? 0, $uid, $uid);
        }
    }

    protected function getFormSchema(): array
    {
        return [
            Grid::make(2)->schema([
                TextInput::make('provider')->hidden(),
                TextInput::make('url'),
                TextInput::make('user'),
                TextInput::make('password'),
                TextInput::make('customer_field'),
            ]),
        ];
    }

    public function makeForm($provider)
    {
        $form = [];
        $section = null;
        $icon = '';
        $service = '';

        switch ($provider) {
            case 'Jira':
            case 'JSM':
                $icon = 'simpleicon-jirasoftware';
                $service = 'Jira Service Management';
                break;
            case 'Slack':
                $icon = 'icon-slack';
                $service = 'Slack';
                break;
            case 'PagerDuty':
                $icon = 'simpleicon-pagerduty';
                $service = 'Pager Duty';
                break;
            case 'ServiceNow':
                $icon = 'ph-duotone ph-user-circle';
                $service = 'Service Now';
                break;
        }

        if ($provider == 'Jira' || $provider == 'JSM') {
            $intro           = __('settings.itsm_jira_intro', ['service' => $service]);
            $itemUrl         = __('settings.itsm_jira_item_url', ['provider' => $provider]);
            $itemUser        = __('settings.itsm_jira_item_user', ['provider' => $provider]);
            $itemToken       = __('settings.itsm_jira_item_token');
            $itemCustomField = __('settings.itsm_jira_item_custom_field', ['provider' => $provider]);
            $tokenInstr      = __('settings.itsm_jira_token_instructions', ['provider' => $provider]);

            $this->html[$provider] = <<<HTML
                    <div class="py-1 text-sm text-slate-600 dark:text-zinc-300">
                        $intro
                    </div>

                    <div class="py-2 pl-4 text-sm text-slate-600 dark:text-zinc-300">
                        <nl>
                            <li>$itemUrl</li>
                            <li>$itemUser</li>
                            <li>$itemToken</li>
                            <li>$itemCustomField</li>
                        </nl>
                    </div>

                    <div class="py-1 text-sm text-slate-600 dark:text-zinc-300">
                        $tokenInstr
                    </div>
                HTML;
        } else {
            $comingSoon = __('settings.itsm_coming_soon', ['service' => $service]);
            $this->html[$provider] = <<<HTML
                    <div class="m-32 w-full text-lg text-slate-600 dark:text-zinc-300">
                            $comingSoon
                    </div>
                HTML;
        }

        $section = Section::make('')
            ->contained(false)
            ->heading(__('settings.itsm_provider_settings_heading', ['provider' => $provider]))
            ->icon($icon)
            ->iconSize(IconSize::Large)
            ->iconColor('primary')
            ->compact(true);

        switch ($provider) {
            case 'JSM':
                $section->schema([
                    Text::make(function () {
                        return new HtmlString($this->html['JSM']);
                    }),
                ]);
                break;
            case 'Slack':
                $section->schema([
                    Text::make(function () {
                        return new HtmlString($this->html['Slack']);
                    }),
                ]);
                $section->extraAttributes([
                    'class' => 'flex justify-center items-center',
                ]);
                $section->heading('');
                $section->icon('');
                $form[] = $section;

                return $form;
                break;
            case 'PagerDuty':
                $section->schema([
                    Text::make(function () {
                        return new HtmlString($this->html['PagerDuty']);
                    }),
                ]);
                $section->extraAttributes([
                    'class' => 'flex justify-center items-center',
                ]);
                $section->heading('');
                $section->icon('');
                $form[] = $section;

                return $form;
                break;
            case 'ServiceNow':
                $section->schema([
                    Text::make(function () {
                        return new HtmlString($this->html['ServiceNow']);
                    }),
                ]);
                $section->extraAttributes([
                    'class' => 'flex justify-center items-center',
                ]);
                $section->heading('');
                $section->icon('');
                $form[] = $section;

                return $form;
                break;
        }

        $form[] = $section;
        $form[] = Grid::make(2)
            ->schema([
                TextInput::make('provider')
                    ->readOnly()
                    ->hidden()
                    ->dehydrated(true)
                    ->afterStateUpdated(fn () => $set('provider', $provider)),

                TextInput::make('url')
                    ->label(__('settings.itsm_url_label', ['provider' => $provider]))
                    ->placeholder(__('settings.itsm_url_placeholder'))
                    ->url()
                    ->trim()
                    ->minLength(25)
                    ->maxLength(100),

                TextInput::make('user')
                    ->label(__('settings.itsm_user_label', ['provider' => $provider]))
                    ->placeholder(__('settings.itsm_user_placeholder'))
                    ->email()
                    ->trim()
                    ->minLength(5)
                    ->maxLength(100),

                TextInput::make('password')
                    ->label(__('settings.itsm_password_label', ['provider' => $provider]))
                    ->trim()
                    ->password()
                    ->revealable()
                    ->minLength(10)
                    ->maxLength(512)
                    ->placeholder(fn () => $this->passwordExists ? __('settings.itsm_password_saved_hint') : '')
                    ->hint(fn () => $this->passwordExists ? __('settings.itsm_password_saved_hint') : ''),

                TextInput::make('customer_field')
                    ->label(__('settings.itsm_customer_field_label'))
                    ->placeholder(__('settings.itsm_customer_field_placeholder'))
                    ->trim()
                    ->minLength(5)
                    ->maxLength(50),
            ]);

        $form[] = Flex::make([
            Action::make('save')
                ->color('primary')
                ->action(function ($data) use ($provider) {
                    $this->saveITSM($provider, $this->form->getState());
                })
                ->label(__('settings.itsm_action_save')),
            Action::make('clear')
                ->color('gray')
                ->action(function ($data) {
                    $this->clearState();
                })
                ->label(__('settings.itsm_action_clear')),
            Action::make('delete')
                ->color('danger')
                ->action(function ($data) use ($provider) {
                    $this->deleteITSM($provider);
                })
                ->label(__('settings.itsm_action_delete')),
            Action::make('test')
                ->color('info')
                ->action(function () use ($provider) {
                    $this->testITSM($provider);
                })
                ->label(__('settings.itsm_action_test'))
                ->visible(fn () => $this->passwordExists),
        ])
            ->extraAttributes([
                'class' => 'justify-end',
                ]);

        return $form;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Tabs')
                    ->contained()
                    ->persistTab()
                    ->tabs([
                        Tab::make('JSM')
                            ->icon('simpleicon-jirasoftware')
                            ->schema($this->makeForm('JSM')),
                        Tab::make('ServiceNow')
                            ->icon('phosphor-user-circle-duotone')
                            ->schema($this->makeForm('ServiceNow')),
                        Tab::make('Slack')
                            ->icon('icon-slack')
                            ->schema($this->makeForm('Slack')),
                        Tab::make('PagerDuty')
                            ->icon('simpleicon-pagerduty')
                            ->schema($this->makeForm('PagerDuty')),
                    ]),
            ])
            ->statePath('data');
    }
}

?>

<x-layouts.app>
    @volt('settings.itsm')
        <div class="relative">
            <x-app.settings-layout
                :title="__('settings.itsm_title')"
                :description="__('settings.itsm_description')"
            >
                {{ $this->form }}

            </x-app.settings-layout>
        </div>
    @endvolt
</x-layouts.app>
