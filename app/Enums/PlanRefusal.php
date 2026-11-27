<?php

declare(strict_types=1);

namespace App\Enums;

use Symfony\Component\HttpFoundation\Response;

/**
 * Why a plan write was refused. The writer decides; each caller picks the field or toast it shows
 * the message on, and the API turns the status into its response code.
 */
enum PlanRefusal: string
{
    case Duplicate = 'duplicate';
    case PayCycleIncome = 'pay-cycle-income';
    case RecurringOff = 'recurring-off';
    case UntrackedAccount = 'untracked-account';
    case HiddenCategory = 'hidden-category';
    case StartNotAfterToday = 'start-not-after-today';

    public function message(): string
    {
        return match ($this) {
            self::Duplicate => __('A plan like this already exists.'),
            self::PayCycleIncome => __('Pay cycle income is managed elsewhere'),
            self::RecurringOff => __('Recurring is switched off. Switch it on in Settings → Recurring.'),
            self::UntrackedAccount => __('Pick one of your tracked accounts.'),
            self::HiddenCategory => __('Pick a category that is not hidden.'),
            self::StartNotAfterToday => __('Planned transactions start after today.'),
        };
    }

    public function status(): int
    {
        return match ($this) {
            self::Duplicate => Response::HTTP_CONFLICT,
            self::PayCycleIncome => Response::HTTP_FORBIDDEN,
            self::RecurringOff => Response::HTTP_NOT_FOUND,
            self::UntrackedAccount, self::HiddenCategory, self::StartNotAfterToday => Response::HTTP_UNPROCESSABLE_ENTITY,
        };
    }
}
