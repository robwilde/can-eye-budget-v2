<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\GmailServiceContract;
use App\DTOs\EmailSearchResult;
use App\Exceptions\GmailSearchException;
use App\Models\Transaction;
use App\Support\Email\ReceiptParser;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Webklex\IMAP\Facades\Client;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Client as ImapClient;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\GetMessagesFailedException;
use Webklex\PHPIMAP\Exceptions\ImapBadRequestException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Exceptions\InvalidWhereQueryCriteriaException;
use Webklex\PHPIMAP\Exceptions\ResponseException;
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

    /**
     * Build a readable snippet from an email's text and HTML bodies. Flattens
     * the bodies via ReceiptParser (plain-text preferred; HTML has
     * style/script/head blocks dropped whole before tags are stripped, so CSS
     * such as @font-face rules never leaks in). Leads with the seller and any
     * payment-schedule amounts (the parts a BNPL receipt is linked for),
     * followed by a short lead of body text for context.
     */
    public static function snippetFromBodies(?string $textBody, ?string $htmlBody): ?string
    {
        $body = ReceiptParser::flatten($textBody, $htmlBody);

        if ($body === '') {
            return null;
        }

        $highlights = self::paymentHighlights($body);
        $lead = Str::limit($body, 180);

        return $highlights === [] ? $lead : implode(' · ', $highlights).' — '.$lead;
    }

    /**
     * The Gmail deep link that opens the exact message by its RFC822 id. The
     * id is URL-encoded, so the result is always a safe mail.google.com URL
     * even for an untrusted (client-hydrated) message id.
     */
    public static function deepLink(string $messageId): string
    {
        return 'https://mail.google.com/mail/u/0/#search/rfc822msgid:'.rawurlencode($messageId);
    }

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

    /**
     * @throws GmailSearchException
     */
    public function searchForTransaction(Transaction $transaction): Collection
    {
        if (! $this->isConfigured()) {
            throw GmailSearchException::notConfigured();
        }

        $client = null;

        try {
            $client = Client::account('gmail');
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
            try {
                $client?->disconnect();
            } catch (Throwable) {
            }
        }
    }

    /**
     * Pull the seller and any "amount on date" payment-schedule lines out of a
     * cleaned receipt body. Returns an empty list for non-receipt emails, in
     * which case the caller falls back to a plain lead snippet.
     *
     * @return list<string>
     */
    private static function paymentHighlights(string $text): array
    {
        $highlights = [];

        if (preg_match('/\bSeller\b[:\s]+(.+?)(?=\s+(?:Current balance|Loan reference|Posted on|Payment amount|Payment type|Payment method)\b|$)/i', $text, $m) === 1) {
            $seller = mb_trim($m[1]);

            if ($seller !== '') {
                $highlights[] = 'Seller: '.$seller;
            }
        }

        if (preg_match_all('/\$\s?[\d,]+\.\d{2}\s*AUD\s+(?:will\s+be\s+charged\s+)?on\s+\d{1,2}\s+[A-Za-z]+\s+\d{4}/i', $text, $matches) >= 1) {
            foreach (array_slice(array_values(array_unique($matches[0])), 0, 4) as $line) {
                $highlights[] = mb_trim(preg_replace('/\s+/', ' ', $line) ?? '');
            }
        }

        return $highlights;
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

    /**
     * @throws \Webklex\PHPIMAP\Exceptions\RuntimeException
     * @throws GetMessagesFailedException
     * @throws ResponseException
     * @throws InvalidWhereQueryCriteriaException
     * @throws ImapBadRequestException
     * @throws ConnectionFailedException
     * @throws AuthFailedException
     * @throws ImapServerErrorException
     */
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
        $textBody = $message->getTextBody();
        $htmlBody = $message->getHTMLBody();

        return [
            'result' => new EmailSearchResult(
                messageId: $messageId,
                subject: (string) $message->getSubject(),
                fromName: $fromName,
                fromAddress: $fromAddress,
                date: $date?->toIso8601String(),
                snippet: self::snippetFromBodies($textBody, $htmlBody),
                gmailUrl: self::deepLink($messageId),
                details: ReceiptParser::parse($textBody, $htmlBody),
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

        return mb_trim(preg_replace('/\s+/', ' ', $merchant) ?? '');
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
