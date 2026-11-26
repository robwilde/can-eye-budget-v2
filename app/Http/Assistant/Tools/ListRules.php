<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Http\Resources\Api\RuleResource;
use App\Models\UserRule;

final class ListRules extends OwnedListTool
{
    public function name(): string
    {
        return 'list_rules';
    }

    public function description(): string
    {
        return 'Lists the user\'s categorisation rules, in the order they run, with their rule group.';
    }

    protected function model(): string
    {
        return UserRule::class;
    }

    protected function resource(): string
    {
        return RuleResource::class;
    }

    protected function order(): array
    {
        return ['order'];
    }

    protected function with(): array
    {
        return ['group'];
    }
}
