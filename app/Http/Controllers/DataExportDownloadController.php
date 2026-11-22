<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DataExportStatus;
use App\Models\DataExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DataExportDownloadController extends Controller
{
    public function __invoke(Request $request, int $export): StreamedResponse
    {
        $found = DataExport::query()
            ->where('user_id', $request->user()->id)
            ->where('status', DataExportStatus::Completed)
            ->findOrFail($export);

        $disk = Storage::disk('local');

        abort_unless($found->stored_path !== null && $disk->exists($found->stored_path), 404);

        return $disk->download($found->stored_path, 'can-i-budget-export-'.$found->created_at->format('Y-m-d').'.zip');
    }
}
