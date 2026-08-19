<?php

namespace App\Livewire;

use Livewire\Component;

class ListCases extends Component
{
    public $type;

    public function render()
    {
        return view('livewire.list-cases');
    }
}
