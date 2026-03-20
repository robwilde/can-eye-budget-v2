<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\BasiqServiceContract;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

final class RegisterBasiqWebhookCommand extends Command
{
    protected $signature = 'basiq:register-webhook
        {--url= : The webhook URL (defaults to APP_URL/webhooks/basiq)}';

    protected $description = 'Register a webhook with the Basiq API for real-time notifications';

    /**
     * @throws JsonException
     */
    public function handle(BasiqServiceContract $basiqService): int
    {
        $url = $this->option('url') ?? config('app.url').'/webhooks/basiq';

        $this->components->info("Registering webhook at: {$url}");

        try {
            $response = $basiqService->api()->post('/notifications/webhooks', [
                'url' => $url,
                'events' => [
                    'connection.created',
                    'transactions.updated',
                ],
            ])->json();
        } catch (Throwable $e) {
            $this->components->error("Failed to register webhook: {$e->getMessage()}");

            return self::FAILURE;
        }

        $secret = $response['secret'] ?? null;

        if ($secret) {
            $this->components->info('Webhook registered successfully.');
            $this->components->warn('Add this to your .env file:');
            $this->line("BASIQ_WEBHOOK_SECRET={$secret}");
        } else {
            $this->components->warn('Webhook registered but no secret was returned.');
            $this->components->info('Response: '.json_encode($response, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }

        return self::SUCCESS;
    }
}
