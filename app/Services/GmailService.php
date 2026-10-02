<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\GmailServiceContract;
use App\DTOs\EmailSearchResult;
use App\DTOs\RawEmail;
use App\Exceptions\GmailSearchException;
use App\Models\Transaction;
use App\Support\Email\GmailMailbox;
use App\Support\Email\ReceiptParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;
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

    public function __construct(
        private readonly CategoryRuleGenerator $ruleGenerator,
        private readonly GmailMailbox $mailbox,
    ) {}

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
        return $this->mailbox->isConfigured();
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

        try {
            $messages = $this->mailbox->search($this->buildQuery($transaction), self::MAX_RESULTS);

            if ($messages->isEmpty()) {
                $messages = $this->mailbox->search($this->buildQuery($transaction, withAmount: false), self::MAX_RESULTS);
            }

            return $this->rank($messages, $transaction->post_date);
        } catch (Throwable $e) {
            throw GmailSearchException::wrap($e);
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

    /**
     * @return Collection<int, EmailSearchResult>
     */
    private function rank(MessageCollection $messages, CarbonImmutable $postDate): Collection
    {
        return $messages
            ->map(static fn (Message $message): ?RawEmail => GmailMailbox::rawEmail($message))
            ->filter()
            ->sortBy(static fn (RawEmail $email): int => $email->date instanceof CarbonImmutable
                ? (int) abs($email->date->diffInSeconds($postDate))
                : PHP_INT_MAX)
            ->map(static fn (RawEmail $email): EmailSearchResult => $email->toSearchResult(
                ReceiptParser::parse($email->textBody, $email->htmlBody),
            ))
            ->values();
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
