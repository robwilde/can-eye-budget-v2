<?php

declare(strict_types=1);

namespace App\Enums;

enum BnplOrderStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case AutoApproved = 'auto_approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending review',
            self::Approved => 'Approved',
            self::AutoApproved => 'Auto-approved',
            self::Rejected => 'Rejected',
        };
    }

    public function isApproved(): bool
    {
        return $this === self::Approved || $this === self::AutoApproved;
    }
}
