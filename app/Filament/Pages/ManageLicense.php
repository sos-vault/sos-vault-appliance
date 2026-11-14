<?php

namespace App\Filament\Pages;

use App\Models\LocalLicense;
use App\Models\User;
use App\Services\LicenseRequestService;
use App\Services\LocalLicenseService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

/**
 * @property-read Schema $form
 */
class ManageLicense extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'phosphor-key-duotone';

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.pages.manage-license';

    public static function getNavigationLabel(): string
    {
        return __('appliance.nav.license');
    }

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /**
     * The license-request key generated this request, rendered as a copyable
     * field in the Blade view. Null until "Generate License Request" runs.
     */
    public ?string $licenseKey = null;

    public static function canAccess(): bool
    {
        return isAppliance();
    }

    public function mount(): void
    {
        $this->form->fill([]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make(__('licensing.request.section_heading'))
                        ->description(__('licensing.request.section_description'))
                        ->icon('phosphor-paper-plane-tilt-duotone')
                        ->headerActions([
                            Action::make('generate_request')
                                ->label(__('licensing.request.button_generate'))
                                ->icon('phosphor-export-duotone')
                                ->action(fn () => $this->requestLicense()),
                        ])
                        ->schema([]),

                    // The generated key sits between "Request a License" and
                    // "Install License": you generate it here, then paste it at
                    // sos-vault.com. Visible only after Generate runs.
                    Section::make(__('licensing.request.key_heading'))
                        ->description(__('licensing.request.key_helper'))
                        ->icon('phosphor-key-duotone')
                        ->visible(fn (): bool => filled($this->licenseKey))
                        ->schema([
                            View::make('filament.pages.partials.license-key'),
                        ]),

                    Section::make(__('appliance.manage_license.install_section_heading'))
                        ->description(__('appliance.manage_license.install_section_description'))
                        ->headerActions([
                            Action::make('install_license')
                                ->label(__('appliance.manage_license.install_button'))
                                ->icon('phosphor-upload-duotone')
                                ->action(fn () => $this->installLicense()),
                        ])
                        ->schema([
                            FileUpload::make('lic_file')
                                ->label(__('appliance.manage_license.file_label'))
                                // No acceptedFileTypes: the .lic extension is
                                // unregistered, so browsers report an empty MIME
                                // for it and FilePond rejects it as "invalid
                                // type". The signed contents are validated
                                // server-side in installLicense().
                                ->storeFiles(false)
                                ->maxSize(256)
                                ->columnSpanFull(),
                        ]),
                ]),
            ])
            ->statePath('data');
    }

    public function requestLicense(): void
    {
        try {
            $this->licenseKey = app(LicenseRequestService::class)->generate();
        } catch (RuntimeException $e) {
            $this->licenseKey = null;

            Notification::make()
                ->danger()
                ->title(__('licensing.request.notif_failed'))
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('licensing.request.notif_key_ready'))
            ->body(__('licensing.request.notif_key_ready_body'))
            ->send();
    }

    public function installLicense(): void
    {
        $upload = $this->data['lic_file'] ?? null;

        // FileUpload with storeFiles(false) returns either a single
        // TemporaryUploadedFile or an array of them; normalise both shapes.
        $file = is_array($upload) ? ($upload[array_key_first($upload)] ?? null) : $upload;

        if (! $file instanceof TemporaryUploadedFile) {
            Notification::make()->danger()->title(__('appliance.manage_license.notif_no_file'))->send();

            return;
        }

        $contents = file_get_contents($file->getRealPath());

        // The upload lands on the `vault` Livewire temp disk (/vault/wkng). Once
        // its bytes are in memory we no longer need it on disk — delete it now so
        // a signed .lic never lingers in the vault temp dir (it would otherwise
        // sit there until Livewire's temp sweep, if ever, runs).
        $file->delete();

        if ($contents === false || trim($contents) === '') {
            Notification::make()->danger()->title(__('appliance.manage_license.notif_empty'))->send();

            return;
        }

        try {
            $license = app(LocalLicenseService::class)->install($contents, auth()->id());
        } catch (RuntimeException $e) {
            Notification::make()
                ->danger()
                ->title(__('appliance.manage_license.notif_install_failed'))
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('appliance.manage_license.notif_installed_title'))
            ->body(__('appliance.manage_license.notif_installed_body', [
                'date' => $license->expires_at->toDateString(),
                'seats' => $license->seats,
            ]))
            ->send();

        $this->form->fill([]);
    }

    /**
     * Data passed to the Blade view for the read-only "Installed License" card.
     *
     * @return array<string, mixed>
     */
    public function getInstalledLicenseData(): array
    {
        $license = LocalLicense::current();

        if (! $license) {
            return ['installed' => false];
        }

        $usedSeats = User::query()->count();

        // Reserve one seat for the always-included admin (mirrors the
        // dashboard widget): present seats in user-facing terms so a 10-user
        // license with only the admin present reads "0 / 10", not "1 / 11".
        $reservedAdminSeats = 1;
        $displayUsed = max(0, $usedSeats - $reservedAdminSeats);
        $displayTotal = max(0, $license->seats - $reservedAdminSeats);

        return [
            'installed' => true,
            'uuid' => $license->uuid,
            'customer_id' => $license->customer_id,
            'status' => $license->status,
            'seats' => $displayTotal,
            'seats_used' => $displayUsed,
            'seats_remaining' => max(0, $displayTotal - $displayUsed),
            'features' => $license->features ?? [],
            'issued_at' => $license->issued_at,
            'expires_at' => $license->expires_at,
            'is_expiring_soon' => $license->expires_at->diffInDays(now()) <= 30,
        ];
    }
}
