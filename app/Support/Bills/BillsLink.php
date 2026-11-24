<?php

declare(strict_types=1);

namespace App\Support\Bills;

use App\Models\User;
use App\Services\Recurring\RecurringGate;

/** Where an alert email sends the user: the Bills page when it exists for them, otherwise the dashboard. */
final class BillsLink
{
    public static function for(object $notifiable): string
    {
        return config('budget.bills_page') && $notifiable instanceof User && RecurringGate::enabled($notifiable) ? route('bills') : route('dashboard');
    }
}
