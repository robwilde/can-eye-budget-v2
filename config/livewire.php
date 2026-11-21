<?php

declare(strict_types=1);

use App\Services\Statement\StatementUploadService;

return [

    /*
    |---------------------------------------------------------------------------
    | Temporary file uploads
    |---------------------------------------------------------------------------
    |
    | Livewire validates every file against these rules before a component sees
    | it, and its default caps files at 12 MB. The statement vault accepts up to
    | StatementUploadService::MAX_BYTES, so the cap follows that limit. Components
    | that take smaller files still validate their own size.
    |
    */

    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        'rules' => ['required', 'file', 'max:'.intdiv(StatementUploadService::MAX_BYTES, 1024)],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];
