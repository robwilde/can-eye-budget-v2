<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SettingsController extends OwnedReadController
{
    public function show(Request $request): JsonResponse
    {
        $user = $this->owner($request);

        return response()->json(['data' => [
            'base_currency' => $user->base_currency,
            'timezone' => $user->timezone,
            'month_start_day' => $user->month_start_day,
            'preferences' => $user->preferences->toArray(),
        ]]);
    }
}
