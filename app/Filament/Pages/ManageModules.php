<?php

namespace App\Filament\Pages;

use App\Jobs\DownloadAiModelJob;
use App\Models\Module;
use App\Services\ModelProvisionService;
use App\Services\PackageManager;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;

/**
 * @property-read Schema $form
 */
class ManageModules extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'phosphor-package-duotone';

    protected static ?string $navigationLabel = 'Software Updates';

    protected static ?string $title = 'Software Updates';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.manage-modules';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /**
     * The page is always reachable: the "AI Assistant Model" download (rendered
     * in the blade) must work on UNLICENSED installs so the local bot can be
     * enabled before a license is applied. Module install/update — the parts
     * that require an active license — are hidden in the blade unless licensed.
     */
    public static function canAccess(): bool
    {
        return true;
    }

    /** Whether module install/update (the licensed sections) is available. */
    public function moduleManagementAvailable(): bool
    {
        return isSaas() || applianceLicensed();
    }

    /** Whether the local AI model weights are already on disk. */
    #[Computed]
    public function aiModelInstalled(): bool
    {
        return app(ModelProvisionService::class)->isInstalled();
    }

    /**
     * Live download state the blade polls for the progress bar. Null when no
     * download has run. See DownloadAiModelJob::STATE_CACHE_KEY for the shape.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function aiModelDownloadState(): ?array
    {
        return DownloadAiModelJob::currentState();
    }

    /** True while a download is actively running (drives the poll + bar). */
    #[Computed]
    public function aiModelDownloading(): bool
    {
        return ($this->aiModelDownloadState()['status'] ?? null) === 'downloading'
            && ! $this->aiModelInstalled();
    }

    /**
     * Kick off the background download. Seeds the cached state to "downloading"
     * immediately so the polled progress bar appears without waiting for the
     * queue worker to pick the job up.
     */
    public function startAiModelDownload(): void
    {
        if ($this->aiModelInstalled() || $this->aiModelDownloading()) {
            return;
        }

        DownloadAiModelJob::putState(['status' => 'downloading', 'percent' => 0, 'downloaded' => 0, 'total' => 0]);

        // Pass the triggering admin so the queued job can address its progress /
        // completion notifications back to this operator.
        DownloadAiModelJob::dispatch(auth()->id());

        unset($this->aiModelDownloadState, $this->aiModelDownloading);

        Notification::make()
            ->success()
            ->title('AI model download started')
            ->body('This runs in the background and may take several minutes. The progress bar below updates as it downloads.')
            ->send();
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Install / Update Package')
                        ->description('Upload a .tar.gz or .tar.gz.gpg (signed) package file to install or update a module or patch.')
                        ->icon('phosphor-upload-simple-duotone')
                        ->schema([
                            FileUpload::make('archive')
                                ->label('Package Archive (.tar.gz or .tar.gz.gpg)')
                                ->disk('local')
                                ->directory('private/module-uploads')
                                ->visibility('private')
                                ->preserveFilenames(),
                        ]),
                ])
                    ->livewireSubmitHandler('installPackage')
                    ->footer([
                        Actions::make([
                            Action::make('installPackage')
                                ->label('Install / Update')
                                ->submit('installPackage')
                                ->icon('phosphor-download-simple-duotone'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function installPackage(): void
    {
        $data = $this->form->getState();
        $relativePath = $data['archive'] ?? null;

        if (! $relativePath) {
            Notification::make()
                ->danger()
                ->title('No file uploaded')
                ->send();

            return;
        }

        $absolutePath = Storage::disk('local')->path($relativePath);

        try {
            $module = app(PackageManager::class)->install($absolutePath);

            Notification::make()
                ->success()
                ->title("Package installed: {$module->name} v{$module->version}")
                ->send();

            $this->form->fill();
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Installation failed')
                ->body($e->getMessage())
                ->persistent()
                ->send();
        } finally {
            if (Storage::disk('local')->exists($relativePath)) {
                Storage::disk('local')->delete($relativePath);
            }
        }
    }

    public function toggleEnabled(int $moduleId): void
    {
        $module = Module::findOrFail($moduleId);

        if ($module->package_type !== 'module') {
            return;
        }

        $module->update(['is_enabled' => ! $module->is_enabled]);

        $status = $module->is_enabled ? 'enabled' : 'disabled';

        Notification::make()
            ->success()
            ->title("Module {$status}: {$module->name}")
            ->send();
    }

    public function removeModule(int $moduleId): void
    {
        $module = Module::findOrFail($moduleId);

        try {
            app(PackageManager::class)->remove($module);

            Notification::make()
                ->success()
                ->title("Removed: {$module->name}")
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Removal failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /** @return Collection<int, Module> */
    public function getInstalledModulesProperty(): Collection
    {
        return Module::query()->orderBy('name')->get();
    }
}
