<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TransactionAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TransactionAttachmentController extends Controller
{
    public function __invoke(Request $request, int $attachment): StreamedResponse
    {
        $found = TransactionAttachment::query()
            ->forUser($request->user()->id)
            ->findOrFail($attachment);

        $disk = Storage::disk($found->disk);

        abort_unless($disk->exists($found->path), 404);

        return $disk->download($found->path, $found->original_name);
    }
}
