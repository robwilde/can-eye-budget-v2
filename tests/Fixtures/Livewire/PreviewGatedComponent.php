<?php

declare(strict_types=1);

namespace Tests\Fixtures\Livewire;

use App\Livewire\Concerns\RequiresPreviewFeatures;
use Livewire\Component;

final class PreviewGatedComponent extends Component
{
    use RequiresPreviewFeatures;

    public int $runs = 0;

    public function run(): void
    {
        $this->runs++;
    }

    public function render(): string
    {
        return '<div data-test="gated">gated component</div>';
    }

    protected function previewFlag(): ?string
    {
        return 'budget.goals_enabled';
    }
}
