<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

trait ReportsFailure
{
    public ?string $errorMessage = null;

    private function fail(string $message): void
    {
        $this->errorMessage = $message;
        $this->addError('errorMessage', $message);
    }

    private function clearFailure(): void
    {
        $this->errorMessage = null;
        $this->resetErrorBag('errorMessage');
    }
}
