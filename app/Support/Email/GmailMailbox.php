<?php

declare(strict_types=1);

namespace App\Support\Email;

use App\DTOs\RawEmail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;
use Webklex\IMAP\Facades\Client;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Client as ImapClient;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Support\MessageCollection;

final readonly class GmailMailbox
{
    /**
     * Gmail folders searched, in priority order. All Mail covers archived
     * receipts; INBOX is the fallback for non-English locales / hidden folders.
     *
     * @var list<string>
     */
    private const array FOLDER_PATHS = ['[Gmail]/All Mail', 'INBOX'];

    public function __construct(
        private string $username,
        #[SensitiveParameter]
        private string $password,
    ) {}

    /**
     * The mailbox-independent parts of a message. Null when the message has no
     * Message-ID (it could never be linked or deduplicated).
     */
    public static function rawEmail(Message $message): ?RawEmail
    {
        $messageId = mb_trim((string) $message->getMessageId(), "<> \t\n\r\0\x0B");

        if ($messageId === '') {
            return null;
        }

        $from = $message->getFrom()->first();
        $date = $message->getDate()->first();

        return new RawEmail(
            messageId: $messageId,
            subject: (string) $message->getSubject(),
            fromName: $from instanceof Address && $from->personal !== '' ? $from->personal : null,
            fromAddress: $from instanceof Address ? $from->mail : '',
            date: $date instanceof CarbonInterface ? CarbonImmutable::instance($date) : null,
            textBody: $message->getTextBody(),
            htmlBody: $message->getHTMLBody(),
        );
    }

    public static function forUser(User $user): ?self
    {
        $credential = $user->gmailCredential;

        return $credential === null ? null : new self($credential->username, $credential->app_password);
    }

    /**
     * @throws Throwable login or connection failure
     */
    public function verify(): void
    {
        $client = $this->client();

        try {
            $client->connect();
        } finally {
            $this->close($client);
        }
    }

    /**
     * Run one X-GM-RAW query. The value is wrapped in double quotes by the IMAP
     * library, so it must never itself contain a double quote.
     *
     * @throws Throwable connection, folder or query failure
     */
    public function search(string $xGmRaw, int $limit): MessageCollection
    {
        $client = $this->client();

        try {
            $client->connect();

            return $this->resolveFolder($client)
                ->query()
                ->where('CUSTOM X-GM-RAW', $xGmRaw)
                ->limit($limit)
                ->get();
        } finally {
            $this->close($client);
        }
    }

    private function client(): ImapClient
    {
        return Client::make([
            ...(array) config('imap.accounts.gmail'),
            'username' => $this->username,
            'password' => $this->password,
        ]);
    }

    private function close(ImapClient $client): void
    {
        try {
            $client->disconnect();
        } catch (Throwable) {
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
}
