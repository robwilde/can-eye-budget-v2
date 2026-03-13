<?php

namespace App\Livewire;

use Illuminate\View\View;
use Livewire\Component;

class SmokeTest extends Component
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function render(): View
    {
        return view('livewire.smoke-test');
    }
}
