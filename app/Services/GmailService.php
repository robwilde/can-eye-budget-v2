<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\GmailServiceContract;
use App\DTOs\EmailSearchResult;
use App\Exceptions\GmailSearchException;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Webklex\IMAP\Facades\Client;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Client as ImapClient;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Support\MessageCollection;

final class GmailService implements GmailServiceContract
{
    private const int MAX_RESULTS = 10;

    private const int DATE_WINDOW_DAYS = 7;

    /**
     * Payment-processor / BNPL signatures. When a description matches one of
     * these keywords the bank line names the processor, not the payee, so the
     * receipt email arrives *from* the processor — searching `from:<sender>`
     * finds it where the leftover description token (e.g. "PAYIN4") never would.
     *
     * @var array<string, string>
     */
    private const array PROVIDER_SENDERS = [
        'afterpay' => 'afterpay',
        'paypal' => 'paypal',
        'pypl' => 'paypal',
        'payin4' => 'paypal',
        'klarna' => 'klarna',
        'zippay' => 'zip',
        'zip.co' => 'zip',
        'humm' => 'humm',
    ];

    /**
     * Gmail folders searched, in priority order. All Mail covers archived
     * receipts; INBOX is the fallback for non-English locales / hidden folders.
     *
     * @var list<string>
     */
    private const array FOLDER_PATHS = ['[Gmail]/All Mail', 'INBOX'];

    public function __construct(private readonly CategoryRuleGenerator $ruleGenerator) {}

    public function isConfigured(): bool
    {
        return (string) config('imap.accounts.gmail.username') !== ''
            && (string) config('imap.accounts.gmail.password') !== '';
    }

    /**
     * Build the Gmail X-GM-RAW search string for a transaction. Public so the
     * exact query is unit-testable. The value is wrapped in double quotes by
     * the IMAP library, so it must never itself contain a double quote.
     */
    public function buildQuery(Transaction $transaction, bool $withAmount = true): string
    {
        $parts = [$this->searchSubject($transaction)];

        if ($withAmount) {
            $parts[] = number_format(abs($transaction->amount) / 100, 2, '.', '');
        }

        $postDate = $transaction->post_date;
        $parts[] = 'after:'.$postDate->subDays(self::DATE_WINDOW_DAYS)->format('Y/m/d');
        $parts[] = 'before:'.$postDate->addDays(self::DATE_WINDOW_DAYS + 1)->format('Y/m/d');

        return implode(' ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    public function searchForTransaction(Transaction $transaction): Collection
    {
        if (! $this->isConfigured()) {
            throw GmailSearchException::notConfigured();
        }

        $client = Client::account('gmail');

        try {
            $client->connect();
            $folder = $this->resolveFolder($client);

            $messages = $this->runQuery($folder, $this->buildQuery($transaction));

            if ($messages->isEmpty()) {
                $messages = $this->runQuery($folder, $this->buildQuery($transaction, withAmount: false));
            }

            return $this->rank($messages, $transaction->post_date);
        } catch (Throwable $e) {
            throw GmailSearchException::wrap($e);
        } finally {
            $client->disconnect();
        }
    }

    private function resolveFolder(ImapClient $client): Folder
    {
        foreach (self::FOLDER_PATHS as $path) {
            try {
                $folder = $client->getFolderByPath($path);

                if ($folder instanceof Folder) {
                    return $folder;
                }
            } catch (Throwable) {
                // Try the next candidate folder.
            }
        }

        throw new RuntimeException('No searchable Gmail folder found ('.implode(', ', self::FOLDER_PATHS).').');
    }

    private function runQuery(Folder $folder, string $query): MessageCollection
    {
        return $folder->query()
            ->where('CUSTOM X-GM-RAW', $query)
            ->limit(self::MAX_RESULTS)
            ->get();
    }

    /**
     * @return Collection<int, EmailSearchResult>
     */
    private function rank(MessageCollection $messages, CarbonImmutable $postDate): Collection
    {
        return $messages
            ->map(fn (Message $message): ?array => $this->mapMessage($message))
            ->filter()
            ->map(static function (array $row) use ($postDate): array {
                $row['proximity'] = $row['date'] instanceof CarbonImmutable
                    ? abs($row['date']->diffInSeconds($postDate))
                    : PHP_INT_MAX;

                return $row;
            })
            ->sortBy('proximity')
            ->map(static fn (array $row): EmailSearchResult => $row['result'])
            ->values();
    }

    /**
     * @return array{result: EmailSearchResult, date: ?CarbonImmutable}|null
     */
    private function mapMessage(Message $message): ?array
    {
        $messageId = mb_trim((string) $message->getMessageId(), "<> \t\n\r\0\x0B");

        if ($messageId === '') {
            return null;
        }

        $from = $message->getFrom()->first();
        $fromName = $from instanceof Address && $from->personal !== '' ? $from->personal : null;
        $fromAddress = $from instanceof Address ? $from->mail : '';

        $date = $this->messageDate($message);

        return [
            'result' => new EmailSearchResult(
                messageId: $messageId,
                subject: (string) $message->getSubject(),
                fromName: $fromName,
                fromAddress: $fromAddress,
                date: $date?->toIso8601String(),
                snippet: $this->snippet($message),
                gmailUrl: 'https://mail.google.com/mail/u/0/#search/rfc822msgid:'.rawurlencode($messageId),
            ),
            'date' => $date,
        ];
    }

    private function messageDate(Message $message): ?CarbonImmutable
    {
        $raw = $message->getDate()->first();

        if ($raw instanceof CarbonInterface) {
            return CarbonImmutable::instance($raw);
        }

        return null;
    }

    private function snippet(Message $message): ?string
    {
        $body = $message->getTextBody();

        if ($body === '') {
            $body = strip_tags($message->getHTMLBody());
        }

        $body = mb_trim((string) preg_replace('/\s+/', ' ', $body));

        return $body === '' ? null : Str::limit($body, 200);
    }

    /**
     * The primary search term: a `from:<sender>` filter when the description
     * names a payment processor, otherwise the sanitised merchant token. The
     * value is folded into the double-quote-wrapped X-GM-RAW string, so it
     * must never contain a double quote.
     */
    private function searchSubject(Transaction $transaction): string
    {
        $sender = $this->providerSender($transaction->description);

        if ($sender !== null) {
            return $sender;
        }

        $merchant = str_replace('"', ' ', $this->ruleGenerator->suggestMatchValue($transaction));

        return mb_trim((string) preg_replace('/\s+/', ' ', $merchant));
    }

    private function providerSender(string $description): ?string
    {
        $haystack = mb_strtolower($description);

        foreach (self::PROVIDER_SENDERS as $needle => $sender) {
            if (str_contains($haystack, $needle)) {
                return 'from:'.$sender;
            }
        }

        return null;
    }
}
