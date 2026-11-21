<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PipelineRunStatus;
use App\Enums\PipelineTrigger;
use App\Enums\RuleActionType;
use App\Exceptions\RuleBaselineTimedOutException;
use App\Models\PipelineAuditEntry;
use App\Models\PipelineRun;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\RuleEmailBaseline;
use App\Services\RuleFieldClaims;
use App\Services\UserRuleApplier;
use App\Support\Audit\AttributeJobToUser;
use App\Support\Rules\RuleRunScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies a user's rules on demand, off the request. With a rule id it runs the active rules above
 * that rule first, sharing one set of field claims so the result equals a full run, then the rule
 * itself; rules below it do not run, and an inactive rule is switched on. Without one it runs
 * every active rule in order.
 *
 * Each run is a Manual PipelineRun with a processed and a blocked audit entry per rule. The rule
 * being applied replaces hand-set values, as a user-initiated re-apply does; the rules above it
 * respect them, as a sync does. A rule that throws is recorded and is not retried.
 */
final class ApplyUserRulesJob implements ShouldQueue
{
    use Queueable;

    public const string STAGE_KEY = 'apply-user-rules';

    private const int OVERLAP_TRIES = 30;

    private const int OVERLAP_RELEASE_SECONDS = 30;

    public int $tries = self::OVERLAP_TRIES;

    public int $maxExceptions = 1;

    public int $timeout = 600;

    public function __construct(public readonly User $user, public readonly ?int $ruleId = null) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            new AttributeJobToUser($this->user->id),
            new WithoutOverlapping("analysis-user-{$this->user->id}")->expireAfter(600)->releaseAfter(self::OVERLAP_RELEASE_SECONDS),
        ];
    }

    public function handle(UserRuleApplier $applier): void
    {
        $target = null;

        if ($this->ruleId !== null) {
            $target = UserRule::query()->where('user_id', $this->user->id)->find($this->ruleId);

            if (! $target instanceof UserRule) {
                return;
            }
        }

        $run = PipelineRun::create([
            'user_id' => $this->user->id,
            'trigger' => PipelineTrigger::Manual,
            'status' => PipelineRunStatus::Running,
            'is_first_sync' => false,
            'stages_completed' => [],
            'stages_skipped' => [],
            'stages_failed' => [],
            'started_at' => now(),
        ]);

        if ($target instanceof UserRule && ! $target->is_active) {
            try {
                $this->switchOn($target);
            } catch (RuleBaselineTimedOutException) {
                $this->record($run, 'baseline_timed_out', $target, []);
                $run->update([
                    'status' => PipelineRunStatus::Failed,
                    'stages_failed' => [['stage' => self::STAGE_KEY, 'error' => RuleBaselineTimedOutException::class]],
                    'completed_at' => now(),
                ]);

                return;
            }
        }

        $claims = new RuleFieldClaims;
        $scope = new RuleRunScope;
        $openGroup = null;
        $failed = [];
        $targetFailed = false;

        $rules = $this->rulesToRun($target);

        foreach ($rules as $rule) {
            $isTarget = $target instanceof UserRule && $rule->id === $target->id;

            if ($openGroup !== null && $openGroup->id !== $rule->user_rule_group_id) {
                $scope->closeGroup($openGroup->stop_processing);
            }

            $openGroup = $rule->group;

            try {
                if (! $isTarget && ! $rule->is_auto_apply) {
                    $applier->observe($rule, $scope);

                    continue;
                }

                $result = $applier->apply($rule, overrideLocks: $isTarget, claims: $claims, scope: $scope);

                $this->record($run, 'processed', $rule, ['processed' => $result->processed, 'modified' => $result->modified]);
                $this->record($run, 'blocked', $rule, ['count' => $result->blocked()]);
            } catch (Throwable $exception) {
                report($exception);

                $failed[] = ['stage' => self::STAGE_KEY, 'error' => $exception::class];
                $targetFailed = $targetFailed || $isTarget;
                $this->record($run, 'rule_failed', $rule, ['exception' => $exception::class]);
            }
        }

        $run->update([
            'status' => match (true) {
                $targetFailed || ($failed !== [] && count($failed) === count($rules)) => PipelineRunStatus::Failed,
                $failed !== [] => PipelineRunStatus::PartialFailure,
                default => PipelineRunStatus::Completed,
            },
            'stages_completed' => $failed === [] ? [self::STAGE_KEY] : [],
            'stages_failed' => $failed,
            'completed_at' => now(),
        ]);
    }

    /**
     * Applying a rule switches it on. The rule row is locked and, for an email rule, its baseline is
     * recorded in the same transaction as UserRuleManager does, so applying an inactive email rule
     * never emails its backlog. A baseline that times out rolls the activation back: the rule stays
     * off and the run fails without running anything.
     *
     * @throws RuleBaselineTimedOutException
     */
    private function switchOn(UserRule $rule): void
    {
        DB::transaction(function () use ($rule): void {
            $locked = UserRule::query()->where('user_id', $this->user->id)->whereKey($rule->id)->lockForUpdate()->firstOrFail();
            $locked->update(['is_active' => true]);

            if ($locked->hasAction(RuleActionType::EmailDigest)) {
                app(RuleEmailBaseline::class)->record($locked);
            }

            $rule->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * The rules to run, in priority order: every active rule above the target in an active group,
     * then the target; or every active rule when there is no target. Rules other than the target
     * act only when auto-apply, as in a sync; the rest are only observed for stop-processing.
     *
     * @return list<UserRule>
     */
    private function rulesToRun(?UserRule $target): array
    {
        $rules = [];

        $groups = UserRuleGroup::query()
            ->where('user_id', $this->user->id)
            ->ordered()
            ->with(['rules' => fn ($query) => $query->ordered()->orderBy('id')])
            ->orderBy('id')
            ->get();

        foreach ($groups as $group) {
            foreach ($group->rules as $rule) {
                if ($target instanceof UserRule && $rule->id === $target->id) {
                    $rule->setRelation('group', $group);
                    $rules[] = $rule;

                    return $rules;
                }

                if ($group->is_active && $rule->is_active) {
                    $rule->setRelation('group', $group);
                    $rules[] = $rule;
                }
            }
        }

        return $rules;
    }

    /**
     * @param  array<string, int|string>  $metadata
     */
    private function record(PipelineRun $run, string $action, UserRule $rule, array $metadata): void
    {
        PipelineAuditEntry::create([
            'pipeline_run_id' => $run->id,
            'stage' => self::STAGE_KEY,
            'action' => $action,
            'metadata' => ['rule_id' => $rule->id, 'rule_name' => $rule->name, ...$metadata],
        ]);
    }
}
