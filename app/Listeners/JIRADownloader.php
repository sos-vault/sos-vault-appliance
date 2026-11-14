<?php

namespace App\Listeners;

use App\Events\JIRADownload;
use App\Exceptions\InvalidSosreportFilenameException;
use App\Providers\DataTools;
use App\Providers\VaultTools;
use App\Services\JiraService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;

class JIRADownloader implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;

    protected $error_message;

    private bool $DEBUG = false;

    // Create the event listener.
    public function __construct() {}

    // Handle the event.
    public function handle(JIRADownload $event): void
    {

        // throw new \Exception("Error Processing the job", 1);

        $user = $event->data['user'];
        $issueid = $event->data['issueid'];
        $selectedFile = $event->data['selectedFile'];
        $report = $event->data['report'];
        $customer = $event->data['customer'];
        $version = $event->data['version'];
        $link = $event->data['link'];

        if (! $user || ! $issueid || ! $selectedFile || ! $report) {
            return;
        }

        if ($user->locale && array_key_exists($user->locale, config('app.supported_locales', []))) {
            App::setLocale($user->locale);
        }

        $ini = microtime(true);

        $message = '';
        if ($event instanceof JIRADownload) {
            if (! $this->retrieveFile($user, $issueid, $selectedFile, $report, $customer, $version, $link)) {
                $type = 'error';
                // Original: "Issue {$issueid} attachment {$selectedFile->filename} download failed. {$this->error_message}"
                $message = __('notifications.jira_download_failed', [
                    'issueid' => $issueid,
                    'filename' => $selectedFile->filename,
                    'error' => $this->error_message,
                ]);
                notifyError($message);
            } else {
                $type = 'success';
                // Original: "Issue {$issueid} attachment {$selectedFile->filename} download successful."
                $message = __('notifications.jira_download_success', [
                    'issueid' => $issueid,
                    'filename' => $selectedFile->filename,
                ]);

                Notification::make()
                    ->title($message)
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('success')
                    ->send();

                Log::info($message);
            }
        }

        // send to Notifications page
        notifyUser($user, $message, $type);

        $end = microtime(true);
        Log::info(sprintf('JIRADownload took %d s', $end - $ini));

    }

    public function failed($event, $exception): void
    {
        Log::info('failed');
    }

    public function retrieveFile($user, $issueid, $selectedFile, $report, $customer, $version, $link)
    {

        $uid = $user->id;
        $gid = $user->id;
        $vid = 0;
        $cid = 0;

        $vtools = new VaultTools($user);
        if (! $vtools) {
            $message = "No vault associated to {$user->username}.";
            $this->error_message = $message;
            $payload = (object) [
                'message' => $message,
                'issue' => $issueid,
                'attachments' => $selectedFile->filename,
                'size' => Number::fileSize($selectedFile->size),
                'via' => 'web',
            ];
            addEvent($payload, 'ITSM_DOWNLD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

            return false;
        }

        $vid = $vtools->getVaultId();

        if (! $vtools->vaultExists()) {
            $message = "No vault found for {$user->username}.";

            $this->error_message = $message;
            $payload = (object) [
                'message' => $message,
                'issue' => $issueid,
                'attachments' => $selectedFile->filename,
                'size' => Number::fileSize($selectedFile->size),
                'via' => 'web',
            ];
            addEvent($payload, 'ITSM_DOWNLD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

            return false;
        }

        if (! $vtools->isOpen()) {
            if (! $vtools->openVault()) {
                $message = "Couldn't open {$user->username}'s vault.";

                $this->error_message = $message;
                $payload = (object) [
                    'message' => $message,
                    'issue' => $issueid,
                    'attachments' => $selectedFile->filename,
                    'size' => Number::fileSize($selectedFile->size),
                    'via' => 'web',
                ];
                addEvent($payload, 'ITSM_DOWNLD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

                return false;
            }
        }

        // check is there is enough space in users vault (size in bytes)
        if (! $vtools->doesItFit($selectedFile->size)) {
            $message = "Not enough space left on {$user->username} vault";
            $this->error_message = $message;
            $payload = (object) [
                'message' => $message,
                'issue' => $issueid,
                'attachments' => $selectedFile->filename,
                'size' => Number::fileSize($selectedFile->size),
                'via' => 'web',
            ];
            addEvent($payload, 'ITSM_DOWNLD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

            return false;
        }

        // PEND: Check if php can handle the file size
        // $max_size = (int) ini_get("upload_max_filesize") * 1000; //config:5120M (max_size=5120000)

        $filename = $selectedFile->filename;
        if (! preg_match('/^(secured-)*(sosreport-)+..*(gpg|gz|xz)$/', $filename)) {

            $message = 'Invalid sosreport filename.';
            $this->error_message = $message;
            $payload = (object) [
                'message' => $message,
                'issue' => $issueid,
                'attachments' => $selectedFile->filename,
                'size' => Number::fileSize($selectedFile->size),
                'via' => 'web',
            ];
            addEvent($payload, 'ITSM_DOWNLD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

            return false;
        }

        // does a packed file with exactly the same name alredy exists in the vault
        $newfile = $vtools->getMountPoint().'/'.$filename;

        $this->DEBUG && Log::info('newfile: '.var_export($newfile, 1));

        if (is_file($newfile)) {
            $message = 'This sosreport file is already in your vault.';
            $this->error_message = $message;
            $payload = (object) [
                'message' => $message,
                'issue' => $issueid,
                'attachments' => $selectedFile->filename,
                'size' => Number::fileSize($selectedFile->size),
                'via' => 'web',
            ];
            addEvent($payload, 'ITSM_DOWNLD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

            return false;
        }

        // does an unpacked dir with corresponding name alredy exists in the vault
        try {
            $fdata = $vtools->parseFilename($filename);
        } catch (InvalidSosreportFilenameException $e) {
            $message = __('vault.upload_unparseable_name');
            $this->error_message = $message;
            notifyUser($user, $message, 'error');
            $payload = (object) [
                'message' => $message,
                'issue' => $issueid,
                'attachments' => $selectedFile->filename,
                'size' => Number::fileSize($selectedFile->size),
                'via' => 'web',
            ];
            addEvent($payload, 'ITSM_DOWNLD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

            return false;
        }

        $this->DEBUG && Log::info('fdata: '.var_export($fdata, 1));

        $newpath = $vtools->getMountPoint().'/'.$fdata->path;

        $this->DEBUG && Log::info('newpath: '.var_export($newpath, 1));

        if (is_dir($newpath)) {
            $message = 'This sosreport is alredy uploaded and unpacked as a directory.';
            $this->error_message = $message;
            $payload = (object) [
                'message' => $message,
                'issue' => $issueid,
                'attachments' => $selectedFile->filename,
                'size' => Number::fileSize($selectedFile->size),
                'via' => 'web',
            ];
            addEvent($payload, 'ITSM_DOWNLD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

            return false;
        }

        try {
            app(JiraService::class)->downloadFile($user, $issueid, $selectedFile, $report);
        } catch (Exception $e) {
            $message = $e->getMessage();
            if (is_file($report)) {
                unlink($report);
            }
            $this->error_message = $message;
            $payload = (object) [
                'message' => $message,
                'issue' => $issueid,
                'attachments' => $selectedFile->filename,
                'size' => Number::fileSize($selectedFile->size),
                'via' => 'web',
            ];
            addEvent($payload, 'ITSM_DOWNLD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

            return false;
        }

        if (! is_file($report)) {
            $message = 'Failed to download sosreport file.';
            $this->error_message = $message;
            $payload = (object) [
                'message' => $message,
                'issue' => $issueid,
                'attachments' => $selectedFile->filename,
                'size' => Number::fileSize($selectedFile->size),
                'via' => 'web',
            ];
            addEvent($payload, 'ITSM_DOWNLD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

            return false;
        }

        // at this point the file is already in the vault....

        $message = 'Selected file downloaded correctly and ready for unpack. ';
        $payload = (object) [
            'message' => $message,
            'issue' => $issueid,
            'attachments' => $selectedFile->filename,
            'size' => Number::fileSize($selectedFile->size),
            'via' => 'web',
        ];
        addEvent($payload, 'ITSM_DOWNLD', 'SUCCESS', 'NORMAL', $cid, $vid, $uid, $gid);

        // if there is an existing decrypt-pass key, try to unpack...
        // or if the file is not encrypted, try to unpack...
        $type = 'success';
        $key = (object) ['key' => 'anything'];
        foreach ($user->apiKeys as $apiKey) {
            if ($apiKey->name == 'decrypt-pass') {
                $key = $apiKey;
                break;
            }
        }

        try {
            $fdata = $vtools->parseFilename($selectedFile->filename);
        } catch (InvalidSosreportFilenameException $e) {
            $message = __('vault.upload_unparseable_name');
            $this->error_message = $message;
            notifyUser($user, $message, 'error');
            $payload = (object) [
                'message' => $message,
                'issue' => $issueid,
                'attachments' => $selectedFile->filename,
                'size' => Number::fileSize($selectedFile->size),
                'via' => 'web',
            ];
            addEvent($payload, 'UNPACK', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

            return false;
        }

        if ((isset($key) && $key) || ! $fdata->gpg) {
            $files = $vtools->getFiles();
            if (! $files) {
                $message = 'No files found in vault.';
                $this->error_message = $message;
                $payload = (object) [
                    'message' => $message,
                    'issue' => $issueid,
                    'attachments' => $selectedFile->filename,
                    'size' => Number::fileSize($selectedFile->size),
                    'via' => 'web',
                ];
                addEvent($payload, 'UNPACK', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

                return false;
            }

            foreach ($files as $fileobj) {
                if ($fileobj->name == $selectedFile->filename) {
                    $file = $fileobj;
                    break;
                }
            }

            if (! $file) {
                $message = 'No such file in vault.';
                $this->error_message = $message;
                $payload = (object) [
                    'message' => $message,
                    'issue' => $issueid,
                    'attachments' => $selectedFile->filename,
                    'size' => Number::fileSize($selectedFile->size),
                    'via' => 'web',
                ];
                addEvent($payload, 'UNPACK', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

                return false;
            }

            $this->DEBUG && Log::info('file found: '.var_export($file, 1));

            $statusfile = "/tmp/{$file->id}.json";
            $statuslock = "/tmp/{$file->id}.lock";

            if ($statusfile) {
                $statusdata = [
                    'phase' => 'Processing',
                    'percentage' => 1,
                    'filename' => $file->name,
                ];
                file_put_contents($statusfile, json_encode($statusdata));
                sleep(1);
            }

            $path = $vtools->getMountPoint().'/';

            $this->emessage = null;
            $this->etype = null;
            $this->cid = null;
            $this->did = null;

            if (! $vtools->doDecryptAndExtract($file->name, $path, $key->key, $this->did, $this->cid, $this->emessage, $statusfile, $customer, $version, $link)) {
                $message .= 'File extraction failed. ';

                if ($this->emessage) {
                    // populated during decryption or extraction
                    $message .= $this->emessage;
                }

                $this->error_message = $message;
                $payload = (object) [
                    'message' => $message,
                    'issue' => $issueid,
                    'attachments' => $selectedFile->filename,
                    'size' => Number::fileSize($selectedFile->size),
                    'via' => 'web',
                ];
                addEvent($payload, 'UNPACK', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

                return false;
            }

            $message .= 'File extraction complete.';

            $this->DEBUG && Log::info('file unpacked successfully');

            if (isset($this->did)) {
                // pre extract data for summary tool
                $this->DEBUG && Log::info('Generiating Summary tool data');

                $dtools = new DataTools($vtools, $vid, $this->did);
                $dtools->summaryData($this->cid);
            }

            $payload = (object) [
                'message' => $message,
                'name' => $file->name,
                'id' => $file->id,
                'via' => 'web',
            ];
            addEvent($payload, 'UNPACK', 'SUCCESS', 'NORMAL', $this->cid, $vid, $uid, $gid);

            return true;
        }

        // encrypted file with no decrypt key available
        $this->error_message = 'Cannot unpack: file is encrypted and no decryption key is configured.';

        return false;
    }
}
