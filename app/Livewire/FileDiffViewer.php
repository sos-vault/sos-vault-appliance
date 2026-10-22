<?php

namespace App\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;

class FileDiffViewer extends Component
{
    public string $leftContent = '';

    public string $rightContent = '';

    public function mount(string $leftContent = '', string $rightContent = '')
    {
        $maxchars = 2000000;
        // Optional protection for large files
        if (strlen($leftContent) > $maxchars || strlen($rightContent) > $maxchars) {
            $leftContent = substr($leftContent, 0, $maxchars);
            $rightContent = substr($rightContent, 0, $maxchars);
        }

        $this->leftContent = $leftContent;
        $this->rightContent = $rightContent;
    }

    public function render()
    {
        return view('livewire.file-diff-viewer');
    }

    #[On('load-chunk-diff')]
    public function loadChunkDiff($left, $right)
    {
        // Empty on one side is a real diff (file added / emptied / removed) and
        // must still render — only short-circuit when both sides are missing.
        if ($left === null && $right === null) {
            return;
        }

        $this->leftContent = (string) ($left ?? '');
        $this->rightContent = (string) ($right ?? '');

        $this->dispatch('getFileDiff',
            left: $this->leftContent,
            right: $this->rightContent,
        );
    }
}
