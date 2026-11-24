<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Bills\CalendarFeedBuilder;
use App\Services\Bills\CalendarFeedTokens;
use App\Services\Recurring\RecurringGate;
use App\Support\Sentry\FeedTokenRedactor;
use Illuminate\Http\Response;
use Sentry\State\Scope;

use function Sentry\configureScope;

/**
 * The private bills calendar feed. Anything that should not exist answers the same not-found: the flag off,
 * recurring off, a malformed or unknown token.
 */
final class BillsFeedController extends Controller
{
    public function __invoke(string $token, CalendarFeedTokens $tokens, CalendarFeedBuilder $builder): Response
    {
        if (function_exists('Sentry\\configureScope')) {
            configureScope(static fn (Scope $scope) => $scope->addEventProcessor(FeedTokenRedactor::redact(...)));
        }

        abort_unless((bool) config('budget.calendar_feed'), 404);

        $user = $tokens->find($token);

        abort_unless($user !== null && RecurringGate::enabled($user), 404);

        return response($builder->build($user), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="bills.ics"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
