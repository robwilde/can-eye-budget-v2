<?php

declare(strict_types=1);

namespace App\Support\Sentry;

use Sentry\Event;

/**
 * Keeps the private calendar feed's token out of Sentry: the token lives in the request path, and Sentry sends
 * the request URL with every event even when default PII is off.
 */
final class FeedTokenRedactor
{
    public const string PATTERN = '#(/bills/feed/)[A-Za-z0-9]{40}(\.ics)#';

    public static function redact(Event $event): Event
    {
        $request = $event->getRequest();

        foreach (['url', 'query_string'] as $key) {
            if (isset($request[$key]) && is_string($request[$key])) {
                $request[$key] = preg_replace(self::PATTERN, '$1[redacted]$2', $request[$key]) ?? '';
            }
        }

        $event->setRequest($request);

        return $event;
    }
}
