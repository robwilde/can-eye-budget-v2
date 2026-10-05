<?php

declare(strict_types=1);

return [
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),

    'log_channel' => env('AUDIT_LOG_CHANNEL', 'audit'),
];
