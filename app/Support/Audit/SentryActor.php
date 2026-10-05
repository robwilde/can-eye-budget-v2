<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Illuminate\Support\Facades\Context;
use Sentry\State\Scope;

use function Sentry\configureScope;

final class SentryActor
{
    public function bind(?int $userId): void
    {
        $requestId = Context::get('request_id');

        configureScope(static function (Scope $scope) use ($userId, $requestId): void {
            if ($userId === null) {
                $scope->removeUser();
            } else {
                $scope->setUser(['id' => (string) $userId]);
            }

            if (is_string($requestId)) {
                $scope->setTag('request_id', $requestId);
            }
        });
    }
}
