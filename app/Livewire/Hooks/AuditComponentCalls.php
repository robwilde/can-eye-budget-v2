<?php

declare(strict_types=1);

namespace App\Livewire\Hooks;

use App\Enums\AuditOutcome;
use App\Livewire\Attributes\NotAudited;
use App\Support\Audit\AuditRecorder;
use Closure;
use Livewire\ComponentHook;
use Livewire\Drawer\Utils;
use Livewire\Features\SupportEvents\SupportEvents;
use Livewire\Mechanisms\HandleComponents\ComponentContext;
use ReflectionMethod;
use Throwable;

final class AuditComponentCalls extends ComponentHook
{
    private ?string $method = null;

    private bool $active = false;

    /**
     * @param  array<int|string, mixed>  $params
     * @param  array<string, mixed>  $metadata
     */
    public function call(string $method, array $params, Closure $returnEarly, array $metadata, ComponentContext $componentContext): ?Closure
    {
        $resolved = $method === '__dispatch' ? $this->listenerMethod($params) : $method;

        $this->method = $resolved !== null && $this->isAuditable($resolved) ? $resolved : null;
        $this->active = $this->method !== null;

        if (! $this->active) {
            return null;
        }

        return function (): void {
            $this->finish(AuditOutcome::Success);
        };
    }

    public function exception(Throwable $exception, Closure $stopPropagation): void
    {
        $this->finish(AuditOutcome::Failure);
    }

    private function finish(AuditOutcome $outcome): void
    {
        if (! $this->active) {
            return;
        }

        $this->active = false;
        $this->record($outcome);
    }

    /**
     * @param  array<int|string, mixed>  $params
     */
    private function listenerMethod(array $params): ?string
    {
        $event = $params[0] ?? null;

        if (! is_string($event)) {
            return null;
        }

        foreach (SupportEvents::getComponentListeners($this->component) as $name => $handler) {
            if ((is_int($name) ? $handler : $name) === $event) {
                return is_string($handler) ? $handler : null;
            }
        }

        return null;
    }

    private function isAuditable(string $method): bool
    {
        return ! str_starts_with($method, '_')
            && AuditRecorder::accepts($method)
            && in_array($method, Utils::getPublicMethodsDefinedBySubClass($this->component), true)
            && (new ReflectionMethod($this->component, $method))->getAttributes(NotAudited::class) === [];
    }

    private function record(AuditOutcome $outcome): void
    {
        if ($this->method === null) {
            return;
        }

        $action = 'livewire.'.$this->component->getName().'.'.$this->method;

        if (! AuditRecorder::accepts($action)) {
            return;
        }

        app(AuditRecorder::class)->recordSafely(action: $action, outcome: $outcome);
    }
}
