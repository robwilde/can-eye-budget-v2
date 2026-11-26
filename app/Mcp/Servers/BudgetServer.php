<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Contracts\AssistantWriteTool;
use App\Mcp\Tools\AssistantToolAdapter;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\Assistant\AssistantToolRegistry;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Can I Budget')]
#[Version('1.0.0')]
#[Instructions('Read and manage your own budget: accounts, transactions, categories, tags, rules and budgets. Money is integer cents. Tools that change data need a read-write API key.')]
final class BudgetServer extends Server
{
    public int $defaultPaginationLength = 50;

    protected function boot(): void
    {
        $user = request()->user();
        $key = $user?->currentAccessToken();

        if (! $user instanceof User || ! $key instanceof ApiKey) {
            return;
        }

        $mayWrite = $key->allowsWrites();

        foreach (app(AssistantToolRegistry::class)->forUser($user) as $tool) {
            if ($tool instanceof AssistantWriteTool && ! $mayWrite) {
                continue;
            }

            $this->tools[] = new AssistantToolAdapter($tool, $user, $mayWrite);
        }
    }
}
