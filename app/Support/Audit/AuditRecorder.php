<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Enums\AuditOutcome;
use App\Models\AuditEvent;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Sentry\Breadcrumb;
use Throwable;

use function Sentry\addBreadcrumb;

final class AuditRecorder
{
    private const int MAX_NAME_LENGTH = 100;

    private const string NAME = '/\A[A-Za-z](?:[A-Za-z_.:-]|(?<!\d)\d{1,2}(?!\d)){0,99}\z/';

    private const string SUBJECT_ID = '/\A(?:\d{1,20}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-7][0-9A-HJKMNP-TV-Z]{25})\z/i';

    public static function accepts(string $value): bool
    {
        return mb_strlen($value) <= self::MAX_NAME_LENGTH && preg_match(self::NAME, $value) === 1;
    }

    public function record(
        string $action,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        AuditOutcome $outcome = AuditOutcome::Success,
        ?int $actorId = null,
    ): AuditEvent {
        $payload = $this->payload($action, $subjectType, $subjectId, $outcome, $actorId);

        $event = $this->storeRow($payload);
        $this->writeLog($payload);
        $this->addBreadcrumb($action, $outcome, $payload);

        return $event;
    }

    public function recordSafely(
        string $action,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        AuditOutcome $outcome = AuditOutcome::Success,
        ?int $actorId = null,
    ): void {
        try {
            $payload = $this->payload($action, $subjectType, $subjectId, $outcome, $actorId);
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        foreach ([
            fn () => $this->storeRow($payload),
            fn () => $this->writeLog($payload),
            fn () => $this->addBreadcrumb($action, $outcome, $payload),
        ] as $sink) {
            try {
                $sink();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * @return array{user_id: int|string|null, action: string, subject_type: string|null, subject_id: string|null, outcome: string, request_id: mixed}
     */
    private function payload(
        string $action,
        ?string $subjectType,
        int|string|null $subjectId,
        AuditOutcome $outcome,
        ?int $actorId,
    ): array {
        $subjectId = $subjectId === null ? null : (string) $subjectId;

        $this->assertName('action', $action);

        if ($subjectType !== null) {
            $this->assertName('subject type', $subjectType);
        }

        if ($subjectId !== null && preg_match(self::SUBJECT_ID, $subjectId) !== 1) {
            throw new InvalidArgumentException('Audit subject id must be an integer id, UUID or ULID.');
        }

        return [
            'user_id' => $actorId ?? Auth::id(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'outcome' => $outcome->value,
            'request_id' => Context::get('request_id'),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeRow(array $payload): AuditEvent
    {
        return AuditEvent::query()->create([...$payload, 'created_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeLog(array $payload): void
    {
        /** @var Logger $logger */
        $logger = Log::channel(config()->string('audit.log_channel'));
        $logger->getLogger()->info('audit', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function addBreadcrumb(string $action, AuditOutcome $outcome, array $payload): void
    {
        addBreadcrumb(new Breadcrumb(
            $outcome === AuditOutcome::Success ? Breadcrumb::LEVEL_INFO : Breadcrumb::LEVEL_WARNING,
            Breadcrumb::TYPE_DEFAULT,
            'audit',
            $action,
            $payload,
        ));
    }

    private function assertName(string $field, string $value): void
    {
        if (! self::accepts($value)) {
            throw new InvalidArgumentException("Audit {$field} must be a short name of letters, at most two consecutive digits and _ . : -.");
        }
    }
}
