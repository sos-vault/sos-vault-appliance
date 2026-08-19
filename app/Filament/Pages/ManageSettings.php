<?php

namespace App\Filament\Pages;

use App\Events\SendUserEmail;
use App\Models\Group;
use App\Models\LocalLicense;
use App\Models\User;
use App\Rules\HostnameOrIp;
use App\Services\Ai\ProviderProfile;
use App\Services\ApplianceNetworkSettings;
use App\Services\PasswordPolicy;
use App\Services\SiemForwarder;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\Permission\Models\Role;
use Wave\Setting;

/**
 * @property-read Schema $form
 */
class ManageSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'phosphor-gear-fine-duotone';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.manage-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $flat = Setting::pluck('value', 'key')->all();

        // Never load the licensing passphrase into the form: the value is
        // sensitive (encrypted at rest with svault0) and must NOT be sent to
        // the browser, even masked. The UI only exposes whether one is set.
        unset($flat[LICENSING_PASSPHRASE_SETTING_KEY]);

        // SIEM settings are all stored encrypted (svault0). Decrypt the scalar
        // fields so the form shows real host/port/protocol/format values; the
        // two certificates are write-only and never sent to the browser (their
        // SET / NOT-SET status is surfaced via helper text instead).
        foreach (SIEM_SETTING_KEYS as $siemKey) {
            if ($siemKey === 'siem.ca_cert' || $siemKey === 'siem.server_cert') {
                unset($flat[$siemKey]);

                continue;
            }
            if (array_key_exists($siemKey, $flat)) {
                $flat[$siemKey] = decryptSiemSetting($flat[$siemKey]);
            }
        }

        // AI provider API keys, the ServiceNow password, and the AWS secret
        // access key are stored encrypted (svault0); decrypt them so the form
        // shows the real value, same as every other password field on this page.
        foreach (ENCRYPTED_SETTING_KEYS as $encryptedKey) {
            if (array_key_exists($encryptedKey, $flat)) {
                $flat[$encryptedKey] = decryptSetting($flat[$encryptedKey]);
            }
        }

        $nested = [];
        foreach ($flat as $key => $value) {
            Arr::set($nested, $key, $value);
        }

        // Prefill the appliance vault directory with its effective default so
        // the "Main Vault" field is never blank on a fresh install.
        if (isAppliance() && ! Arr::has($nested, 'appliance.vault_dir')) {
            Arr::set($nested, 'appliance.vault_dir', $this->currentVaultDir());
        }

        // Prefill the default vault size with its effective default (matches the
        // provisioning fallback) so the field shows 500 rather than blank until
        // the operator saves it for the first time.
        if (applianceLicensed() && ! Arr::has($nested, 'appliance.default_vault_size_mb')) {
            Arr::set($nested, 'appliance.default_vault_size_mb', 500);
        }

        $this->form->fill($nested);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Site')
                        ->visible(isSaas())
                        ->description('General site configuration')
                        ->icon('phosphor-globe-duotone')
                        ->headerActions([
                            Action::make('save_site')
                                ->label('Save')
                                ->icon('phosphor-floppy-disk-duotone')
                                ->action(fn () => $this->saveGroup('site', 'Site')),
                        ])
                        ->schema([
                            TextInput::make('site.title')
                                ->label('Site Title')
                                ->maxLength(191),
                            TextInput::make('site.description')
                                ->label('Site Description')
                                ->maxLength(191),
                            TextInput::make('site.app_name')
                                ->label('Application Name')
                                ->maxLength(191),
                            TextInput::make('site.app_url')
                                ->label('Application URL')
                                ->url()
                                ->maxLength(191),
                            TextInput::make('site.app_version')
                                ->label('Application Version')
                                ->maxLength(50),
                            Toggle::make('site.trial_end_emails')
                                ->label('Send Trial-End Reminder Emails')
                                ->helperText('When enabled, Free trial users receive a daily email during the final 7 days of their trial.')
                                ->afterStateHydrated(fn (Toggle $component, $state) => $component->state((bool) $state))
                                ->dehydrateStateUsing(fn ($state) => $state ? '1' : '0'),
                        ])
                        ->columns(2)
                        ->collapsible()
                        ->collapsed(),

                    Section::make('Authentication')
                        ->visible(isSaas() || applianceLicensed())
                        ->description('User registration, login, verification, and password settings')
                        ->icon('phosphor-lock-key-duotone')
                        ->headerActions([
                            Action::make('save_auth')
                                ->label('Save')
                                ->icon('phosphor-floppy-disk-duotone')
                                ->action(fn () => $this->saveGroup('auth', 'Authentication')),
                        ])
                        ->schema([
                            Toggle::make('auth.dashboard_redirect')
                                ->label('Redirect Homepage to Dashboard if Logged In')
                                ->helperText('When enabled, authenticated users visiting / are redirected to /dashboard.')
                                ->afterStateHydrated(fn (Toggle $component, $state) => $component->state((bool) $state))
                                ->dehydrateStateUsing(fn ($state) => $state ? '1' : '0'),
                            Select::make('auth.email_or_username')
                                ->label('Users Login With')
                                ->options([
                                    'email' => 'Email address',
                                    'username' => 'Username',
                                ]),
                            Select::make('auth.username_in_registration')
                                ->label('Username Field During Registration')
                                ->options([
                                    'yes' => 'Show username field',
                                    'no' => 'Hide username field (auto-generate)',
                                ]),
                            Toggle::make('auth.verify_email')
                                ->label('Require Email Verification on Sign Up')
                                ->afterStateHydrated(fn (Toggle $component, $state) => $component->state((bool) $state))
                                ->dehydrateStateUsing(fn ($state) => $state ? '1' : '0'),
                            Select::make('auth.default_role')
                                ->label('Default Role Assigned at Registration')
                                ->options(fn () => static::defaultRoleOptions()),
                            Select::make('auth.password_complexity')
                                ->label('Password Complexity')
                                ->options([
                                    PasswordPolicy::MODE_DEFAULT => 'Default (12+ chars, 2 each: upper / lower / digit / sign)',
                                    PasswordPolicy::MODE_RELAXED => 'Relaxed (9+ chars, 1 each: upper / lower / digit / sign)',
                                    PasswordPolicy::MODE_CUSTOM => 'Custom (set your own counts below)',
                                ])
                                ->default(PasswordPolicy::MODE_DEFAULT)
                                ->live()
                                ->helperText('Controls registration, password reset, and profile password validation. Replaces the legacy "Minimum Password Length" setting (kept in sync automatically).'),
                            TextInput::make('auth.password_custom_min_length')
                                ->label('Custom: Minimum Length')
                                ->numeric()
                                ->minValue(6)
                                ->maxValue(128)
                                ->default(12)
                                ->visible(fn (Get $get): bool => $get('auth.password_complexity') === PasswordPolicy::MODE_CUSTOM),
                            TextInput::make('auth.password_custom_min_digits')
                                ->label('Custom: Minimum Digits')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(32)
                                ->default(2)
                                ->visible(fn (Get $get): bool => $get('auth.password_complexity') === PasswordPolicy::MODE_CUSTOM),
                            TextInput::make('auth.password_custom_min_upper')
                                ->label('Custom: Minimum Uppercase Letters')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(32)
                                ->default(2)
                                ->visible(fn (Get $get): bool => $get('auth.password_complexity') === PasswordPolicy::MODE_CUSTOM),
                            TextInput::make('auth.password_custom_min_lower')
                                ->label('Custom: Minimum Lowercase Letters')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(32)
                                ->default(2)
                                ->visible(fn (Get $get): bool => $get('auth.password_complexity') === PasswordPolicy::MODE_CUSTOM),
                            TextInput::make('auth.password_custom_min_signs')
                                ->label('Custom: Minimum Signs (non-alphanumeric)')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(32)
                                ->default(2)
                                ->visible(fn (Get $get): bool => $get('auth.password_complexity') === PasswordPolicy::MODE_CUSTOM),
                        ])
                        ->columns(2)
                        ->collapsible()
                        ->collapsed(),

                    Section::make('Social Authentication')
                        ->visible(isSaas())
                        ->description('OAuth credentials for Google, Facebook, and GitHub login. Leave blank to disable a provider.')
                        ->icon('phosphor-users-three-duotone')
                        ->headerActions([
                            Action::make('save_social_auth')
                                ->label('Save')
                                ->icon('phosphor-floppy-disk-duotone')
                                ->action(fn () => $this->saveGroup('auth', 'Social Authentication')),
                        ])
                        ->schema([
                            TextInput::make('auth.google_client_id')
                                ->label('Google Client ID'),
                            TextInput::make('auth.google_client_secret')
                                ->label('Google Client Secret')
                                ->password()
                                ->revealable(),
                            TextInput::make('auth.facebook_client_id')
                                ->label('Facebook App ID'),
                            TextInput::make('auth.facebook_client_secret')
                                ->label('Facebook App Secret')
                                ->password()
                                ->revealable(),
                            TextInput::make('auth.github_client_id')
                                ->label('GitHub Client ID'),
                            TextInput::make('auth.github_client_secret')
                                ->label('GitHub Client Secret')
                                ->password()
                                ->revealable(),
                        ])
                        ->columns(2)
                        ->collapsible()
                        ->collapsed(),

                    Section::make('Mail')
                        ->description('Outgoing email (SMTP) and sender configuration')
                        ->icon('phosphor-envelope-duotone')
                        ->headerActions([
                            Action::make('testEmail')
                                ->label('Send Test Email')
                                ->icon('phosphor-paper-plane-duotone')
                                ->color('gray')
                                ->modalHeading('Send Test Email')
                                ->modalSubmitActionLabel('Send')
                                ->fillForm(fn (): array => [
                                    'subject' => 'SOS-Vault — Test Email',
                                    'body' => '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.</p><p>Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.</p>',
                                ])
                                ->schema([
                                    Grid::make(2)
                                        ->schema([
                                            TextInput::make('to')
                                                ->label('To')
                                                ->email()
                                                ->required()
                                                ->maxLength(191),
                                            TextInput::make('subject')
                                                ->label('Subject')
                                                ->required()
                                                ->maxLength(191),
                                        ]),
                                    RichEditor::make('body')
                                        ->label('Body')
                                        ->required(),
                                ])
                                ->action(fn (array $data) => $this->sendTestEmail(
                                    $data['to'],
                                    $data['subject'],
                                    $data['body'],
                                )),
                            Action::make('save_mail')
                                ->label('Save')
                                ->icon('phosphor-floppy-disk-duotone')
                                ->action(fn () => $this->saveGroup('mail', 'Mail')),
                        ])
                        ->schema([
                            Select::make('mail.mailer')
                                ->label('Mail Driver')
                                ->options([
                                    'smtp' => 'SMTP',
                                    'mailgun' => 'Mailgun',
                                    'ses' => 'Amazon SES',
                                    'postmark' => 'Postmark',
                                    'sendmail' => 'Sendmail',
                                    'log' => 'Log (testing)',
                                    'array' => 'Array (testing)',
                                ]),
                            Select::make('mail.encryption')
                                ->label('SMTP Encryption')
                                ->options([
                                    'tls' => 'TLS',
                                    'ssl' => 'SSL',
                                    '' => 'None',
                                ]),
                            TextInput::make('mail.host')
                                ->label('SMTP Host')
                                ->maxLength(191),
                            TextInput::make('mail.port')
                                ->label('SMTP Port')
                                ->maxLength(10),
                            TextInput::make('mail.username')
                                ->label('SMTP Username')
                                ->maxLength(191),
                            TextInput::make('mail.password')
                                ->label('SMTP Password')
                                ->password()
                                ->revealable()
                                ->maxLength(191),
                            TextInput::make('mail.from_address')
                                ->label('From Address')
                                ->email()
                                ->maxLength(191),
                            TextInput::make('mail.from_name')
                                ->label('From Name')
                                ->maxLength(191),
                        ])
                        ->columns(2)
                        ->collapsible()
                        ->collapsed(),

                    Section::make('Logging')
                        ->visible(isSaas())
                        ->description('Application log channel and verbosity level')
                        ->icon('phosphor-file-text-duotone')
                        ->headerActions([
                            Action::make('save_logging')
                                ->label('Save')
                                ->icon('phosphor-floppy-disk-duotone')
                                ->action(fn () => $this->saveGroup('logging', 'Logging')),
                        ])
                        ->schema([
                            Select::make('logging.channel')
                                ->label('Default Log Channel')
                                ->options([
                                    'stack' => 'Stack',
                                    'single' => 'Single',
                                    'daily' => 'Daily',
                                    'syslog' => 'Syslog',
                                    'errorlog' => 'Errorlog',
                                    'null' => 'Null (disable)',
                                ]),
                            Select::make('logging.level')
                                ->label('Log Level')
                                ->options([
                                    'debug' => 'Debug',
                                    'info' => 'Info',
                                    'notice' => 'Notice',
                                    'warning' => 'Warning',
                                    'error' => 'Error',
                                    'critical' => 'Critical',
                                    'alert' => 'Alert',
                                    'emergency' => 'Emergency',
                                ]),
                            Select::make('logging.deprecations_channel')
                                ->label('Deprecations Channel')
                                ->options([
                                    'null' => 'Null (ignore)',
                                    'stack' => 'Stack',
                                    'single' => 'Single',
                                    'daily' => 'Daily',
                                ]),
                        ])
                        ->columns(2)
                        ->collapsible()
                        ->collapsed(),

                    Section::make('Analytics')
                        ->visible(isSaas())
                        ->description('Google Analytics and YouTube API configuration')
                        ->icon('phosphor-chart-bar-duotone')
                        ->headerActions([
                            Action::make('save_analytics')
                                ->label('Save')
                                ->icon('phosphor-floppy-disk-duotone')
                                ->action(fn () => $this->saveGroup('analytics', 'Analytics')),
                        ])
                        ->schema([
                            TextInput::make('analytics.ga_property_id')
                                ->label('Google Analytics Property ID')
                                ->maxLength(191),
                            TextInput::make('analytics.youtube_api_key_1')
                                ->label('YouTube API Key 1')
                                ->password()
                                ->revealable()
                                ->maxLength(191),
                            TextInput::make('analytics.youtube_api_key_2')
                                ->label('YouTube API Key 2')
                                ->password()
                                ->revealable()
                                ->maxLength(191),
                        ])
                        ->columns(2)
                        ->collapsible()
                        ->collapsed(),

                    Section::make('Security')
                        ->visible(isSaas())
                        ->description('reCAPTCHA, MaxMind, and AbuseIPDB configuration')
                        ->icon('phosphor-shield-check-duotone')
                        ->headerActions([
                            Action::make('save_security')
                                ->label('Save')
                                ->icon('phosphor-floppy-disk-duotone')
                                ->action(fn () => $this->saveGroup('security', 'Security')),
                        ])
                        ->schema([
                            TextInput::make('security.recaptcha_site_key')
                                ->label('reCAPTCHA Site Key')
                                ->maxLength(191),
                            TextInput::make('security.recaptcha_secret_key')
                                ->label('reCAPTCHA Secret Key')
                                ->password()
                                ->revealable()
                                ->maxLength(191),
                            TextInput::make('security.maxmind_license_key')
                                ->label('MaxMind License Key')
                                ->password()
                                ->revealable()
                                ->maxLength(191),
                            TextInput::make('security.abuseip_storage_path')
                                ->label('AbuseIPDB Storage Path')
                                ->maxLength(191),
                            TextInput::make('security.abuseip_storage_compress')
                                ->label('AbuseIPDB Compress Storage')
                                ->maxLength(10),
                            TextInput::make('security.jwt_secret')
                                ->label('JWT Secret')
                                ->password()
                                ->revealable()
                                ->maxLength(191),
                        ])
                        ->columns(2)
                        ->collapsible()
                        ->collapsed(),

                    Section::make('Billing')
                        ->visible(isSaas())
                        ->description('Paddle and Stripe payment credentials')
                        ->icon('phosphor-credit-card-duotone')
                        ->headerActions([
                            Action::make('save_billing')
                                ->label('Save')
                                ->icon('phosphor-floppy-disk-duotone')
                                ->action(fn () => $this->saveGroup('billing', 'Billing')),
                        ])
                        ->schema([
                            Select::make('billing.provider')
                                ->label('Billing Provider')
                                ->options([
                                    'paddle' => 'Paddle',
                                    'stripe' => 'Stripe',
                                ]),
                            Select::make('billing.paddle_env')
                                ->label('Paddle Environment')
                                ->options([
                                    'sandbox' => 'Sandbox',
                                    'production' => 'Production',
                                ]),
                            TextInput::make('billing.paddle_vendor_id')
                                ->label('Paddle Vendor ID')
                                ->maxLength(191),
                            TextInput::make('billing.paddle_api_key')
                                ->label('Paddle API Key')
                                ->password()
                                ->revealable()
                                ->maxLength(191),
                            TextInput::make('billing.paddle_client_side_token')
                                ->label('Paddle Client Side Token')
                                ->password()
                                ->revealable()
                                ->maxLength(191),
                            TextInput::make('billing.paddle_public_key')
                                ->label('Paddle Public Key')
                                ->password()
                                ->revealable()
                                ->maxLength(191),
                            TextInput::make('billing.paddle_webhook_secret')
                                ->label('Paddle Webhook Secret')
                                ->password()
                                ->revealable()
                                ->maxLength(191),
                            TextInput::make('billing.stripe_publishable_key')
                                ->label('Stripe Publishable Key')
                                ->maxLength(191),
                            TextInput::make('billing.stripe_secret_key')
                                ->label('Stripe Secret Key')
                                ->password()
                                ->revealable()
                                ->maxLength(191),
                            TextInput::make('billing.stripe_webhook_secret')
                                ->label('Stripe Webhook Secret')
                                ->password()
                                ->revealable()
                                ->maxLength(191),
                            Toggle::make('billing.card_upfront')
                                ->label('Require Credit Card Up Front')
                                ->helperText('When enabled, users must enter payment details before accessing the app.')
                                ->afterStateHydrated(fn (Toggle $component, $state) => $component->state((bool) $state))
                                ->dehydrateStateUsing(fn ($state) => $state ? '1' : '0')
                                ->columnSpanFull(),
                            TextInput::make('billing.trial_days')
                                ->label('Trial Days (when no card up front)')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(365)
                                ->helperText('Number of free trial days granted when card-up-front is disabled. Set to 0 to disable trials.'),
                        ])
                        ->columns(2)
                        ->collapsible()
                        ->collapsed(),

                    Section::make('Self-Hosted Bundle')
                        ->visible(isSaas())
                        ->description('Download links for the self-hosted appliance. Leave blank to hide the corresponding button on the landing page.')
                        ->icon('phosphor-package-duotone')
                        ->headerActions([
                            Action::make('save_standalone')
                                ->label('Save')
                                ->icon('phosphor-floppy-disk-duotone')
                                ->action(fn () => $this->saveGroup('standalone', 'Self-Hosted Bundle')),
                        ])
                        ->schema([
                            TextInput::make('standalone.deb_url')
                                ->label('Debian / Ubuntu package URL (.deb)')
                                ->url()
                                ->maxLength(2048),
                            TextInput::make('standalone.rpm_url')
                                ->label('RHEL / Rocky / AlmaLinux package URL (.rpm)')
                                ->url()
                                ->maxLength(2048),
                            TextInput::make('standalone.checksums_url')
                                ->label('SHA-256 checksums URL (optional)')
                                ->url()
                                ->maxLength(2048)
                                ->helperText('Link to SHA256SUMS published next to the packages.'),
                        ])
                        ->columns(2)
                        ->collapsible()
                        ->collapsed(),

                    Section::make('Telegram')
                        ->visible(isSaas())
                        ->description('Telegram bot notification settings')
                        ->icon('phosphor-telegram-logo-duotone')
                        ->headerActions([
                            Action::make('save_telegram')
                                ->label('Save')
                                ->icon('phosphor-floppy-disk-duotone')
                                ->action(fn () => $this->saveGroup('telegram', 'Telegram')),
                        ])
                        ->schema([
                            TextInput::make('telegram.api_key')
                                ->label('Telegram API Key')
                                ->password()
                                ->revealable()
                                ->maxLength(191),
                            TextInput::make('telegram.chat_id')
                                ->label('Telegram Chat ID')
                                ->maxLength(191),
                        ])
                        ->columns(2)
                        ->collapsible()
                        ->collapsed(),

                    Section::make('AWS / S3')
                        ->visible(isSaas())
                        ->description('Amazon S3 storage credentials and bucket configuration')
                        ->icon('phosphor-cloud-duotone')
                        ->headerActions([
                            Action::make('save_aws')
                                ->label('Save')
                                ->icon('phosphor-floppy-disk-duotone')
                                ->action(fn () => $this->saveGroup('aws', 'AWS / S3')),
                        ])
                        ->schema([
                            TextInput::make('aws.access_key_id')
                                ->label('AWS Access Key ID')
                                ->maxLength(191),
                            TextInput::make('aws.secret_access_key')
                                ->label('AWS Secret Access Key')
                                ->password()
                                ->revealable()
                                ->maxLength(191),
                            TextInput::make('aws.default_region')
                                ->label('AWS Default Region')
                                ->maxLength(50),
                            TextInput::make('aws.bucket')
                                ->label('S3 Bucket Name')
                                ->maxLength(191),
                            TextInput::make('aws.url')
                                ->label('S3 Custom URL')
                                ->url()
                                ->maxLength(191),
                            TextInput::make('aws.endpoint')
                                ->label('S3 Endpoint (custom / MinIO)')
                                ->url()
                                ->maxLength(191),
                            TextInput::make('aws.use_path_style_endpoint')
                                ->label('Use Path-Style Endpoint')
                                ->helperText('Set to true for MinIO or custom S3-compatible storage.')
                                ->maxLength(10),
                        ])
                        ->columns(2)
                        ->collapsible()
                        ->collapsed(),

                    Section::make('ServiceNow')
                        // Hidden on self-hosted: the ServiceNow ITSM
                        // integration is not implemented yet. Kept on the
                        // SaaS build only until the feature ships.
                        ->visible(isSaas())
                        ->description('ServiceNow ITSM integration credentials')
                        ->icon('phosphor-ticket-duotone')
                        ->headerActions([
                            Action::make('save_servicenow')
                                ->label('Save')
                                ->icon('phosphor-floppy-disk-duotone')
                                ->action(fn () => $this->saveGroup('servicenow', 'ServiceNow')),
                        ])
                        ->schema([
                            TextInput::make('servicenow.instance')
                                ->label('ServiceNow Instance URL')
                                ->url()
                                ->maxLength(191),
                            TextInput::make('servicenow.username')
                                ->label('ServiceNow Username')
                                ->maxLength(191),
                            TextInput::make('servicenow.password')
                                ->label('ServiceNow Password')
                                ->password()
                                ->revealable()
                                ->maxLength(191),
                        ])
                        ->columns(2)
                        ->collapsible()
                        ->collapsed(),

                    Section::make('SIEM Integration')
                        ->visible(isSaas() || applianceLicensed())
                        ->description('Forward every recorded event to your SIEM over Syslog (TCP, UDP or TLS), as ECS JSON or RFC 5424. All settings are encrypted at rest.')
                        ->icon('phosphor-broadcast-duotone')
                        ->headerActions([
                            Action::make('test_siem')
                                ->label('Send Test Event')
                                ->icon('heroicon-o-signal')
                                ->color('gray')
                                ->modalHeading('SIEM connectivity test')
                                ->modalDescription('Sends a synthetic SIEM_TEST event to the saved destination and traces where it succeeds or fails. Save your changes first — this tests the stored settings.')
                                ->modalContent(fn () => view('filament.siem-test-result', [
                                    'result' => app(SiemForwarder::class)->test(),
                                ]))
                                ->modalSubmitAction(false)
                                ->modalCancelActionLabel('Close'),
                            Action::make('save_siem')
                                ->label('Save')
                                ->icon('phosphor-floppy-disk-duotone')
                                ->action(fn () => $this->saveSiem()),
                        ])
                        ->schema([
                            Toggle::make('siem.enabled')
                                ->label('Enable SIEM forwarding')
                                ->helperText('When on, every event is forwarded to the destination below (with an extra LOGTYPE="sos-vault" field). Forwarding runs on the queue, so a slow or unreachable SIEM never affects the app.')
                                ->afterStateHydrated(fn (Toggle $component, $state) => $component->state((bool) $state))
                                ->dehydrateStateUsing(fn ($state) => $state ? '1' : '0')
                                ->columnSpanFull(),
                            TextInput::make('siem.host')
                                ->label('SIEM Host (FQDN or IP)')
                                ->placeholder('siem.example.com or 10.0.0.20')
                                ->rules(['nullable', 'string', 'max:191', new HostnameOrIp])
                                ->maxLength(191),
                            TextInput::make('siem.port')
                                ->label('Port')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(65535)
                                ->placeholder('514'),
                            Select::make('siem.protocol')
                                ->label('Transport Protocol')
                                ->live()
                                ->default('tcp')
                                ->options([
                                    'tcp' => 'TCP',
                                    'udp' => 'UDP',
                                    'tls' => 'TLS (encrypted)',
                                ])
                                ->helperText('TLS requires the CA that signed your SIEM server\'s certificate (below).'),
                            Select::make('siem.format')
                                ->label('Wire Format')
                                ->default('ecs')
                                ->options([
                                    'ecs' => 'ECS (JSON)',
                                    'rfc5424' => 'Syslog (RFC 5424)',
                                ]),
                            Placeholder::make('siem.tls_note')
                                ->label('TLS')
                                ->visible(fn (Get $get): bool => $this->siemProtocol($get) === 'tls')
                                ->content('sos-vault connects to your SIEM as a TLS client and verifies the server\'s certificate against the CA below. Upload the CA that signed the SIEM server certificate; for a self-signed SIEM, upload its own certificate as the "SIEM server certificate".')
                                ->columnSpanFull(),
                            FileUpload::make('siem.ca_cert')
                                ->label('CA Certificate (PEM)')
                                ->visible(fn (Get $get): bool => $this->siemProtocol($get) === 'tls')
                                ->acceptedFileTypes(['application/x-pem-file', 'application/octet-stream', 'text/plain'])
                                ->storeFiles(false)
                                ->maxSize(256)
                                ->helperText(fn (): string => hasSiemCert('ca')
                                    ? 'Status: SET — upload a new file to replace it, or leave blank to keep the current CA.'
                                    : 'Status: NOT SET — upload the CA that signed your SIEM server certificate.'),
                            FileUpload::make('siem.server_cert')
                                ->label('SIEM Server Certificate (PEM, optional)')
                                ->visible(fn (Get $get): bool => $this->siemProtocol($get) === 'tls')
                                ->acceptedFileTypes(['application/x-pem-file', 'application/octet-stream', 'text/plain'])
                                ->storeFiles(false)
                                ->maxSize(256)
                                ->helperText(fn (): string => hasSiemCert('server')
                                    ? 'Status: SET — upload a new file to replace it, or leave blank to keep it.'
                                    : 'Status: NOT SET — optional; use for a self-signed or pinned SIEM server.'),
                        ])
                        ->columns(2)
                        ->collapsible()
                        ->collapsed(),

                    Section::make('AI Assistant')
                        ->visible(isSaas() || applianceLicensed())
                        ->description('Local LLM, cloud provider credentials, and behaviour for the Mil chat assistant')
                        ->icon('phosphor-robot-duotone')
                        ->headerActions([
                            Action::make('save_ai')
                                ->label('Save')
                                ->icon('phosphor-floppy-disk-duotone')
                                ->action(fn () => $this->saveGroup('ai', 'AI Assistant')),
                        ])
                        ->schema([
                            Select::make('ai.provider')
                                ->label('AI Provider')
                                ->live()
                                ->options([
                                    'local' => 'Local (llama.cpp)',
                                    'ollama' => 'On-prem Ollama',
                                    'openai' => 'OpenAI',
                                    'anthropic' => 'Anthropic',
                                ])
                                ->helperText('Switching to a cloud provider requires the matching API key below. On-prem Ollama needs its server URL and model name.'),
                            TextInput::make('ai.local_url')
                                ->label('Local LLM URL')
                                ->visible(fn (Get $get): bool => $this->aiProvider($get) === 'local')
                                ->url()
                                ->placeholder(fn (Get $get): string => $this->aiProvider($get) === 'local'
                                    ? 'http://172.21.21.61:8080/v1'
                                    : 'Only used when AI Provider is Local (llama.cpp)')
                                ->helperText('Base URL of the llama.cpp server (OpenAI-compatible endpoint).')
                                ->maxLength(191),
                            TextInput::make('ai.local_model')
                                ->label('Local Model Name')
                                ->visible(fn (Get $get): bool => $this->aiProvider($get) === 'local')
                                ->placeholder(fn (Get $get): string => $this->aiProvider($get) === 'local'
                                    ? 'qwen2.5-1.5b-instruct'
                                    : 'Only used when AI Provider is Local (llama.cpp)')
                                ->maxLength(191),
                            TextInput::make('ai.ollama_url')
                                ->label('Ollama Server URL')
                                ->visible(fn (Get $get): bool => $this->aiProvider($get) === 'ollama')
                                ->url()
                                ->placeholder(fn (Get $get): string => $this->aiProvider($get) === 'ollama'
                                    ? 'http://localhost:11434/v1'
                                    : 'Only used when AI Provider is On-prem Ollama')
                                ->helperText('Base URL of your Ollama server (OpenAI-compatible endpoint — include /v1).')
                                ->maxLength(191),
                            TextInput::make('ai.ollama_model')
                                ->label('Ollama Model Name')
                                ->visible(fn (Get $get): bool => $this->aiProvider($get) === 'ollama')
                                ->placeholder(fn (Get $get): string => $this->aiProvider($get) === 'ollama'
                                    ? 'e.g. llama3.1, deepseek-r1, qwen2.5'
                                    : 'Only used when AI Provider is On-prem Ollama')
                                ->helperText('The model tag as it appears in `ollama list` on your server.')
                                ->maxLength(191),
                            TextInput::make('ai.ollama_api_key')
                                ->label('Ollama API Key (optional)')
                                ->visible(fn (Get $get): bool => $this->aiProvider($get) === 'ollama')
                                ->password()
                                ->revealable()
                                ->placeholder(fn (Get $get): string => $this->aiProvider($get) === 'ollama'
                                    ? 'Leave blank unless your Ollama server enforces a key'
                                    : 'Only used when AI Provider is On-prem Ollama')
                                ->helperText('Most Ollama servers need no key. Set one only if yours is behind an auth proxy.')
                                ->maxLength(191),
                            Toggle::make('ai.ollama_tools')
                                ->label('Ollama Supports Tool-Calling (agentic analysis)')
                                // Only meaningful for on-prem Ollama; hidden for every other
                                // provider. Hidden fields aren't dehydrated, so the stored
                                // value is preserved when saving under a different provider.
                                ->visible(fn (Get $get): bool => $this->aiProvider($get) === 'ollama')
                                ->helperText('On-prem Ollama only. ON: Mil lets the model fetch and correlate case data on demand (better root-cause answers, but multi-step and slower — enable only for a model that tool-calls reliably, e.g. DeepSeek-R1 or Llama 3.1). OFF: Mil pre-selects the relevant data in a single call (safer for smaller models).')
                                ->afterStateHydrated(fn (Toggle $component, $state) => $component->state((bool) $state))
                                ->dehydrateStateUsing(fn ($state) => $state ? '1' : '0'),
                            TextInput::make('ai.openai_model')
                                ->label('OpenAI Model')
                                ->visible(fn (Get $get): bool => $this->aiProvider($get) === 'openai')
                                ->placeholder(fn (Get $get): string => $this->aiProvider($get) === 'openai'
                                    ? 'gpt-4o'
                                    : 'Only used when AI Provider is OpenAI')
                                ->maxLength(191),
                            TextInput::make('ai.openai_api_key')
                                ->label('OpenAI API Key')
                                ->visible(fn (Get $get): bool => $this->aiProvider($get) === 'openai')
                                ->password()
                                ->revealable()
                                ->placeholder(fn (Get $get): string => $this->aiProvider($get) === 'openai'
                                    ? 'sk-… (or falls back to the OPENAI_API_KEY env var)'
                                    : 'Only used when AI Provider is OpenAI')
                                ->helperText('Required when the provider is OpenAI. Falls back to the OPENAI_API_KEY env var if left blank.')
                                ->maxLength(191),
                            TextInput::make('ai.anthropic_model')
                                ->label('Anthropic Model')
                                ->visible(fn (Get $get): bool => $this->aiProvider($get) === 'anthropic')
                                ->placeholder(fn (Get $get): string => $this->aiProvider($get) === 'anthropic'
                                    ? 'claude-3-5-sonnet-20241022'
                                    : 'Only used when AI Provider is Anthropic')
                                ->maxLength(191),
                            TextInput::make('ai.anthropic_api_key')
                                ->label('Anthropic API Key')
                                ->visible(fn (Get $get): bool => $this->aiProvider($get) === 'anthropic')
                                ->password()
                                ->revealable()
                                ->placeholder(fn (Get $get): string => $this->aiProvider($get) === 'anthropic'
                                    ? 'sk-ant-… (or falls back to the ANTHROPIC_API_KEY env var)'
                                    : 'Only used when AI Provider is Anthropic')
                                ->helperText('Required when the provider is Anthropic. Falls back to the ANTHROPIC_API_KEY env var if left blank.')
                                ->maxLength(191),
                            TextInput::make('ai.max_tokens')
                                ->label('Max Response Tokens')
                                ->numeric()
                                ->minValue(128)
                                ->maxValue(8192)
                                ->placeholder(fn (Get $get): string => 'e.g. 512 — applies to the '.$this->aiProviderLabel($this->aiProvider($get)).' model'),
                            TextInput::make('ai.temperature')
                                ->label('Temperature')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(2)
                                ->placeholder(fn (Get $get): string => 'e.g. 0.3 — applies to the '.$this->aiProviderLabel($this->aiProvider($get)).' model')
                                ->helperText('Lower = more deterministic. Recommended: 0.2–0.5 for technical queries.'),
                            TextInput::make('ai.rate_limit')
                                ->label('Rate Limit (queries per user/min)')
                                ->numeric()
                                ->minValue(1)
                                ->placeholder(fn (Get $get): string => 'e.g. 5 — per user/min against '.$this->aiProviderLabel($this->aiProvider($get))),
                            Toggle::make('ai.inject_case_context')
                                ->label('Enable Current-Sosreport Analysis')
                                // Auto-forced OFF for the tiny local model, so hide it there;
                                // it's a real choice only on the capable providers.
                                ->visible(fn (Get $get): bool => $this->aiProvider($get) !== 'local')
                                ->helperText('When a case is open and the question is about it, inject the health digest plus the relevant data files. Automatically OFF for the local model (too small for reliable analysis); sos-vault, sos and Linux help stay enabled.')
                                ->afterStateHydrated(fn (Toggle $component, $state) => $component->state((bool) $state))
                                ->dehydrateStateUsing(fn ($state) => $state ? '1' : '0'),
                            Placeholder::make('ai.profile_summary')
                                ->label('Active provider profile')
                                ->columnSpanFull()
                                ->content(function () {
                                    $provider = setting('ai.provider', 'local');
                                    $profile = ProviderProfile::for($provider);
                                    $analysis = $profile->caseAnalysisEnabled ? 'enabled' : 'disabled (help-only)';

                                    return "Provider \"{$provider}\": current-sosreport analysis {$analysis}; "
                                        ."knowledge budget {$profile->maxKnowledgeChars} chars, "
                                        ."{$profile->perFileCap} chars/data-file, {$profile->historyTurns} history messages. "
                                        .'Tune per-provider profiles in config/ai.php.';
                                }),
                        ])
                        ->columns(2)
                        ->collapsible()
                        ->collapsed(),

                    Section::make('Host & Port')
                        ->visible(isAppliance())
                        ->description('Hostname and HTTPS port this appliance is served on. Saving rewrites APP_URL in .env and the nginx HTTPS port mapping in docker-compose.yml — the application must be restarted for the change to take effect.')
                        ->icon('phosphor-globe-duotone')
                        ->headerActions([
                            Action::make('save_host_port')
                                ->label('Save')
                                ->icon('phosphor-floppy-disk-duotone')
                                ->requiresConfirmation()
                                ->modalHeading('Update host & port')
                                ->modalDescription('This rewrites .env and docker-compose.yml and requires an application restart. Continue?')
                                ->action(fn () => $this->saveHostPort()),
                        ])
                        ->schema([
                            TextInput::make('appliance.host')
                                ->label('Hostname')
                                ->required()
                                ->maxLength(253)
                                ->default(fn () => ApplianceNetworkSettings::osHostname())
                                ->helperText('The hostname clients use to reach this appliance. Defaults to the OS hostname.'),
                            TextInput::make('appliance.port')
                                ->label('HTTPS Port')
                                ->numeric()
                                ->required()
                                ->minValue(1)
                                ->maxValue(65535)
                                ->default(ApplianceNetworkSettings::DEFAULT_PORT)
                                ->helperText('Host port mapped to the nginx HTTPS (443) container port. Default 2002.'),
                        ])
                        ->columns(2)
                        ->collapsible()
                        ->collapsed(),

                    Section::make(__('appliance.nav.disk'))
                        // Vault directory configuration — available on every
                        // appliance install (licensed or not). Relocated here
                        // from the standalone "Main Vault" page.
                        ->visible(isAppliance())
                        ->description(__('licensing.disk_manager.vault_dir_helper'))
                        ->icon('phosphor-hard-drives-duotone')
                        ->headerActions([
                            Action::make('save_vault_dir')
                                ->label(__('licensing.disk_manager.save_button'))
                                ->icon('phosphor-floppy-disk-duotone')
                                ->action(fn () => $this->saveVaultDir()),
                        ])
                        ->schema([
                            TextInput::make('appliance.vault_dir')
                                ->label(__('licensing.disk_manager.vault_dir_label'))
                                ->placeholder('/vault')
                                ->columnSpanFull(),
                        ])
                        ->columns(1)
                        ->collapsible()
                        ->collapsed(),

                    Section::make('Appliance Vaults')
                        ->visible(applianceLicensed())
                        ->description('Defaults used when the admin provisions a new group vault from the Groups panel.')
                        ->icon('phosphor-vault-duotone')
                        ->headerActions([
                            Action::make('save_appliance_vaults')
                                ->label('Save')
                                ->icon('phosphor-floppy-disk-duotone')
                                ->action(fn () => $this->saveGroup('appliance.', 'Appliance Vaults')),
                        ])
                        ->schema([
                            TextInput::make('appliance.default_vault_size_mb')
                                ->label('Default Vault Size (MB)')
                                ->numeric()
                                ->minValue(256)
                                ->default(500)
                                ->helperText('Pre-fills the "Vault Size" field when creating a new group. Each group can still override this at creation time.'),
                            Placeholder::make('appliance.summary')
                                ->label('Appliance Status')
                                ->content(fn () => static::applianceStatusSummary()),
                        ])
                        ->columns(2)
                        ->collapsible()
                        ->collapsed(),

                    Section::make('Licensing Key')
                        ->visible(isSaas())
                        ->description('Passphrase that unlocks the master GPG private key used to decrypt self-hosted customer uploads and sign licenses. Stored encrypted (svault0) in the settings table; never displayed.')
                        ->icon('phosphor-key-duotone')
                        ->headerActions([
                            Action::make('save_licensing')
                                ->label('Save')
                                ->icon('phosphor-floppy-disk-duotone')
                                ->action(fn () => $this->saveLicensing()),
                        ])
                        ->schema([
                            TextInput::make('licensing.master_gpg_passphrase')
                                ->label('Master GPG Passphrase')
                                ->password()
                                ->autocomplete('new-password')
                                ->placeholder(fn (): string => hasMasterGpgPassphrase()
                                    ? 'A licensing key is set — leave blank to keep, type to overwrite'
                                    : 'No licensing key set — enter the master GPG passphrase')
                                ->helperText(fn (): string => hasMasterGpgPassphrase()
                                    ? 'Status: SET. The current value is never displayed. Submitting a new value overwrites it.'
                                    : 'Status: NOT SET. Self-hosted ingestion will fail until a passphrase is provided.')
                                ->maxLength(512)
                                ->columnSpanFull(),
                        ])
                        ->columns(1)
                        ->collapsible()
                        ->collapsed(),
                ]),
            ])
            ->statePath('data');
    }

    /** Current AI provider from the live form state, falling back to the saved setting. */
    private function aiProvider(Get $get): string
    {
        return $get('ai.provider') ?: setting('ai.provider', 'local');
    }

    /** Current SIEM transport protocol from the live form state (form is filled from decrypted values). */
    private function siemProtocol(Get $get): string
    {
        return $get('siem.protocol') ?: 'tcp';
    }

    private function aiProviderLabel(?string $provider): string
    {
        return match ($provider) {
            'openai' => 'OpenAI',
            'anthropic' => 'Anthropic',
            'ollama' => 'On-prem Ollama',
            default => 'Local (llama.cpp)',
        };
    }

    public function saveGroup(string $prefix, string $label): void
    {
        $data = $this->form->getState();
        $flat = Arr::dot($data);
        $dotPrefix = rtrim($prefix, '.').'.';

        foreach ($flat as $key => $value) {
            if (str_starts_with($key, $dotPrefix)) {
                if (in_array($key, ENCRYPTED_SETTING_KEYS, true) && $value !== '' && $value !== null) {
                    $cipher = encryptSetting((string) $value);

                    if ($cipher === null) {
                        Notification::make()
                            ->danger()
                            ->title("Failed to save {$label} settings")
                            ->body('The svault0 keyring key is not available, so the secret value could not be encrypted. Check the server keyring configuration.')
                            ->send();

                        return;
                    }

                    $value = $cipher;
                }

                Setting::updateOrCreate(
                    ['key' => $key],
                    ['display_name' => $key, 'value' => $value, 'type' => 'text', 'order' => 0]
                );
            }
        }

        if ($prefix === 'auth') {
            Cache::forget('wave_settings');

            // Keep the legacy 'auth.min_password_length' in sync with the
            // selected complexity profile so any code still reading it
            // (config('wave.auth.min_password_length') fallbacks, third-party
            // libs) sees a value consistent with the new policy.
            $minLength = PasswordPolicy::minLength();
            Setting::updateOrCreate(
                ['key' => 'auth.min_password_length'],
                ['display_name' => 'auth.min_password_length', 'value' => (string) $minLength, 'type' => 'text', 'order' => 0]
            );
        }

        Notification::make()
            ->success()
            ->title("{$label} settings saved")
            ->send();
    }

    /**
     * Persist the appliance hostname + HTTPS port. Stores them in the settings
     * table and mirrors them into .env (APP_URL) and docker-compose.yml (nginx
     * :443 host-port mapping), then asks the operator to restart the stack.
     */
    public function saveHostPort(): void
    {
        // Read raw form state (not getState()) so this section's own
        // validation governs the save rather than the whole page's.
        $host = trim((string) Arr::get($this->data, 'appliance.host', ''));
        $port = (int) Arr::get($this->data, 'appliance.port', ApplianceNetworkSettings::DEFAULT_PORT);

        if ($host === '') {
            Notification::make()->danger()->title('Hostname is required')->send();

            return;
        }
        if (! ApplianceNetworkSettings::isValidHost($host)) {
            Notification::make()->danger()
                ->title('Invalid hostname')
                ->body('The hostname may contain only letters, digits, dots and hyphens.')
                ->send();

            return;
        }
        if ($port < 1 || $port > 65535) {
            Notification::make()->danger()->title('Port must be between 1 and 65535')->send();

            return;
        }

        $updated = app(ApplianceNetworkSettings::class)->apply($host, $port);

        $body = sprintf('APP_URL is now https://%s:%d.', $host, $port);
        if ($updated === []) {
            $body .= ' Settings were stored, but no infrastructure files were writable on this host.';
        } else {
            $body .= ' Restart the application (docker compose down && docker compose up -d) for the change to take effect.';
        }

        Notification::make()
            ->warning()
            ->title('Host & Port saved — restart required')
            ->body($body)
            ->persistent()
            ->send();
    }

    /**
     * Persist the master GPG (licensing) passphrase. Empty submissions are
     * treated as "no change" so the existing value is preserved. Non-empty
     * submissions are encrypted with the svault0 keyring key before storage
     * and the form field is cleared so the value never round-trips back to
     * the browser.
     */
    public function saveLicensing(): void
    {
        $data = $this->form->getState();
        $plain = trim((string) Arr::get($data, 'licensing.master_gpg_passphrase', ''));

        if ($plain === '') {
            Notification::make()
                ->info()
                ->title('No change to Licensing Key')
                ->body('Leave the field blank to keep the current value, or type a new value to overwrite it.')
                ->send();

            return;
        }

        $cipher = encryptLicensingPassphrase($plain);

        if ($cipher === null) {
            Notification::make()
                ->danger()
                ->title('Failed to save Licensing Key')
                ->body('The svault0 keyring key is not available. Check the server keyring configuration.')
                ->send();

            return;
        }

        Setting::updateOrCreate(
            ['key' => LICENSING_PASSPHRASE_SETTING_KEY],
            ['display_name' => LICENSING_PASSPHRASE_SETTING_KEY, 'value' => $cipher, 'type' => 'text', 'order' => 0]
        );

        // Scrub the plaintext from the form state so it is not echoed back.
        $this->data['licensing']['master_gpg_passphrase'] = '';

        Notification::make()
            ->success()
            ->title('Licensing Key saved')
            ->body('The passphrase has been encrypted and stored.')
            ->send();
    }

    /**
     * Persist the SIEM Integration settings. Every siem.* value is encrypted at
     * rest with the svault0 key. The two certificates are write-only: a blank
     * upload keeps the existing value (mirroring the Licensing Key handler).
     * Emits ADD_SIEM / CHG_SIEM / DEL_SIEM depending on the resulting state.
     */
    public function saveSiem(): void
    {
        $data = $this->form->getState();

        // Presence of a prior host distinguishes a first-time config (ADD) from
        // an update (CHG). Read before writing.
        $hadHost = (bool) Setting::get('siem.host');

        $scalars = [
            'siem.enabled' => Arr::get($data, 'siem.enabled') ? '1' : '0',
            'siem.host' => trim((string) Arr::get($data, 'siem.host', '')),
            'siem.port' => trim((string) Arr::get($data, 'siem.port', '')),
            'siem.protocol' => (string) (Arr::get($data, 'siem.protocol') ?: 'tcp'),
            'siem.format' => (string) (Arr::get($data, 'siem.format') ?: 'ecs'),
        ];

        foreach ($scalars as $key => $value) {
            if ($value === '') {
                // Store an empty value so a cleared host/port is represented.
                $this->upsertSiemSetting($key, '');

                continue;
            }

            $cipher = encryptSiemSetting($value);
            if ($cipher === null) {
                $this->siemKeyringError();

                return;
            }
            $this->upsertSiemSetting($key, $cipher);
        }

        // Certificates: only overwrite when a new file was uploaded.
        foreach (['siem.ca_cert', 'siem.server_cert'] as $certKey) {
            $pem = $this->readUploadedPem(Arr::get($data, $certKey));
            if ($pem === null) {
                continue; // blank upload — keep the existing value
            }

            if (@openssl_x509_read($pem) === false) {
                Notification::make()
                    ->danger()
                    ->title('Invalid certificate')
                    ->body('The uploaded '.($certKey === 'siem.ca_cert' ? 'CA certificate' : 'SIEM server certificate').' is not a valid PEM certificate.')
                    ->send();

                return;
            }

            $cipher = encryptSiemSetting($pem);
            if ($cipher === null) {
                $this->siemKeyringError();

                return;
            }
            $this->upsertSiemSetting($certKey, $cipher);

            // Scrub the upload from form state so it is not re-processed.
            Arr::set($this->data, $certKey, null);
        }

        Cache::forget('wave_settings');

        // Emit the audit event (itself forwarded to the SIEM if now enabled).
        $enabledNow = $scalars['siem.enabled'] === '1';
        $hostNow = $scalars['siem.host'] !== '';
        $eventType = (! $enabledNow || ! $hostNow) ? 'DEL_SIEM' : ($hadHost ? 'CHG_SIEM' : 'ADD_SIEM');
        $uid = auth()->id() ?? 0;
        addEvent(
            ['host' => $scalars['siem.host'], 'protocol' => $scalars['siem.protocol'], 'format' => $scalars['siem.format'], 'enabled' => $enabledNow],
            $eventType,
            'SUCCESS',
            'ACTIVITY',
            0,
            0,
            $uid,
            $uid,
        );

        Notification::make()
            ->success()
            ->title('SIEM Integration settings saved')
            ->send();
    }

    private function upsertSiemSetting(string $key, string $value): void
    {
        Setting::updateOrCreate(
            ['key' => $key],
            ['display_name' => $key, 'value' => $value, 'type' => 'text', 'order' => 0]
        );
    }

    private function siemKeyringError(): void
    {
        Notification::make()
            ->danger()
            ->title('Failed to save SIEM settings')
            ->body('The svault0 keyring key is not available, so the values could not be encrypted. Check the server keyring configuration.')
            ->send();
    }

    /**
     * Read an uploaded PEM from a FileUpload state value (storeFiles(false)).
     * Returns null when nothing new was uploaded, so callers keep the existing
     * stored value.
     */
    private function readUploadedPem(mixed $upload): ?string
    {
        $file = is_array($upload) ? ($upload[array_key_first($upload)] ?? null) : $upload;

        if (! $file instanceof TemporaryUploadedFile) {
            return null;
        }

        $contents = file_get_contents($file->getRealPath());
        if ($contents === false || trim($contents) === '') {
            return null;
        }

        return $contents;
    }

    /**
     * Persist the appliance vault directory. Ported from the former
     * DiskManager page: the path must be absolute (provisioned by the
     * sos-vault:ensure-plain-vault command).
     */
    public function saveVaultDir(): void
    {
        // Read the raw component state rather than $this->form->getState():
        // getState() validates the whole form, which would trip on unrelated
        // required fields in sibling appliance sections (e.g. Host & Port).
        $path = trim((string) Arr::get($this->data, 'appliance.vault_dir', ''));

        if ($path === '' || $path[0] !== '/') {
            Notification::make()
                ->danger()
                ->title(__('licensing.disk_manager.save_button'))
                ->body(__('licensing.disk_manager.vault_dir_helper'))
                ->send();

            return;
        }

        Setting::updateOrCreate(
            ['key' => 'appliance.vault_dir'],
            ['display_name' => 'Appliance Vault Directory', 'value' => $path, 'type' => 'text', 'order' => 0]
        );

        Notification::make()
            ->success()
            ->title(__('licensing.disk_manager.save_notif'))
            ->send();
    }

    /**
     * The currently configured vault directory (settings override, else the
     * config default of /vault).
     */
    public function currentVaultDir(): string
    {
        return (string) Setting::get('appliance.vault_dir', config('appliance.vault_dir', '/vault'));
    }

    /**
     * Roles offered for "Default Role Assigned at Registration". On the
     * appliance only "Team Member" applies — every other role is a SaaS
     * plan/billing tier with no meaning on a self-hosted box.
     *
     * @return array<string, string>
     */
    public static function defaultRoleOptions(): array
    {
        if (isAppliance()) {
            return Role::where('name', 'Team Member')->pluck('name', 'name')->all();
        }

        return Role::orderBy('name')->pluck('name', 'name')->all();
    }

    /**
     * One-line status for the "Appliance Vaults" settings section. Seats and
     * Users are presented in user-facing terms: one seat is always reserved
     * for the admin operator (mirrors ApplianceLicenseWidget), so a 10-user
     * license (raw seats=11) with only the admin present reads
     * "Seats: 10 • Admin: 1 • Users: 0 • Groups: 1".
     */
    public static function applianceStatusSummary(): string
    {
        $admins = User::whereHas('roles', fn ($query) => $query->where('name', 'admin'))->count();

        return sprintf(
            'Seats: %d • Admin: %d • Users: %d • Groups: %d',
            max(0, (int) (LocalLicense::current()?->seats ?? 0) - 1),
            $admins,
            max(0, User::count() - $admins),
            Group::count()
        );
    }

    public function sendTestEmail(string $to, string $subject, string $body): void
    {
        try {
            SendUserEmail::dispatch([
                'type' => 'response',
                'to' => $to,
                'from' => 'support@sos-vault.com',
                'subject' => $subject,
                'title' => $subject,
                'body' => $body,
            ]);

            Notification::make()
                ->success()
                ->title('Test email queued')
                ->body("Email to {$to} has been queued for delivery.")
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Failed to queue test email')
                ->body($e->getMessage())
                ->send();
        }
    }
}
