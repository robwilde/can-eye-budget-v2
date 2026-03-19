<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\BasiqService;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

final class ConnectBank extends Component
{
    #[Validate('in:connect,manage')]
    public string $action = 'connect';

    public function connect(BasiqService $basiqService): void
    {
        $this->validate();

        $user = auth()->user();

        if (! $user->basiq_user_id) {
            $basiqUser = $basiqService->createUser($user->email);
            $user->update(['basiq_user_id' => $basiqUser->id]);
        }

        $token = $basiqService->clientToken($user->basiq_user_id);

        $state = Str::random(40);
        session()->put('basiq_consent_state', $state);

        $consentUrl = config('services.basiq.consent_url').'/home?'.http_build_query([
            'token' => $token,
            'action' => $this->action,
            'state' => $state,
        ]);

        $this->redirect($consentUrl);
    }

    public function render(): View
    {
        return view('livewire.connect-bank');
    }
}
