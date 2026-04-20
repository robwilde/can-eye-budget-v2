<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\View\View;
use Livewire\Component;

final class SmokeTest extends Component
{
    public int $count = 0;

    public ?string $filter = 'all';

    public function increment(): void
    {
        $this->count++;
    }

    public function render(): View
    {
        return view('livewire.smoke-test');
    }
}
