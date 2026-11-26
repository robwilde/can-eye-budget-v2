<?php

declare(strict_types=1);

namespace App\Contracts;

interface AssistantTool
{
    public function name(): string;

    public function description(): string;

    /**
     * @return array<string, mixed> a JSON schema object for the arguments
     */
    public function parameters(): array;

    /**
     * Runs for the user the tool was built for and returns text for the model. Must only touch that user's data.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments): string;
}
