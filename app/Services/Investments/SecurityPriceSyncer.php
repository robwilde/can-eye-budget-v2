<?php

declare(strict_types=1);

namespace App\Services\Investments;

use App\Enums\AccountClass;
use App\Enums\ImportSource;
use App\Models\Account;
use App\Models\Holding;
use App\Models\Security;
use App\Models\SecurityPrice;
use App\Models\Trade;
use App\Models\User;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Brings the stored prices of the securities one user holds up to date from their provider, for the
 * last DAYS days. Securities and prices are global, so a security several users hold is fetched once a
 * day: one whose prices were already updated today, by any user's job, is skipped unless $force is
 * set, and the upsert by (security, date) makes a repeat harmless. An offline security is skipped. A
 * provider that fails, or returns no prices, leaves the stored prices as they are and records the
 * error on the security; a success clears it. After a provider fails once in a run it is not called
 * again in that run, so an outage costs one request budget, not one per security. Only ids are logged.
 */
final readonly class SecurityPriceSyncer
{
    public const int DAYS = 31;

    public const string ERROR_PROVIDER = 'provider_error';

    public const string ERROR_NO_PRICES = 'no_prices';

    public function __construct(private SecurityPriceProviders $providers, private AccountBalanceHistoryBuilder $history) {}

    /**
     * @param  int|null  $accountId  limit the sync to the securities held on this one account of the user
     * @return array{synced: int, skipped: int, failed: int}
     */
    public function sync(User $user, ?CarbonImmutable $today = null, bool $force = false, ?int $accountId = null): array
    {
        $today ??= $user->today();
        $from = $today->subDays(self::DAYS - 1);
        $result = ['synced' => 0, 'skipped' => 0, 'failed' => 0];

        $securities = Security::query()
            ->whereIn('id', Holding::query()->forUser($user)->when($accountId !== null, fn ($query) => $query->where('account_id', $accountId))->select('security_id'))
            ->orderBy('id')
            ->get();

        $down = [];
        $priced = [];

        foreach ($securities as $security) {
            $provider = $security->offline_at !== null
                ? null
                : ($security->provider !== null ? $this->providers->find($security->provider) : ($this->providers->enabled()[0] ?? null));

            if ($provider === null || ! $provider->enabled() || isset($down[$provider->key()]) || (! $force && $this->refreshedToday($security))) {
                $result['skipped']++;

                if ($provider !== null && $this->refreshedToday($security)) {
                    $priced[] = $security->id;
                }

                continue;
            }

            $quotes = $provider->prices($security->ticker, $security->exchange_mic, $from, $today);

            if ($quotes === null || $quotes === []) {
                $this->recordError($security, $quotes === null ? self::ERROR_PROVIDER : self::ERROR_NO_PRICES);
                $result['failed']++;

                if ($quotes === null) {
                    $down[$provider->key()] = true;
                }

                continue;
            }

            DB::transaction(function () use ($security, $quotes, $provider): void {
                SecurityPrice::query()->upsert(
                    array_map(fn ($quote): array => [
                        'security_id' => $security->id,
                        'date' => $quote->date->toDateString(),
                        'price' => $quote->price,
                        'currency' => $quote->currency,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ], $quotes),
                    ['security_id', 'date'],
                    ['price', 'currency', 'updated_at'],
                );

                $security->forceFill(['provider' => $security->provider ?? $provider->key(), 'last_error' => null, 'last_error_at' => null])->save();
            });

            $result['synced']++;
            $priced[] = $security->id;
        }

        $this->rebuildHistory($user, $priced);

        return $result;
    }

    /**
     * The new prices change what the user's manual investment accounts were worth on past days, so their
     * balance history is rebuilt, whichever of the user's accounts the sync was limited to. This covers a
     * security another user's sync already refreshed today. A rebuild that fails is reported and does not
     * stop the others; the prices are already stored.
     *
     * @param  list<int>  $securityIds
     */
    private function rebuildHistory(User $user, array $securityIds): void
    {
        if ($securityIds === []) {
            return;
        }

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->where('type', AccountClass::Investment)
            ->where(fn ($query) => $query->whereNull('import_source')->orWhere('import_source', '!=', ImportSource::Redbark))
            ->whereIn('id', Trade::query()->whereIn('security_id', $securityIds)->select('account_id'))
            ->orderBy('id')
            ->get();

        foreach ($accounts as $account) {
            try {
                $this->history->rebuild($account);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function refreshedToday(Security $security): bool
    {
        return SecurityPrice::query()
            ->where('security_id', $security->id)
            ->where('updated_at', '>=', now()->startOfDay())
            ->exists();
    }

    private function recordError(Security $security, string $error): void
    {
        $security->forceFill(['last_error' => $error, 'last_error_at' => now()])->save();

        Log::warning('Security price sync recorded an error', ['security_id' => $security->id, 'error' => $error]);
    }
}
