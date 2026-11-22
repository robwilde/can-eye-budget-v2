<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DataExportStatus;
use App\Models\DataExport;
use App\Services\Export\DataExportBuilder;
use App\Support\Audit\AttributeJobToUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Writes the zip of one user's data for a pending DataExport. A redelivered job never restarts an
 * export that already left pending, so a finished archive is never rewritten.
 */
final class GenerateDataExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly DataExport $export) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new AttributeJobToUser($this->export->user_id, audit: true)];
    }

    public function handle(DataExportBuilder $builder): void
    {
        $claimed = DataExport::query()
            ->whereKey($this->export->id)
            ->where('status', DataExportStatus::Pending)
            ->update(['status' => DataExportStatus::Processing, 'started_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $export = DataExport::query()->findOrFail($this->export->id);
        $directory = sys_get_temp_dir().'/data-export-'.$export->id.'-'.bin2hex(random_bytes(4));
        mkdir($directory);

        try {
            $zip = $builder->build($export->user, $directory);
            $path = 'exports/'.$export->user_id.'/'.$export->id.'.zip';

            $stream = fopen($zip, 'rb');

            if (! is_resource($stream) || Storage::disk('local')->writeStream($path, $stream) === false) {
                throw new RuntimeException('The archive could not be stored.');
            }

            fclose($stream);

            $export->update([
                'status' => DataExportStatus::Completed,
                'stored_path' => $path,
                'byte_size' => (int) filesize($zip),
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $export->update(['status' => DataExportStatus::Failed, 'failure_message' => 'The export could not be written.', 'completed_at' => now()]);
        } finally {
            array_map('unlink', glob($directory.'/*') ?: []);
            rmdir($directory);
        }
    }
}
