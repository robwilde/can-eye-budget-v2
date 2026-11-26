<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Exceptions\BudgetPlanRefusedException;
use App\Exceptions\TransactionWriteRefusedException;
use App\Exceptions\TransferLinkRefusedException;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Shared plumbing for the write tools: a refusal or a record that is not the user's comes back as an error result
 * ("not found" never says whether the record exists for someone else), and each successful write is audited by tool name.
 */
trait WritesUserData
{
    use ReadsUserData;

    /**
     * @param  Closure(): array<string, mixed>  $work
     * @return array<string, mixed>
     */
    private function attempt(Closure $work): array
    {
        try {
            return $work();
        } catch (ModelNotFoundException) {
            return ['error' => 'Not found.'];
        } catch (TransactionWriteRefusedException|BudgetPlanRefusedException|TransferLinkRefusedException $refused) {
            return ['error' => $refused->getMessage()];
        } catch (ValidationException $invalid) {
            return ['error' => 'Invalid arguments: '.implode(' ', $invalid->validator->errors()->all())];
        }
    }

    private function audited(User $user, string $subjectType, int|string|null $subjectId): void
    {
        app(AuditRecorder::class)->recordSafely('assistant.'.$this->name(), $subjectType, $subjectId, actorId: $user->id);
    }
}
