<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\InsightStatus;
use App\Jobs\GenerateInsightsJob;
use App\Livewire\Attributes\NotAudited;
use App\Models\Insight;
use App\Models\User;
use App\Services\InsightRecorder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The user's insights. Opening the page marks every active insight read, while the
 * "New" marker keeps showing for the insights that were unread when it opened.
 */
final class InsightFeed extends Component
{
    private const int CHECK_TIMEOUT_SECONDS = 120;

    /** @var list<int> */
    #[Locked]
    public array $newIds = [];

    #[Locked]
    public ?string $runId = null;

    #[Locked]
    public ?int $checkingSince = null;

    #[Locked]
    public bool $timedOut = false;

    public function mount(InsightRecorder $recorder): void
    {
        $user = $this->authenticatedUser();

        $this->newIds = $this->activeIds($user);
        $recorder->markAllRead($user);
    }

    public function acknowledge(int $insightId, InsightRecorder $recorder): void
    {
        $insight = $this->visible($this->authenticatedUser())->findOrFail($insightId);

        $recorder->acknowledge($insight);

        unset($this->insights, $this->links); // @phpstan-ignore property.notFound
        $this->dispatch('insight-dismissed');
    }

    public function checkForNew(): void
    {
        $user = $this->authenticatedUser();

        if ($this->runId !== null && ! $this->timedOut) {
            return;
        }

        $this->runId = (string) Str::uuid();
        $this->checkingSince = now()->getTimestamp();
        $this->timedOut = false;

        GenerateInsightsJob::dispatch($user, $this->runId);
    }

    #[NotAudited]
    public function pollRefresh(InsightRecorder $recorder): void
    {
        $user = $this->authenticatedUser();

        if ($this->runId === null || $this->checkingSince === null) {
            return;
        }

        if (Cache::get(GenerateInsightsJob::runKey($this->runId)) === null) {
            $this->timedOut = now()->getTimestamp() - $this->checkingSince > self::CHECK_TIMEOUT_SECONDS;

            if ($this->timedOut) {
                $this->checkingSince = null;
            }

            return;
        }

        $this->runId = null;
        $this->checkingSince = null;
        $this->newIds = array_values(array_unique([...$this->newIds, ...$this->activeIds($user)]));
        $recorder->markAllRead($user);

        unset($this->insights, $this->links); // @phpstan-ignore property.notFound
    }

    /** @return Collection<int, Insight> */
    #[Computed]
    public function insights(): Collection
    {
        return $this->visible($this->authenticatedUser())->feedOrder()->get();
    }

    /** @return array<int, array{url: string, label: string}> */
    #[Computed]
    public function links(): array
    {
        $links = [];

        foreach ($this->insights() as $insight) {
            $link = $this->link($insight);

            if ($link !== null) {
                $links[$insight->id] = $link;
            }
        }

        return $links;
    }

    public function render(): View
    {
        return view('livewire.insight-feed');
    }

    /**
     * @return Builder<Insight>
     */
    private function visible(User $user): Builder
    {
        return Insight::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [InsightStatus::Active->value, InsightStatus::Read->value]);
    }

    /**
     * @return list<int>
     */
    private function activeIds(User $user): array
    {
        return Insight::query()
            ->where('user_id', $user->id)
            ->where('status', InsightStatus::Active->value)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return array{url: string, label: string}|null
     */
    private function link(Insight $insight): ?array
    {
        return match ($insight->action_route) {
            'reports' => ['url' => route('reports'), 'label' => __('View reports')],
            'dashboard' => ['url' => route('dashboard'), 'label' => __('View dashboard')],
            'accounts.show' => $insight->account_id === null ? null : ['url' => route('accounts.show', $insight->account_id), 'label' => __('Go to account')],
            default => null,
        };
    }

    private function authenticatedUser(): User
    {
        abort_unless(config('budget.insights_enabled') === true, 404);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
