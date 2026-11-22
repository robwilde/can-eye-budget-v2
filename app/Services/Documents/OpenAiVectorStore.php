<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Contracts\VectorStoreContract;
use App\Exceptions\VectorStoreException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * OpenAI's hosted vector stores through the Http facade. A 401 or 403 is permanent; a 5xx, a 429
 * or a timeout is transient and worth a retry. Nothing about a response body or the key is
 * put in an exception message.
 */
final class OpenAiVectorStore implements VectorStoreContract
{
    public function __construct(
        #[SensitiveParameter] private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly int $connectTimeout,
    ) {}

    public function createStore(string $name): string
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->post('/vector_stores', ['name' => $name]));

        return $this->idOf($response);
    }

    public function addFile(string $storeId, string $filename, string $bytes): string
    {
        $file = $this->send(fn (PendingRequest $http): Response => $http->attach('file', $bytes, $filename)->post('/files', ['purpose' => 'assistants']));
        $fileId = $this->idOf($file);

        try {
            $this->send(fn (PendingRequest $http): Response => $http->post('/vector_stores/'.rawurlencode($storeId).'/files', ['file_id' => $fileId]));
        } catch (VectorStoreException $exception) {
            rescue(fn (): Response => $this->send(fn (PendingRequest $http): Response => $http->delete('/files/'.rawurlencode($fileId))), report: false);

            throw $exception;
        }

        return $fileId;
    }

    public function search(string $storeId, string $query, int $limit): array
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->post('/vector_stores/'.rawurlencode($storeId).'/search', ['query' => $query, 'max_num_results' => $limit]));
        $hits = [];

        foreach ((array) $response->json('data', []) as $hit) {
            $text = collect((array) ($hit['content'] ?? []))->pluck('text')->filter(fn (mixed $part): bool => is_string($part))->implode("\n");

            $hits[] = ['filename' => (string) ($hit['filename'] ?? ''), 'excerpt' => $text, 'score' => (float) ($hit['score'] ?? 0)];
        }

        return $hits;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        $http = Http::baseUrl(mb_rtrim($this->baseUrl, '/'))
            ->withToken($this->apiKey)
            ->withHeaders(['OpenAI-Beta' => 'assistants=v2'])
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->acceptJson();

        try {
            $response = $call($http);
        } catch (ConnectionException) {
            throw new VectorStoreException('The document store could not be reached.', permanent: false);
        }

        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();

        throw new VectorStoreException(
            in_array($status, [401, 403], true) ? 'The document store refused the credentials.' : 'The document store returned an error ('.$status.').',
            permanent: $status < 500 && $status !== 429,
        );
    }

    private function idOf(Response $response): string
    {
        $id = $response->json('id');

        if (! is_string($id) || $id === '') {
            throw new VectorStoreException('The document store gave an unexpected answer.', permanent: true);
        }

        return $id;
    }
}
