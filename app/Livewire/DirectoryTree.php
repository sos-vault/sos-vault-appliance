<?php

namespace App\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;

class DirectoryTree extends Component
{
    public $csrft = '';

    public function render()
    {
        return view('livewire.directory-tree');
    }

    public function mount() {
        $this->csrft = csrf_token();
    }

    #[On('openSosReport')]
    public function openSosReport($cid, $vid, $did, $root, $mode, $tree, $cid2)
    {
        if (isset($cid, $vid, $did, $root, $mode, $tree, $cid2)) {
            $this->dispatch('showReportHierarchy',
                cid: $cid,
                vid: $vid,
                did: $did,
                root: $root,
                mode: $mode,
                tree: $tree,
                cid2: $cid2,
                csrft: $this->csrft,
            );
        }
    }
}
