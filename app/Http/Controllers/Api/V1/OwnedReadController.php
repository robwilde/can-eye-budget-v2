<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;

abstract class OwnedReadController
{
    public const int DEFAULT_PER_PAGE = 50;

    public const int MAX_PER_PAGE = 100;

    protected function owner(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }

    protected function perPage(Request $request): int
    {
        $validated = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE]]);

        return (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE);
    }
}
