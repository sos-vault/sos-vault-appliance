<?php

namespace App\Livewire;

use App\Models\FileContent;
use App\Providers\VaultTools;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class FileContents extends Component
{
    #[Locked]
    public $cid;

    #[Locked]
    public $vid;

    #[Locked]
    public $did;

    #[Locked]
    public $fid;

    public $root = 'pre1';

    public $isSosHtml = false;

    public $sme = false;

    public $contents;

    public $metadata;

    public $filename;

    public $lines = 0;

    private $vtools;

    public function render()
    {
        return view('livewire.file-contents');
    }

    public function mount()
    {
        if (! (isset($this->vid) && isset($this->did) && isset($this->fid) && isset($this->cid))) {
            $message = __('vault.file_no_params');
            notifyError($message);
            $this->dispatch('setErrorState');

            return;
        }

        // fileContents has a little metadata but mot enough.
        // metadata has no contents
        // maybe we shall move to VaultTools all the metadata generation
        $vtools = $this->vtools();
        if (! $vtools) {
            // Access denied or vault unavailable — vtools() already notified and
            // dispatched setErrorState.
            return;
        }

        $fileContents = $vtools->getFileContentsById($this->vid, $this->did, $this->fid, 0, $this->cid);

        if (! isset($fileContents) || empty($fileContents)) {
            $message = __('vault.file_not_found');
            notifyError($message);
            $this->dispatch('setErrorState');

            return;
        }

        if ($fileContents->chunked) {
            // only get the initial chunk of data...
            $data = ['offset' => 0];
            $this->loadChunk($data);
            $fileContents->lines = $this->lines;
        } elseif (! empty($fileContents->isSosHtml)) {
            // Fixed sos_reports/sos.html: hand the raw HTML through so the viewer's
            // innerHTML path renders it as a page instead of escaping it to text.
            $this->isSosHtml = true;
            $this->contents = base64_encode($fileContents->contents);
        } else {
            $this->contents = base64_encode($this->ansi2html(htmlspecialchars($fileContents->contents)));
        }

        $metadata = FileContent::withParameters([
            'vid' => $this->vid,
            'did' => $this->did,
            'fid' => $this->fid,
            'cid' => $this->cid,
            'format' => 'raw',
            'source' => 'file-contents',
        ])->where('case_id', $this->cid)->first();

        if (! empty($metadata)) {
            if ($metadata->isTable) {
                $this->dispatch('set-isTable');
            }

            if ($metadata->chunked) {
                $metadata->lines = $this->lines;
            }

            session(['offset' => 0]);
            session(['lines' => 0]);
            session(['chunked' => $metadata->chunked]);
            session(['chunkCount' => 0]);
            session(['totalLines' => (int) $metadata->totalLines]);

            $filepath = explode('/', $metadata->name);
            $filename = explode('_', array_pop($filepath));
            $this->filename = $filename[0];

            // table type control vars
            session(['isTable' => $metadata->isTable]);
            session(['isLogFile' => $metadata->isLogFile]);
            session(['has_header' => $metadata->has_header]);
            session(['headers' => $metadata->headers]);
            session(['column_keys' => implode('|', array_map(
                fn ($h) => preg_replace('/^(\d)/', 'col_$1', preg_replace('/[^a-zA-Z0-9_]/', '_', $h)),
                explode('|', $metadata->headers)
            ))]);
            session(['columns' => (int) $metadata->columns]);

            // log files use these for filters...
            if ($metadata->isLogFile) {
                session(['ini_date' => $metadata->ini_date]);
                session(['ini_time' => $metadata->ini_time]);
                session(['fin_date' => $metadata->fin_date]);
                session(['fin_time' => $metadata->fin_time]);
                session(['tz' => $metadata->tz]);

                $this->dispatch('log-range-ready');
            }

            $this->metadata = base64_encode(json_encode($metadata));
        }
    }

    public function vtools(): ?VaultTools
    {
        if (isset($this->vtools)) {
            return $this->vtools;
        }

        if (! isset($this->vid)) {
            $message = __('vault.file_no_vault');
            notifyError($message);
            $this->dispatch('setErrorState');

            return null;
        }

        $this->vtools = new VaultTools(resolveVaultUser($this->vid, $this->cid, $this->did, $this->fid), $this->vid);

        if (! isset($this->vtools)) {
            $message = __('vault.file_vault_access_error');
            notifyError($message);
            $this->dispatch('setErrorState');

            return null;
        }

        if ($this->vtools->getVaultId() != $this->vid) {
            $message = __('vault.file_wrong_vault');
            notifyError($message);
            $this->dispatch('setErrorState');

            return null;
        }

        if (! $this->vtools->isOpen()) {
            $message = __('vault.file_vault_closed');
            notifyError($message);
            $this->dispatch('setErrorState');

            return null;
        }

        return $this->vtools;
    }

    #[On('load-chunk')]
    public function loadChunk($data)
    {
        $offset = $data['offset'];

        if (! (isset($this->vid) && isset($this->did) && isset($this->fid))) {
            $message = __('vault.file_no_params');
            notifyError($message);
            $this->dispatch('setErrorState');

            return;
        }

        $vtools = $this->vtools();
        if (! $vtools) {
            // Access denied or vault unavailable — vtools() already notified.
            return;
        }

        $fileContents = $vtools->getFileContentsById($this->vid, $this->did, $this->fid, 0, $this->cid);

        if (! isset($fileContents) || empty($fileContents)) {
            $message = __('vault.file_not_found');
            notifyError($message);
            $this->dispatch('setErrorState');

            return;
        }

        if (! $fileContents->chunked) {
            return;
        }

        // only get the next chunk of data...
        $found = $vtools->getFilePathById($this->vid, $this->did, $this->fid, $this->cid);
        if (! $found) {
            $message = __('vault.file_chunk_error');
            notifyError($message);

            // $this->dispatch('setErrorState');
            return;
        }

        $chunkSize = $this->vtools()->chunkSize;

        $filePath = $found->filePath;

        $fileHandle = fopen($filePath, 'rb');
        fseek($fileHandle, $offset);
        $contents = $this->ansi2html(htmlspecialchars(fread($fileHandle, $chunkSize)));
        fclose($fileHandle);

        $this->lines = count(explode("\n", $contents));
        $this->contents = base64_encode($contents);

        $newOffset = $offset + $chunkSize;

        // update FileInfo stats on file-controls componenet
        $this->dispatch('update-offset', [
            'offset' => $newOffset,
            'lines' => $this->lines,
            'chunkSize' => $chunkSize,
        ]);

        // actual retrieval of next text chunk
        if ($newOffset > $chunkSize) {
            $this->dispatch('fetch-chunk', ['contents' => $this->contents]);
        }
    }

    #[On('openSosFile')]
    public function openSosFile($cid, $vid, $did, $fid)
    {
        isset($cid) && $this->cid = $cid;
        isset($vid) && $this->vid = $vid;
        isset($vid) && $this->did = $did;
        isset($fid) && $this->fid = $fid;
        isset($root) && $this->root = $root;
        $this->dispatch('getFileContents');
    }

    public function ansi2html($shellstring)
    {
        $dictionary = [
            '/\x1B\[K\[.*31m/' => '[',
            '/\x1B\[K\[.*32m/' => '[',
            '/\x1B\[K\[.*33m/' => '[',
            '/\x1B\[K\[.*34m/' => '[',
            '/\x1B\[K\[.*35m/' => '[',
            '/\x1B\[K\[.*36m/' => '[',
            '/\x1B\[K\[.*37m/' => '[',
            '/\x1B\[0;30m/' => '<span style="color:black">',
            '/\x1B\[0;31m/' => '<span style="color:red">',
            '/\x1B\[0;32m/' => '<span style="color:green">',
            '/\x1B\[0;33m/' => '<span style="color:yellow">',
            '/\x1B\[0;34m/' => '<span style="color:blue">',
            '/\x1B\[0;35m/' => '<span style="color:magenta">',
            '/\x1B\[0;36m/' => '<span style="color:cyan">',
            '/\x1B\[0;37m/' => '<span style="color:white">',
            '/\x1B\[0;1;30m/' => '<span style="color:black">',
            '/\x1B\[0;1;31m/' => '<span style="color:red">',
            '/\x1B\[0;1;32m/' => '<span style="color:green">',
            '/\x1B\[0;1;33m/' => '<span style="color:yellow">',
            '/\x1B\[0;1;34m/' => '<span style="color:blue">',
            '/\x1B\[0;1;35m/' => '<span style="color:magenta">',
            '/\x1B\[0;1;36m/' => '<span style="color:cyan">',
            '/\x1B\[0;1;37m/' => '<span style="color:white">',
            '/\x1B\[K\[\x1B\[0;30m/' => '<span style="color:black">',
            '/\x1B\[1;30m/' => '<span style="color:gray">',
            '/\x1B\[1;31m/' => '<span style="color:red; font-weight:bold">',
            '/\x1B\[1;32m/' => '<span style="color:lime; font-weight:bold">',
            '/\x1B\[1;33m/' => '<span style="color:yellow; font-weight:bold">',
            '/\x1B\[1;34m/' => '<span style="color:lightblue; font-weight:bold">',
            '/\x1B\[1;35m/' => '<span style="color:magenta; font-weight:bold">',
            '/\x1B\[1;36m/' => '<span style="color:cyan; font-weight:bold">',
            '/\x1B\[1;37m/' => '<span style="color:white; font-weight:bold">',
            '/\x1B\[0m/' => '</span>',
        ];
        $htmlString = preg_replace(array_keys($dictionary), array_values($dictionary), $shellstring);

        return $htmlString;
    }
}
