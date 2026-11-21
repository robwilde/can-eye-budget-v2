<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PdfImport;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the user a statement PDF has been read and what to do next.
 */
final class PdfImportReadyNotification extends Notification
{
    public function __construct(public readonly PdfImport $import) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lines = (int) ($this->import->summary['lines_found'] ?? 0);
        $name = $this->import->statement->original_filename ?? __('your statement');

        $message = new MailMessage()->subject(__('Your statement has been read'));

        if ($lines === 0) {
            return $message
                ->line(__(':name was read, but it holds no transactions to import.', ['name' => $name]))
                ->action(__('Open your statements'), route('statements'));
        }

        return $message
            ->line(trans_choice(':name was read: :count transaction was found.|:name was read: :count transactions were found.', $lines, ['name' => $name]))
            ->line(__('Review them, choose the account and publish to add them to your ledger.'))
            ->action(__('Open your statements'), route('statements'));
    }
}
