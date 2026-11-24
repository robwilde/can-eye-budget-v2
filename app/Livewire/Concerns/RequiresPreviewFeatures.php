<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\User;
use App\Support\PreviewNotice;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

/**
 * Applies the `preview` route middleware's check to a Livewire component on every request, so
 * its actions are gated as well as its first render. A first render redirects to the dashboard
 * with the preview notice; an update from a user who has since turned preview features off is
 * refused with 403, because a redirect cannot stop the action that came with it. Override
 * previewFlag() to name the deployment flag that must also be on (off answers 404). Not for
 * lazy components or components inside a lazy parent: their first mount arrives as an update
 * request, which gets the 403 rather than the redirect.
 */
trait RequiresPreviewFeatures
{
    public function bootRequiresPreviewFeatures(): void
    {
        $flag = $this->previewFlag();

        abort_if($flag !== null && config($flag) !== true, 404);

        $user = auth()->user();

        if ($user instanceof User && $user->hasPreviewFeatures()) {
            return;
        }

        abort_if(app(HandleRequests::class)->isLivewireRequest(), 403, PreviewNotice::message());

        session()->flash(PreviewNotice::SESSION_KEY, PreviewNotice::message());

        throw new HttpResponseException(new RedirectResponse(route('dashboard')));
    }

    protected function previewFlag(): ?string
    {
        return null;
    }
}
