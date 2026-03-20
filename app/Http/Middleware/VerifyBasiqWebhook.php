<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyBasiqWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $webhookId = $request->header('webhook-id');
        $timestamp = $request->header('webhook-timestamp');
        $signature = $request->header('webhook-signature');

        if (! $webhookId || ! $timestamp || ! $signature) {
            abort(403, 'Missing webhook verification headers.');
        }

        if (! ctype_digit($timestamp)) {
            abort(403, 'Invalid webhook timestamp.');
        }

        if (abs(time() - (int) $timestamp) > 300) {
            abort(403, 'Webhook timestamp expired.');
        }

        $secret = config('services.basiq.webhook_secret', '');

        if (empty($secret)) {
            abort(500, 'Webhook secret is not configured.');
        }

        $secretBytes = base64_decode(str_replace('whsec_', '', $secret), strict: true);

        if ($secretBytes === false) {
            abort(500, 'Webhook secret is invalid.');
        }

        $signedContent = "{$webhookId}.{$timestamp}.{$request->getContent()}";

        $computedSignature = base64_encode(
            hash_hmac('sha256', $signedContent, $secretBytes, binary: true)
        );

        $signatures = explode(' ', $signature);

        foreach ($signatures as $candidate) {
            $candidateValue = mb_trim(preg_replace('/^v\d+,/', '', $candidate));

            if (hash_equals($computedSignature, $candidateValue)) {
                return $next($request);
            }
        }

        abort(403, 'Invalid webhook signature.');
    }
}
