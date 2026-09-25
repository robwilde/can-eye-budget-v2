<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ContextDevServiceContract;
use ContextDev\Core\Exceptions\APIStatusException;
use ContextDev\Core\Exceptions\ContextDevException;
use Illuminate\Console\Command;

/**
 * Resolves one bank/card descriptor to a merchant brand via Context.dev.
 *
 * Each run is one billable call (10 credits), so this is an operator tool for checking
 * how a descriptor resolves — not something to loop over the transactions table.
 */
final class ResolveMerchantBrandCommand extends Command
{
    protected $signature = 'app:resolve-merchant-brand
        {descriptor : Raw transaction descriptor, exactly as the bank feed supplied it}
        {--country= : Country code hint, e.g. au (only if the feed supplied it)}
        {--city= : City hint (only if the feed supplied it)}
        {--mcc= : Merchant Category Code (only if the feed supplied it)}';

    protected $description = 'Resolve a transaction descriptor to a merchant brand via Context.dev (10 credits)';

    public function handle(ContextDevServiceContract $contextDev): int
    {
        $descriptor = (string) $this->argument('descriptor');

        try {
            $merchant = $contextDev->brandFromTransaction(
                descriptor: $descriptor,
                countryCode: $this->stringOption('country'),
                city: $this->stringOption('city'),
                mcc: $this->stringOption('mcc'),
            );
        } catch (ContextDevException $e) {
            $status = $e instanceof APIStatusException && $e->status !== null ? " (HTTP {$e->status})" : '';
            $this->error("Context.dev lookup failed{$status}: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($merchant === null) {
            $this->warn("Unresolved: no confident merchant match for \"{$descriptor}\".");

            return self::SUCCESS;
        }

        $this->table(['Field', 'Value'], [
            ['Descriptor', $descriptor],
            ['Merchant', $merchant->title],
            ['Domain', $merchant->domain ?? '—'],
            ['Industry', $merchant->industry ?? '—'],
            ['Subindustry', $merchant->subindustry ?? '—'],
            ['Logo', $merchant->logoUrl ?? '—'],
            ['Partial', $merchant->partial ? 'yes' : 'no'],
        ]);

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
