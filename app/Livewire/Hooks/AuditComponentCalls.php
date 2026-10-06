<?php

declare(strict_types=1);

namespace App\Livewire\Hooks;

use App\Enums\AuditOutcome;
use App\Livewire\Attributes\NotAudited;
use App\Support\Audit\AuditRecorder;
use Closure;
use Illuminate\Support\MessageBag;
use Livewire\Component;
use Livewire\ComponentHook;
use Livewire\Drawer\Utils;
use Livewire\Features\SupportEvents\SupportEvents;
use Livewire\Mechanisms\HandleComponents\ComponentContext;
use ReflectionException;
use ReflectionMethod;
use Throwable;

final class AuditComponentCalls extends ComponentHook
{
    private ?string $action = null;

    private bool $active = false;

    private ?MessageBag $errorsBefore = null;

    private int $errorCountBefore = 0;

    /**
     * The audit action a call to the given component method records, or null when the call is not audited.
     *
     * @throws ReflectionException
     */
    public static function auditedAction(Component $component, string $method): ?string
    {
        if (str_starts_with($method, '_')
            || ! AuditRecorder::accepts($method)
            || ! in_array($method, Utils::getPublicMethodsDefinedBySubClass($component), true)
            || new ReflectionMethod($component, $method)->getAttributes(NotAudited::class) !== []) {
            return null;
        }

        $action = 'livewire.'.$component->getName().'.'.$method;

        return AuditRecorder::accepts($action) ? $action : null;
    }

    /**
     * @param  array<int|string, mixed>  $params
     * @param  array<string, mixed>  $metadata
     *
     * @throws ReflectionException
     */
    public function call(string $method, array $params, Closure $returnEarly, array $metadata, ComponentContext $componentContext): ?Closure
    {
        $resolved = $method === '__dispatch' ? $this->listenerMethod($params) : $method;

        $this->action = $resolved === null ? null : self::auditedAction($this->component, $resolved);
        $this->active = $this->action !== null;

        if (! $this->active) {
            return null;
        }

        $this->errorsBefore = $this->component->getErrorBag();
        $this->errorCountBefore = $this->errorsBefore->count();

        return function (): void {
            $this->finish($this->addedErrors() ? AuditOutcome::Failure : AuditOutcome::Success);
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

    private function addedErrors(): bool
    {
        $errors = $this->component->getErrorBag();

        return $errors === $this->errorsBefore
            ? $errors->count() > $this->errorCountBefore
            : $errors->isNotEmpty();
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

    private function record(AuditOutcome $outcome): void
    {
        if ($this->action === null) {
            return;
        }

        app(AuditRecorder::class)->recordSafely(action: $this->action, outcome: $outcome);
    }
}
