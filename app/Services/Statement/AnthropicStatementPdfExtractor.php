<?php

declare(strict_types=1);

namespace App\Services\Statement;

use App\Contracts\StatementPdfExtractor;
use App\DTOs\ExtractedStatement;
use App\DTOs\ExtractedStatementLine;
use App\Enums\StatementDocumentType;
use App\Enums\TransactionDirection;
use App\Exceptions\Statement\StatementExtractionFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Reads a statement PDF with the Anthropic Messages API: the PDF goes up as a base64
 * `document` block and the model answers with one JSON object. Neither the API key nor
 * any response body is logged or put in an exception message.
 */
final readonly class AnthropicStatementPdfExtractor implements StatementPdfExtractor
{
    public const string DATE_FORMATS = 'Y-m-d,d/m/Y,m/d/Y,d.m.Y,d-m-Y,d M Y,j F Y';

    private const string API_VERSION = '2023-06-01';

    private const int MAX_DESCRIPTION = 255;

    private const int MIN_TIMEOUT_SECONDS = 120;

    private const int TOKENS_PER_SECOND_FLOOR = 36;

    private const int CONNECT_TIMEOUT_SECONDS = 10;

    /** Sleeps (ms) before each retry of a connection failure, rate limit or 5xx; one retry per entry. */
    private const array BACKOFF_MS = [2000];

    private const string PROMPT = <<<'TXT'
Read this document and reply with one JSON object and nothing else, in exactly this shape:
{"document_type": "bank_statement|credit_card_statement|investment_statement|financial_document|contract|other",
 "date_format": "Y-m-d|d/m/Y|m/d/Y|d.m.Y|d-m-Y|d M Y|j F Y" or null,
 "lines": [{"raw_date": "the date exactly as printed", "amount": "12.34", "direction": "debit|credit", "description": "the text printed for the line", "kind": "statement_only"}]}
Rules: list lines only for bank statements and credit-card statements; for any other document type "lines" is an empty array. "amount" is a positive decimal string with at most two decimals and no currency symbol or thousands separator. "direction" is "debit" for money leaving the account (or a card purchase) and "credit" for money arriving. "date_format" is the PHP date format the printed dates use, or null if you cannot tell. Do not include balances, totals or running-balance rows.
TXT;

    public function __construct(
        private ?string $apiKey,
        private string $baseUrl = 'https://api.anthropic.com/v1',
        private string $model = 'claude-sonnet-4-5',
        private int $maxTokens = 16000,
    ) {}

    public function extract(string $pdfBytes): ExtractedStatement
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new StatementExtractionFailed('AI provider not configured');
        }

        return $this->parse($this->send($pdfBytes));
    }

    private function send(string $pdfBytes): Response
    {
        try {
            $response = Http::withHeaders(['x-api-key' => $this->apiKey, 'anthropic-version' => self::API_VERSION])
                ->acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout($this->timeoutSeconds())
                ->retry(
                    self::BACKOFF_MS,
                    when: static fn (Throwable $e): bool => $e instanceof ConnectionException
                        || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429)),
                    throw: false,
                )
                ->post(mb_rtrim($this->baseUrl, '/').'/messages', [
                    'model' => $this->model,
                    'max_tokens' => $this->maxTokens,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($pdfBytes)]],
                            ['type' => 'text', 'text' => self::PROMPT],
                        ],
                    ]],
                ]);
        } catch (ConnectionException) {
            throw new StatementExtractionFailed('The AI provider could not be reached. Please try again later.');
        }

        if ($response->successful()) {
            return $response;
        }

        throw new StatementExtractionFailed($this->failureMessage($response));
    }

    private function failureMessage(Response $response): string
    {
        $status = $response->status();

        if ($status === 400 && $this->rejectsPdfs($response)) {
            return 'AI provider does not support PDF processing';
        }

        return match (true) {
            $status === 401, $status === 403 => 'The AI provider refused the request.',
            $status === 413 => 'This PDF is too large for the AI provider.',
            $status === 429 || $status >= 500 => 'The AI provider is unavailable. Please try again later.',
            default => 'The AI provider could not process this document.',
        };
    }

    private function rejectsPdfs(Response $response): bool
    {
        $message = mb_strtolower((string) $response->json('error.message'));

        return (str_contains($message, 'pdf') || str_contains($message, 'document'))
            && (str_contains($message, 'support') || str_contains($message, 'type') || str_contains($message, 'media'));
    }

    /**
     * A reply is not streamed, so the wait has to cover generating the whole token budget.
     */
    private function timeoutSeconds(): int
    {
        return max(self::MIN_TIMEOUT_SECONDS, intdiv($this->maxTokens, self::TOKENS_PER_SECOND_FLOOR));
    }

    private function parse(Response $response): ExtractedStatement
    {
        if ($response->json('stop_reason') === 'max_tokens') {
            throw new StatementExtractionFailed('This statement has too many lines to read in one pass. Try a shorter period.');
        }

        $text = '';

        foreach ((array) $response->json('content') as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text = $block['text'];

                break;
            }
        }

        $data = json_decode($this->stripFences($text), true);

        if (! is_array($data) || ! $this->valid($data)) {
            throw new StatementExtractionFailed('The AI provider returned an unreadable result.');
        }

        $type = StatementDocumentType::from($data['document_type']);
        $lines = [];

        foreach ($type->producesLines() ? $data['lines'] : [] as $line) {
            $direction = TransactionDirection::from($line['direction']);
            $cents = $this->toCents($line['amount']);

            $lines[] = new ExtractedStatementLine(
                rawDate: mb_trim($line['raw_date']),
                amountCents: $direction === TransactionDirection::Debit ? -$cents : $cents,
                direction: $direction,
                description: mb_substr(mb_trim($line['description']), 0, self::MAX_DESCRIPTION),
            );
        }

        return new ExtractedStatement($type, $data['date_format'] ?? null, $lines);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function valid(array $data): bool
    {
        return ! Validator::make($data, [
            'document_type' => ['required', 'string', Rule::enum(StatementDocumentType::class)],
            'date_format' => ['nullable', 'string', Rule::in(explode(',', self::DATE_FORMATS))],
            'lines' => ['present', 'array'],
            'lines.*.raw_date' => ['required', 'string', 'max:50'],
            'lines.*.amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?\z/'],
            'lines.*.direction' => ['required', Rule::enum(TransactionDirection::class)],
            'lines.*.description' => ['required', 'string'],
            'lines.*.kind' => ['sometimes', 'in:statement_only'],
        ])->fails();
    }

    private function toCents(string $amount): int
    {
        return (int) bcmul($amount, '100', 0);
    }

    private function stripFences(string $text): string
    {
        $text = mb_trim($text);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/su', $text, $match) === 1) {
            return $match[1];
        }

        return $text;
    }
}
