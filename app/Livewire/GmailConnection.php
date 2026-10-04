<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\GmailCredential;
use App\Support\Email\GmailMailbox;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;

final class GmailConnection extends Component
{
    public string $username = '';

    public string $app_password = '';

    public ?string $testMessage = null;

    public bool $testPassed = false;

    #[Computed]
    public function credential(): ?GmailCredential
    {
        return GmailCredential::query()->where('user_id', Auth::id())->first();
    }

    public function connect(): void
    {
        try {
            $validated = $this->validate([
                'username' => ['required', 'string', 'email', 'max:255'],
                'app_password' => ['required', 'string', 'max:128'],
            ]);
        } catch (ValidationException $e) {
            $this->reset('app_password');

            throw $e;
        }

        $password = (string) preg_replace('/[\s\h]+/u', '', $validated['app_password']);

        try {
            (new GmailMailbox($validated['username'], $password))->verify();
        } catch (Throwable $e) {
            $this->reset('app_password');
            $this->addError('app_password', $this->failureMessage($e));

            return;
        }

        try {
            GmailCredential::query()->updateOrCreate(
                ['user_id' => Auth::id()],
                ['username' => $validated['username'], 'app_password' => $password, 'last_verified_at' => now()],
            );
        } catch (Throwable $e) {
            $this->reset('app_password');
            Log::warning('Gmail connection save failed', ['exception' => $e::class]);
            $this->addError('app_password', __('Could not save your Gmail connection. Try again.'));

            return;
        }

        $this->reset('username', 'app_password', 'testMessage', 'testPassed');
        unset($this->credential);

        $this->dispatch('gmail-saved');
    }

    public function test(): void
    {
        $credential = $this->credential; // @phpstan-ignore property.notFound

        if ($credential === null) {
            return;
        }

        try {
            (new GmailMailbox($credential->username, $credential->app_password))->verify();
        } catch (Throwable $e) {
            $this->testPassed = false;
            $this->testMessage = $this->failureMessage($e);

            return;
        }

        $credential->update(['last_verified_at' => now()]);
        unset($this->credential);

        $this->testPassed = true;
        $this->testMessage = __('Connection works.');
    }

    public function disconnect(): void
    {
        GmailCredential::query()->where('user_id', Auth::id())->delete();

        $this->reset('username', 'app_password', 'testMessage', 'testPassed');
        unset($this->credential);

        $this->modal('confirm-gmail-disconnect')->close();
        $this->dispatch('gmail-disconnected');
    }

    public function render(): View
    {
        return view('livewire.gmail-connection');
    }

    private function failureMessage(Throwable $e): string
    {
        if ($this->loginRejected($e)) {
            return __('Gmail rejected these details. Use a Google app password (it needs 2-Step Verification), not your normal password.');
        }

        Log::warning('Gmail connection check failed', ['exception' => $e::class]);

        return __('Could not reach Gmail. Check your connection and try again.');
    }

    private function loginRejected(Throwable $e): bool
    {
        for ($current = $e; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof AuthFailedException) {
                return true;
            }

            if ($current instanceof ImapServerErrorException
                && preg_match('/AUTHENTICATIONFAILED|Application-specific password|Invalid credentials/i', $current->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }
}
