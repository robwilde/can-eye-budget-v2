<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\InsightType;
use App\Models\User;

interface InsightGenerator
{
    public function key(): string;

    /**
     * @return list<InsightType>
     */
    public function types(): array;

    /**
     * Records this generator's insights for the user and returns their dedup keys.
     *
     * @return list<string>
     */
    public function generate(User $user): array;
}
