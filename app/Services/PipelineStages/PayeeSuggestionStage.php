<?php

declare(strict_types=1);

namespace App\Services\PipelineStages;

use App\Contracts\PipelineStageContract;
use App\DTOs\PipelineContext;
use App\DTOs\StageResult;
use App\Exceptions\TypeSafe\TypeSafeException;
use App\Models\Payee;
use App\Models\PipelineAuditEntry;
use App\Services\Payees\PayeeSuggester;
use Illuminate\Contracts\Container\Container;

/**
 * Asks Jev about the merchants the user's rules did not settle.
 *
 * Runs only when TYPESAFE_API_KEY is configured and there is something to ask about.
 * A Jev failure is recorded as a skipped run and never fails the pipeline: the
 * merchants stay uncategorised and the next run asks again. The suggester is resolved
 * after the key check because the TypeSafe client cannot be built without a key.
 */
final readonly class PayeeSuggestionStage implements PipelineStageContract
{
    private const string STAGE_KEY = 'payee-suggestion';

    public function __construct(private Container $container) {}

    public function key(): string
    {
        return self::STAGE_KEY;
    }

    public function label(): string
    {
        return 'Payee Suggestions';
    }

    public function shouldRun(PipelineContext $context): bool
    {
        return (bool) config('budget.jev_categorisation')
            && filled(config('services.typesafe.api_key'))
            && $this->suggester()->hasCandidates($context->user);
    }

    public function execute(PipelineContext $context): StageResult
    {
        $before = $this->counts($context);

        try {
            $this->suggester()->suggestForUser($context->user);
        } catch (TypeSafeException $e) {
            $this->audit($context, 'skipped', [
                'reason' => 'typesafe_error',
                'exception' => $e::class,
                ...$this->delta($before, $this->counts($context)),
            ]);

            return new StageResult(success: true, stage: self::STAGE_KEY);
        }

        $this->audit($context, 'payees_suggested', $this->delta($before, $this->counts($context)));

        return new StageResult(success: true, stage: self::STAGE_KEY);
    }

    private function suggester(): PayeeSuggester
    {
        return $this->container->make(PayeeSuggester::class);
    }

    /**
     * @return array{suggested: int, auto_applied: int}
     */
    private function counts(PipelineContext $context): array
    {
        $payees = Payee::query()->where('user_id', $context->user->id);

        return [
            'suggested' => $payees->count(),
            'auto_applied' => $payees->where('auto_applied', true)->count(),
        ];
    }

    /**
     * @param  array{suggested: int, auto_applied: int}  $before
     * @param  array{suggested: int, auto_applied: int}  $after
     * @return array{suggested: int, auto_applied: int}
     */
    private function delta(array $before, array $after): array
    {
        return [
            'suggested' => $after['suggested'] - $before['suggested'],
            'auto_applied' => $after['auto_applied'] - $before['auto_applied'],
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(PipelineContext $context, string $action, array $metadata): void
    {
        PipelineAuditEntry::create([
            'pipeline_run_id' => $context->pipelineRun->id,
            'stage' => self::STAGE_KEY,
            'action' => $action,
            'metadata' => $metadata,
        ]);
    }
}
