<?php

declare(strict_types=1);

namespace App\Enums;

enum BnplOrderEventType: string
{
    case EmailPulled = 'email_pulled';
    case PlanCreated = 'plan_created';
    case ReviewRequested = 'review_requested';
    case Approved = 'approved';
    case AutoApproved = 'auto_approved';
    case CategorySet = 'category_set';
    case Rejected = 'rejected';
}
