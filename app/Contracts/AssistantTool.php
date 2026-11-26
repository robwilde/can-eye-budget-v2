<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;

interface AssistantTool
{
    public function name(): string;

    public function description(): string;

    /**
     * @return array<string, mixed> a JSON schema object for the arguments
     */
    public function parameters(): array;

    /**
     * Whether only users who opted in to preview features are offered this tool.
     */
    public function requiresPreview(): bool;

    /**
     * Runs for the given user and returns plain data for the model; money is integer cents. Every query must filter on that
     * user, and a refusal or bad argument is returned as ['error' => message], never thrown.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function handle(User $user, array $arguments): array;
}
