<?php
use App\Exceptions\InvalidSosreportFilenameException;
use App\Models\ITSMProvider;
use App\Models\Vault;
use App\Providers\VaultTools;
use App\Services\JiraService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;

middleware('auth');

new class extends Component implements HasForms
{
    use InteractsWithForms;

    public ?array $data = ['sosreport' => null];

    public $vid;

    public $withProgressBar = true;

    // ITSM download state
    public ?array $itsmProvider = null;

    public int $itsmSection = 1;

    public array $attachments = [];

    public ?array $caseInfo = null;

    public string $fetchError = '';

    public string $itsmCaseNumber = '';

    public ?string $itsmCustomer = null;

    public ?string $itsmLink = null;

    public ?string $itsmDescription = null;

    private $vtools;

    private $DEBUG = true;

    private $vaultsDisabled;

    public function mount($vid, $withProgressBar = null): void
    {
        $this->vaultsDisabled = (config('app.vaultsDisabled') == 'TRUE');
        $this->vid = $vid;
        isset($withProgressBar) && $this->withProgressBar = ($withProgressBar == 'true');

        $provider = ITSMProvider::where('uid', auth()->user()->id)->first();
        $this->itsmProvider = $provider ? $provider->only(['id', 'provider', 'url']) : null;
    }

    public function vtools(): ?VaultTools
    {
        if (isset($this->vtools)) {
            return $this->vtools;
        }

        if (! isset($this->vid)) {
            $message = 'No vault provided. Cannot continue.';
            notifyError($message);

            return null;
        }

        $this->vtools = new VaultTools(auth()->user(), $this->vid);

        if (! isset($this->vtools)) {
            $message = "Couldn't access your vault. Cannot continue.";
            notifyError($message);

            return null;
        }

        if ($this->vtools->getVaultId() != $this->vid) {
            $message = 'Wrong vault provided. Cannot continue.';
            notifyError($message);

            return null;
        }

        if (! $this->vtools->isOpen()) {
            $message = 'Your vault is closed. Cannot continue.';
            notifyError($message);

            return null;
        }

        return $this->vtools;
    }

    #[On('refreshComponents')]
    public function refreshDirectories() {}

    /**
     * Regex an upload's original filename must match to be accepted as a valid
     * sos report. The name must start with "sosreport-" (optionally prefixed by
     * "secured-") and end in a recognised archive/encryption extension. Used
     * both as the FileUpload client-side hint and as the authoritative
     * server-side gate in save(), since this form is never form-validated.
     */
    private function uploadFilenamePattern(): string
    {
        $fileExtensions = [
            '.tar.xz.gpg',
            '.tar.gz.gpg',
            '.tar.xz',
            '.tar.gz',
            '.tgz',
            '.tar',
            '.gpg',
            '.gz',
            '.xz',
        ];

        $regexExten = implode('|', $fileExtensions);

        // The middle segment (host / case / date / id, plus the app's own
        // "-obfuscated" marker) is restricted to letters, digits, dots, dashes
        // and underscores. This deliberately EXCLUDES spaces, slashes and shell
        // metacharacters ($ ; | ` & ( ) etc.) — the accepted name is later stored
        // on disk and interpolated into the gpg/tar commands that unpack it, so a
        // permissive middle (the old `..*`) allowed command injection / path
        // traversal via a crafted upload filename.
        return sprintf('/^(secured-)?sosreport-[A-Za-z0-9._-]+(%s)$/', str_replace('.', '\.', $regexExten));
    }

    public function errorState($message)
    {
        $this->addError('data.sosreport', "⚠️  {$message}");
        $this->dispatch('errorState');
    }

    public function save($file)
    {
        $user = auth()->user();

        if (! isset($file) || empty($file)) {
            $message = __('vault.upload_no_file');
            notifyError($message);
            $this->errorState($message);

            return null;
        }

        $fileName = $file->getClientOriginalName();

        // Authoritative filename gate — reject anything that is not a valid sos
        // report before it ever touches the vault. The FileUpload ->regex() never
        // runs because this form is processed in afterStateUpdated and is never
        // form-validated, so this is the only enforcement of the naming rule.
        if (! preg_match($this->uploadFilenamePattern(), $fileName)) {
            $message = __('vault.upload_invalid_name');
            notifyError($message);
            $this->errorState($message);

            return null;
        }

        $directory = $this->vtools()->getMountPoint();

        $tempFile = "{$directory}/../".$file->getClientOriginalPath();

        $size = intval($file->getSize());

        if (! isset($size) || $size == 0) {
            $message = __('vault.upload_size_error');
            notifyError($message);
            $this->errorState($message);
            is_file($tempFile) && unlink($tempFile);

            return null;
        }

        $this->DEBUG && Log::info('directory: '.var_export($directory, 1));
        $this->DEBUG && Log::info('fileName: '.var_export($fileName, 1));
        $this->DEBUG && Log::info('tempFile: '.var_export($tempFile, 1));
        $this->DEBUG && Log::info('size: '.var_export($size, 1));

        // is there is enough space in user vault
        if (! $this->vtools()->doesItFit($size)) {
            $message = __('vault.upload_no_space');
            notifyError($message);
            $this->errorState($message);
            is_file($tempFile) && unlink($tempFile);

            return null;
        }

        // does a packed file with exactly the same name alredy exists in the vault
        $newfile = $this->vtools()->getMountPoint().'/'.$fileName;

        $this->DEBUG && Log::info('newfile: '.var_export($newfile, 1));

        if (is_file($newfile)) {
            $message = __('vault.upload_already_exists');
            notifyError($message);
            $this->errorState($message);
            is_file($tempFile) && unlink($tempFile);

            return null;
        }

        // does an unpacked dir with corresponding name alredy exists in the vault
        try {
            $fdata = $this->vtools()->parseFilename($fileName);
        } catch (InvalidSosreportFilenameException $e) {
            $message = __('vault.upload_unparseable_name');
            notifyError($message);
            notifyUser($user, $message, 'error');
            $this->errorState($message);
            is_file($tempFile) && unlink($tempFile);

            return null;
        }

        $this->DEBUG && Log::info('fdata: '.var_export($fdata, 1));

        $newpath = $this->vtools()->getMountPoint().'/'.$fdata->path;

        $this->DEBUG && Log::info('newpath: '.var_export($newpath, 1));

        if (is_dir($newpath)) {
            $message = __('vault.upload_dir_exists');
            notifyError($message);
            $this->errorState($message);
            is_file($tempFile) && unlink($tempFile);

            return null;
        }

        if (! rename($tempFile, $newfile)) {
            $message = __('vault.upload_move_error');
            notifyError($message);
            $this->errorState($message);
            is_file($tempFile) && unlink($tempFile);

            return null;
        }

        // generate a new contents.json file
        $this->vtools()->updateContents();

        // Verify that the file is in the contents.json file
        $files = $this->vtools()->getFiles();
        if (! $files) {
            $message = __('vault.upload_no_files_in_vault');
            notifyError($message);
            $this->errorState($message);

            return null;
        }

        foreach ($files as $fileobj) {
            if ($fileobj->name == $fileName) {
                $file = $fileobj;
                break;
            }
        }

        if (! $file) {
            $message = __('vault.upload_not_found_after');
            notifyError($message);
            $this->errorState($message);

            return null;
        }
        $this->DEBUG && Log::info('file found: '.var_export($file, 1));

        $message = __('vault.upload_success');

        $cid = 0;
        $uid = $user->id;
        $gid = $user->id;

        $payload = (object) [
            'message' => $message,
            'name' => $fileName,
            'via' => 'api',
        ];
        addEvent($payload, 'UPLOAD', 'SUCCESS', 'ACTIVITY', $cid, $this->vid, $uid, $gid);

        $this->DEBUG && Log::info($message);
        Notification::make()
            ->title($message)
            ->icon('phosphor-bell-ringing-duotone')
            ->iconColor('success')
            ->send();

        // only return the file if further automatic unpacking is possible
        $key = '';
        foreach ($user->apiKeys as $apiKey) {
            if ($apiKey->name == 'decrypt-pass') {
                if (! $this->vaultsDisabled) {
                    $encrypter = new Encrypter(
                        key: getSvaultKey('svault0'),
                        cipher: config('app.cipher'),
                    );
                    $key = $encrypter->decrypt($apiKey->key);
                } else {
                    $key = $apiKey->key;
                }
                break;
            }
        }

        if (! $fdata->gpg || (! empty($key) && $fdata->gpg)) {
            // if the file is not encrypted or
            // if the file is encrypted and there is a decrypt-pass
            return $file;
        }

        return null;
    }

    #[On('unpackFile')]
    public function unpack($fid, $key): void
    {
        // Phase2: decrypt and extract

        if (! isset($fid) || empty($fid)) {
            $message = __('vault.upload_no_file_to_unpack');
            notifyError($message);
            $this->errorState($message);

            return;
        }

        $file = $this->vtools()->getFileById($fid);

        if (! isset($file) || empty($file)) {
            $message = __('vault.upload_unpack_not_found');
            notifyError($message);
            $this->errorState($message);

            return;
        }

        if (! isset($key)) {
            // if there is an existing decrypt-pass key, it is required...
            $user = auth()->user();
            foreach ($user->apiKeys as $apiKey) {
                if ($apiKey->name == 'decrypt-pass') {
                    if (! $this->vaultsDisabled) {
                        $encrypter = new Encrypter(
                            key: getSvaultKey('svault0'),
                            cipher: config('app.cipher'),
                        );
                        $key = $encrypter->decrypt($apiKey->key);
                    } else {
                        $key = $apiKey->key;
                    }
                    break;
                }
            }
        }

        if ($this->itsmLink) {
            // ITSM download: call doDecryptAndExtract directly to pass case context
            $path = $this->vtools()->getMountPoint().'/';
            $statusfile = "/tmp/{$file->id}.json";
            $errorMesg = null;
            $did = null;
            $cid = null;

            $statusdata = ['phase' => 'Processing', 'percentage' => 1, 'filename' => $file->name];
            file_put_contents($statusfile, json_encode($statusdata));

            $this->vtools()->doDecryptAndExtract(
                $file->name, $path, $key, $did, $cid, $errorMesg, $statusfile,
                $this->itsmCustomer, null, $this->itsmLink
            );

            // Clear ITSM context now that unpack is handled
            $this->itsmCustomer = null;
            $this->itsmLink = null;
            $this->itsmDescription = null;
        } else {
            $this->vtools()->unpack($key, $file->name, $file->id);
        }

        // Fire UNPACK FAILED sysevent + Telegram before pulling the session notifications,
        // so that addEvent() doesn't interfere with the notification race-condition fix.
        $unpackHadError = ! empty($this->vtools()->emessage);
        if ($unpackHadError) {
            $user = auth()->user();
            $payload = (object) [
                'message' => $this->vtools()->emessage,
                'name' => $file->name,
                'id' => $file->id,
            ];
            addEvent($payload, 'UNPACK', 'FAILED', 'NORMAL', 0, $this->vid, $user->id, $user->group_id ?? $user->id);
        }

        // Pull notifications from session BEFORE dispatching refreshComponents.
        // This prevents the race condition in production (multi-worker PHP-FPM) where
        // the dehydrate hook on every re-rendering component would each dispatch
        // 'notificationsSent', causing the Notifications component to receive multiple
        // concurrent requests and lose the notification due to stale Livewire snapshots.
        $pendingNotifications = session()->pull('filament.notifications', []);

        // stop and hide the progress bar
        $this->dispatch('stop-progress', fid: $file->id);

        // Refresh the components — session is now empty so no race condition
        $this->dispatch('refreshComponents');

        // reset the upload
        $this->dispatch('initialState');

        // Deliver each drained toast purely client-side. Re-dispatching the
        // `notificationSent` Livewire event races with refreshComponents and
        // corrupts the shared notifications snapshot (see filamentToastJs).
        foreach ($pendingNotifications as $notification) {
            if (is_array($notification)) {
                $this->js(filamentToastJs($notification));
            }
        }

    }

    #[On('clearUploadErrors')]
    public function clearUploadErrors(): void
    {
        $this->resetErrorBag('data.sosreport');
    }

    public function fetchAttachments(): void
    {
        $this->fetchError = '';

        if (empty($this->itsmCaseNumber)) {
            $this->fetchError = __('vault.itsm_case_required');

            return;
        }

        $result = app(JiraService::class)->getAttachemnets(auth()->user(), $this->itsmCaseNumber);

        if (! $result) {
            $this->fetchError = __('vault.itsm_fetch_failed');

            return;
        }

        $sosPattern = '/^(secured-)*(sosreport-)+..*(gpg|gz|xz)$/';
        $this->attachments = array_values(array_filter(
            (array) $result->attachments,
            fn ($a) => preg_match($sosPattern, $a['filename'] ?? '')
        ));

        if (empty($this->attachments)) {
            $this->fetchError = __('vault.itsm_no_sosreports');

            return;
        }

        $customer = $result->customer;
        $this->caseInfo = [
            'description' => $result->description,
            'customer' => is_array($customer) ? ($customer['name'] ?? null) : $customer,
            'link' => $result->link,
        ];
        $this->itsmSection = 2;
    }

    public function downloadFromITSM(int $index): void
    {
        $user = auth()->user();
        $attachment = $this->attachments[$index] ?? null;

        if (! $attachment) {
            return;
        }

        $selectedFile = (object) $attachment;
        $filename = $selectedFile->filename;
        $directory = $this->vtools()->getMountPoint();
        $newfile = "{$directory}/{$filename}";

        if (is_file($newfile)) {
            $this->errorState(__('vault.upload_already_exists'));

            return;
        }

        $fdata = $this->vtools()->parseFilename($filename);
        $newpath = "{$directory}/{$fdata->path}";

        if (is_dir($newpath)) {
            $this->errorState(__('vault.upload_dir_exists'));

            return;
        }

        if (! $this->vtools()->doesItFit($selectedFile->size)) {
            $this->errorState(__('vault.upload_no_space'));

            return;
        }

        app(JiraService::class)->downloadFile($user, $this->itsmCaseNumber, $selectedFile, $newfile);

        if (! is_file($newfile)) {
            $this->errorState(__('vault.itsm_download_error'));

            return;
        }

        $this->vtools()->updateContents();
        $files = $this->vtools()->getFiles();
        $file = null;

        foreach ($files as $f) {
            if ($f->name === $filename) {
                $file = $f;
                break;
            }
        }

        if (! $file) {
            $this->errorState(__('vault.upload_not_found_after'));

            return;
        }

        $this->itsmCustomer = $this->caseInfo['customer'] ?? null;
        $this->itsmLink = $this->caseInfo['link'] ?? null;
        $this->itsmDescription = $this->caseInfo['description'] ?? null;

        $uid = $user->id;
        $payload = (object) [
            'message' => __('vault.itsm_download_success'),
            'issue' => $this->itsmCaseNumber,
            'name' => $filename,
            'size' => Number::fileSize($selectedFile->size),
            'via' => 'web',
        ];
        addEvent($payload, 'ITSM_DOWNLD', 'SUCCESS', 'ACTIVITY', 0, $this->vid, $uid, $uid);

        Notification::make()
            ->title(__('vault.itsm_download_success'))
            ->icon('phosphor-bell-ringing-duotone')
            ->iconColor('success')
            ->send();

        $this->dispatch('close-modal', id: 'itsm-download-modal');
        $this->resetItsmState();

        $key = null;
        foreach ($user->apiKeys as $apiKey) {
            if ($apiKey->name === 'decrypt-pass') {
                if (! $this->vaultsDisabled) {
                    $encrypter = new Encrypter(
                        key: getSvaultKey('svault0'),
                        cipher: config('app.cipher'),
                    );
                    $key = $encrypter->decrypt($apiKey->key);
                } else {
                    $key = $apiKey->key;
                }
                break;
            }
        }

        if (! $fdata->gpg || (! empty($key) && $fdata->gpg)) {
            $this->dispatch('start-progress', fid: $file->id, key: $key);
        } else {
            $this->dispatch('refreshComponents');
        }
    }

    public function resetItsmState(): void
    {
        $this->itsmSection = 1;
        $this->attachments = [];
        $this->caseInfo = null;
        $this->fetchError = '';
        $this->itsmCaseNumber = '';
    }

    protected function renderCaseSummary(): string
    {
        $description = e($this->caseInfo['description'] ?? '');
        $customer = e($this->caseInfo['customer'] ?? '');
        $link = e($this->caseInfo['link'] ?? '');

        $rows = '';
        if ($customer) {
            $rows .= "<dt class='font-medium text-gray-500 dark:text-zinc-400'>".__('cases.col_customer')."</dt><dd class='text-gray-800 dark:text-zinc-200'>".e($customer).'</dd>';
        }
        if ($link) {
            $rows .= "<dt class='font-medium text-gray-500 dark:text-zinc-400'>Link</dt><dd><a href='".e($link)."' target='_blank' class='text-primary-600 dark:text-primary-400 underline text-sm'>".e($link).'</a></dd>';
        }
        if ($description) {
            $rows .= "<dt class='font-medium text-gray-500 dark:text-zinc-400 col-span-2'>".__('cases.col_description')."</dt><dd class='col-span-2 text-gray-700 dark:text-zinc-300 text-sm whitespace-pre-line'>".e($description).'</dd>';
        }

        return "<dl class='grid grid-cols-2 gap-x-4 gap-y-2 text-sm py-2'>{$rows}</dl>";
    }

    protected function renderAttachmentsTable(): string
    {
        if (empty($this->attachments)) {
            return '';
        }

        $downloadLabel = __('vault.itsm_attachment_download');
        $rows = '';
        foreach ($this->attachments as $index => $attachment) {
            $name = e($attachment['filename'] ?? '');
            $size = Number::fileSize($attachment['size'] ?? 0);
            $date = e(substr($attachment['created'] ?? '', 0, 10));
            $idx = (int) $index;

            $rows .= <<<HTML
                    <tr class="border-b border-gray-200 dark:border-zinc-700 hover:bg-gray-50 dark:hover:bg-zinc-800/50">
                        <td class="py-2 px-3 text-sm text-gray-800 dark:text-zinc-200 font-mono break-all">{$name}</td>
                        <td class="py-2 px-3 text-sm text-gray-600 dark:text-zinc-400 whitespace-nowrap">{$size}</td>
                        <td class="py-2 px-3 text-sm text-gray-600 dark:text-zinc-400 whitespace-nowrap">{$date}</td>
                        <td class="py-2 px-3 text-right">
                            <button type="button"
                                x-on:click="\$wire.downloadFromITSM({$idx})"
                                class="inline-flex items-center gap-1 rounded-md bg-primary-600 px-2.5 py-1 text-xs font-semibold text-white shadow-sm hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-600">
                                {$downloadLabel}
                            </button>
                        </td>
                    </tr>
                HTML;
        }

        $thClass = 'py-2 px-3 text-left text-xs font-semibold text-gray-500 dark:text-zinc-400 uppercase tracking-wider';
        $colName = __('vault.itsm_attachment_col_name');
        $colSize = __('vault.itsm_attachment_col_size');
        $colDate = __('vault.itsm_attachment_col_date');

        return <<<HTML
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-zinc-700 mt-2">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-zinc-700">
                        <thead class="bg-gray-50 dark:bg-zinc-800">
                            <tr>
                                <th class="{$thClass}">{$colName}</th>
                                <th class="{$thClass}">{$colSize}</th>
                                <th class="{$thClass}">{$colDate}</th>
                                <th class="{$thClass}"></th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-zinc-900 divide-y divide-gray-100 dark:divide-zinc-800">
                            {$rows}
                        </tbody>
                    </table>
                </div>
            HTML;
    }

    public function form(Schema $schema): Schema
    {
        $mimeTypes = [
            'multipart/encrypted',
            'application/octet-stream',
            'application/pgp-encrypted',
            'application/x-xz',
            'application/gzip',           // RFC standard — Chrome/Linux .tar.gz
            'application/x-gzip',         // legacy — Firefox/macOS .tar.gz
            'application/tar+gzip',
            'application/x-compressed-tar',
            'application/x-tar',
            'application/tar',
            '.gpg',
            '.gz',
            '.xz',
        ];

        $regex = $this->uploadFilenamePattern();

        $maxSize = (int) ini_get('upload_max_filesize') * 1024;
        $help = '';

        $this->description = '';
        $this->description .= "<div class='my-2 text-sm'>";
        $this->description .= __('vault.upload_description').' ';
        $this->description .= '<p><span class="inline-block w-full text-center text-sm font-semibold my-2">';
        $this->description .= __('vault.upload_format_example').'</span>';
        $this->description .= '<p>';
        $this->description .= __('vault.upload_auto_unpack');
        $this->description .= '</div>';

        return $schema
            ->statePath('data')
            ->components([
                FileUpload::make('sosreport')
                    ->hiddenLabel()
                    ->visibility('private')
                    ->preserveFilenames(true)
                    ->panelAspectRatio('10:1')
                    ->panelLayout('compact')
                    /*
                    ->removeUploadedFileButtonPosition('right')
                    ->loadingIndicatorPosition('left')
                    ->uploadButtonPosition('left')
                    ->uploadProgressIndicatorPosition('left')
                    */
                    ->helperText(function (): HtmlString {
                        return new HtmlString($this->description);
                    })
                    ->hint("$help")
                    ->required()
                    ->maxSize($maxSize)
                    ->regex($regex)
                    ->acceptedFileTypes($mimeTypes)
                    ->afterStateUpdated(function ($state): void {
                        if (isset($state)) {
                            $file = $this->save($state);
                            if (isset($file)) {
                                $key = null;
                                $this->dispatch('start-progress', fid: $file->id, key: $key);
                            } else {
                                // Pull notifications from session before refreshComponents to prevent race condition
                                $pendingNotifications = session()->pull('filament.notifications', []);
                                // Refresh the tables (session is now empty, safe to dispatch)
                                $this->dispatch('refreshComponents');
                                // Reset the upload
                                $this->dispatch('initialState');
                                // Deliver drained toasts client-side (avoids the
                                // notificationSent/refreshComponents snapshot race).
                                foreach ($pendingNotifications as $notification) {
                                    if (is_array($notification)) {
                                        $this->js(filamentToastJs($notification));
                                    }
                                }
                            }
                        }
                    }),
            ]);
    }
}
?>

<x-app.container-half>
    <style>
        /* Make FilePond validation error messages readable */
        .filepond--file-status-main {
            font-size: 0.8rem !important;
        }
        .filepond--file-status-sub {
            font-size: 0.7rem !important;
            white-space: normal !important;
            overflow: visible !important;
            text-overflow: unset !important;
            line-height: 1.3 !important;
        }
        .filepond--item[data-filepond-item-state="load-error"] .filepond--file,
        .filepond--item[data-filepond-item-state="processing-error"] .filepond--file {
            min-height: 3.5rem;
        }
    </style>
    <div>
        @script
        <script>
           $wire.on('errorState', () => {
                const pond = FilePond.find($el.querySelector('.filepond--root'));
                if(pond) {
                    pond.element.classList.add('bg-danger-700');
                    setTimeout(() => {
                        pond.removeFiles({ revert: true });
                        setTimeout(() => {
                            pond.element.classList.remove('bg-danger-700');
                            setTimeout(() => {
                                Livewire.dispatch('clearUploadErrors');
                            }, 5000);
                        }, 5000);
                    }, 2000);
                }
           });
           $wire.on('initialState', () => {
                const pond = FilePond.find($el.querySelector('.filepond--root'));
                if(pond) {
                    setTimeout(() => {
                        pond.removeFiles({ revert: true });
                    }, 5000);
                }
           });
        </script>
        @endscript

        {{ $this->form }}

        @if($itsmProvider)
            <div class="mt-3 flex justify-end">
                <x-filament::button
                    color="info"
                    x-on:click="$dispatch('open-modal', { id: 'itsm-download-modal' })"
                    icon="phosphor-cloud-arrow-down-duotone"
                >
                    {{ __('vault.itsm_download_button') }}
                </x-filament::button>
            </div>

            <x-filament::modal
                id="itsm-download-modal"
                width="3xl"
                :close-by-clicking-away="true"
            >
                <x-slot name="heading">
                    {{ __('vault.itsm_modal_heading') }}
                </x-slot>

                <div class="space-y-4 py-2">
                    @if($itsmSection === 1)
                        <p class="text-sm text-gray-600 dark:text-zinc-300">
                            {{ __('vault.itsm_search_instructions') }}
                        </p>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-zinc-300 mb-1">
                                {{ __('vault.itsm_case_label') }}
                            </label>
                            <input
                                type="text"
                                wire:model="itsmCaseNumber"
                                wire:keydown.enter="fetchAttachments"
                                placeholder="{{ __('vault.itsm_case_placeholder') }}"
                                class="block w-full rounded-lg border border-gray-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-3 py-2 text-sm text-gray-900 dark:text-zinc-100 placeholder-gray-400 dark:placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                            />
                        </div>
                        @if($fetchError)
                            <p class="text-sm text-danger-600 dark:text-danger-400">{{ $fetchError }}</p>
                        @endif
                        <div class="flex justify-end gap-3 pt-2">
                            <x-filament::button
                                color="gray"
                                x-on:click="$dispatch('close-modal', { id: 'itsm-download-modal' })"
                            >
                                {{ __('vault.itsm_cancel_button') }}
                            </x-filament::button>
                            <x-filament::button
                                color="primary"
                                wire:click="fetchAttachments"
                                wire:loading.attr="disabled"
                                wire:target="fetchAttachments"
                            >
                                <span wire:loading.remove wire:target="fetchAttachments">{{ __('vault.itsm_search_button') }}</span>
                                <span wire:loading wire:target="fetchAttachments">...</span>
                            </x-filament::button>
                        </div>
                    @else
                        @if($caseInfo)
                            {!! $this->renderCaseSummary() !!}
                        @endif
                        {!! $this->renderAttachmentsTable() !!}
                        <div class="flex justify-end gap-3 pt-2">
                            <x-filament::button
                                color="gray"
                                wire:click="resetItsmState"
                            >
                                {{ __('vault.itsm_back_button') }}
                            </x-filament::button>
                            <x-filament::button
                                color="gray"
                                x-on:click="$dispatch('close-modal', { id: 'itsm-download-modal' })"
                            >
                                {{ __('vault.itsm_cancel_button') }}
                            </x-filament::button>
                        </div>
                    @endif
                </div>
            </x-filament::modal>
        @endif

        @if($withProgressBar)
            @livewire('progress-bar', ['vid' => $vid])
        @endif()

    </div>
</x-app.container-half>
