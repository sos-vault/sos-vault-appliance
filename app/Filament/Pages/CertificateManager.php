<?php

namespace App\Filament\Pages;

use App\Services\CertificateManagerService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

/**
 * Sprint 5 Step D — appliance-only TLS certificate management.
 *
 * Operators use this page to replace the nginx fullchain.pem / privkey.pem
 * (mounted into the nginx container at /etc/nginx/ssl/sos-vault.com/) and to
 * drop a corporate root CA into the system trust store. Both actions go
 * through sysadmin/cert-helper via CertificateManagerService — PHP never
 * touches privileged paths directly.
 *
 * @property-read Schema $form
 */
class CertificateManager extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'phosphor-certificate-duotone';

    protected static ?int $navigationSort = 13;

    protected string $view = 'filament.pages.certificate-manager';

    public static function getNavigationLabel(): string
    {
        return __('appliance.nav.certificates');
    }

    /** @var array<string, mixed>|null */
    public ?array $data = [];

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
                    Section::make(__('appliance.certificate.server_section_heading'))
                        ->description(__('appliance.certificate.server_section_description'))
                        ->headerActions([
                            Action::make('install_cert')
                                ->label(__('appliance.certificate.server_install_button'))
                                ->icon('phosphor-upload-duotone')
                                ->action(fn () => $this->installCertificate()),
                            Action::make('regenerate_self_signed')
                                ->label(__('appliance.certificate.server_self_signed_button'))
                                ->icon('phosphor-arrow-counter-clockwise-duotone')
                                ->color('gray')
                                ->requiresConfirmation()
                                ->modalHeading(__('appliance.certificate.server_self_signed_confirm_heading'))
                                ->modalDescription(__('appliance.certificate.server_self_signed_confirm_body'))
                                ->action(fn () => $this->regenerateSelfSigned()),
                        ])
                        ->schema([
                            FileUpload::make('fullchain')
                                ->label(__('appliance.certificate.server_field_fullchain'))
                                ->acceptedFileTypes(['application/x-pem-file', 'application/octet-stream', 'text/plain'])
                                ->storeFiles(false)
                                ->maxSize(256)
                                ->columnSpanFull(),
                            FileUpload::make('privkey')
                                ->label(__('appliance.certificate.server_field_privkey'))
                                ->acceptedFileTypes(['application/x-pem-file', 'application/octet-stream', 'text/plain'])
                                ->storeFiles(false)
                                ->maxSize(64)
                                ->columnSpanFull(),
                        ]),

                    Section::make(__('appliance.certificate.ca_section_heading'))
                        ->description(__('appliance.certificate.ca_section_description'))
                        ->headerActions([
                            Action::make('install_corp_ca')
                                ->label(__('appliance.certificate.ca_install_button'))
                                ->icon('phosphor-shield-check-duotone')
                                ->action(fn () => $this->installCorpCa()),
                        ])
                        ->schema([
                            FileUpload::make('corp_ca')
                                ->label(__('appliance.certificate.ca_field_label'))
                                ->acceptedFileTypes(['application/x-pem-file', 'application/x-x509-ca-cert', 'application/octet-stream', 'text/plain'])
                                ->storeFiles(false)
                                ->maxSize(64)
                                ->columnSpanFull(),
                        ]),
                ]),
            ])
            ->statePath('data');
    }

    public function installCertificate(): void
    {
        $fullchain = $this->readUpload('fullchain');
        $privkey = $this->readUpload('privkey');

        if ($fullchain === null || $privkey === null) {
            Notification::make()
                ->danger()
                ->title(__('appliance.certificate.notif_both_required'))
                ->send();

            return;
        }

        try {
            // Write the new cert files. nginx loads certs at startup and does
            // not pick up a replacement live (the app container has no way to
            // reload the separate nginx container), so the operator must restart
            // the appliance to apply it — see the notification below.
            app(CertificateManagerService::class)->install($fullchain, $privkey);
        } catch (RuntimeException $e) {
            Notification::make()
                ->danger()
                ->title(__('appliance.certificate.notif_install_failed'))
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        // Persistent so the operator can't miss the required restart step.
        Notification::make()
            ->success()
            ->title(__('appliance.certificate.notif_installed_title'))
            ->body(__('appliance.certificate.notif_installed_body'))
            ->persistent()
            ->send();

        $this->form->fill([]);
    }

    public function regenerateSelfSigned(): void
    {
        try {
            app(CertificateManagerService::class)->generateSelfSigned();
        } catch (RuntimeException $e) {
            Notification::make()
                ->danger()
                ->title(__('appliance.certificate.notif_self_signed_failed'))
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('appliance.certificate.notif_self_signed_title'))
            ->body(__('appliance.certificate.notif_self_signed_body'))
            ->persistent()
            ->send();
    }

    public function installCorpCa(): void
    {
        $ca = $this->readUpload('corp_ca');
        if ($ca === null) {
            Notification::make()->danger()->title(__('appliance.certificate.notif_no_ca'))->send();

            return;
        }

        try {
            app(CertificateManagerService::class)->installCorpCa($ca);
        } catch (RuntimeException $e) {
            Notification::make()
                ->danger()
                ->title(__('appliance.certificate.notif_ca_failed'))
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        // Persistent so the operator can't miss the required restart step
        // (container_start.sh re-runs update-ca-certificates on boot).
        Notification::make()
            ->success()
            ->title(__('appliance.certificate.notif_ca_installed_title'))
            ->body(__('appliance.certificate.notif_ca_installed_body'))
            ->persistent()
            ->send();

        $this->data['corp_ca'] = null;
    }

    /**
     * Data passed to the Blade view for the read-only "Current Certificate"
     * card. Returns ['available' => false, 'error' => ...] when openssl
     * fails or no cert is installed yet.
     *
     * @return array<string, mixed>
     */
    public function getCurrentCertificateData(): array
    {
        try {
            $info = app(CertificateManagerService::class)->inspect();
        } catch (RuntimeException $e) {
            return ['available' => false, 'error' => $e->getMessage()];
        }

        return [
            'available' => true,
            'subject' => $info['subject'],
            'issuer' => $info['issuer'],
            'expires_at' => $info['expires_at'],
            'is_expiring_soon' => $info['expires_at']
                ? $info['expires_at']->diffInDays(now(), false) >= -30
                : false,
        ];
    }

    private function readUpload(string $key): ?string
    {
        $upload = $this->data[$key] ?? null;
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
}
