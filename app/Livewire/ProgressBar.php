<?php

namespace App\Livewire;

use App\Providers\VaultTools;
use Livewire\Attributes\On;
use Livewire\Component;

class ProgressBar extends Component
{
    public $vid;

    public $fid;

    public $currentVal = 0;

    public $currentPhase;

    public $isProgress = false;

    private $DEBUG = false;

    private $vtools = null;

    public function mount($vid)
    {
        if (! isset($vid)) {
            $message = __('vault.badge_no_vault_monitor');
            notifyError($message);

            return null;
        }

        $this->vid = $vid;
        $this->currentVal = 0;
        $this->currentPhase = '';
    }

    #[On('stop-progress')]
    public function toggleProgress()
    {
        $this->isProgress = false;
        $this->dispatch('close-modal', id: 'progress-modal');
        if (isset($this->fid)) {
            $statusfile = "/tmp/{$this->fid}.json";
            $statuslock = "/tmp/{$this->fid}.lock";
            is_file($statusfile) && unlink($statusfile);
            is_file($statuslock) && unlink($statuslock);
        }
    }

    #[On('start-progress')]
    public function startProgress($fid, $key)
    {
        $this->dispatch('unpackFile', fid: $fid, key: $key);
        $this->isProgress = true;
        if ($this->isProgress) {
            $this->currentVal = 0;
            $this->currentPhase = __('vault.badge_starting');
            $this->dispatch('open-modal', id: 'progress-modal');
        }
        $this->fid = $fid;
    }

    public function poll()
    {
        $progress = json_decode($this->vtools()->unpackStatus($this->fid), true);
        isset($progress['phase']) && $this->currentPhase = $progress['phase'];
        if ($this->currentVal < 100) {
            isset($progress['percentage']) && $this->currentVal = $progress['percentage'];
        }
    }

    public function render()
    {
        return view('livewire.progress-bar');
    }

    public function vtools(): ?VaultTools
    {
        if (isset($this->vtools)) {
            return $this->vtools;
        }

        if (! isset($this->vid)) {
            $message = __('vault.dir_no_vault');
            notifyError($message);

            return null;
        }

        $this->vtools = new VaultTools(auth()->user(), $this->vid);

        if (! isset($this->vtools)) {
            $message = __('vault.dir_vault_access_error');
            notifyError($message);

            return null;
        }

        if ($this->vtools->getVaultId() != $this->vid) {
            $message = __('vault.dir_wrong_vault');
            notifyError($message);

            return null;
        }

        if (! $this->vtools->isOpen()) {
            $message = __('vault.dir_vault_closed');
            notifyError($message);

            return null;
        }

        return $this->vtools;
    }
}
