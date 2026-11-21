<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Statement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StatementDownloadController extends Controller
{
    public function __invoke(Request $request, int $statement): StreamedResponse
    {
        $found = Statement::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($statement);

        $disk = Storage::disk(Statement::DISK);

        abort_unless($disk->exists($found->stored_path), 404);

        return $disk->download($found->stored_path, $found->original_filename);
    }
}
