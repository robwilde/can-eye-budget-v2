<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\TypeSafeServiceContract;
use App\DTOs\TypeSafeChoiceAnswer;
use App\Exceptions\TypeSafe\TypeSafeException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Minimal client for POST /v1/systemone (docs.typesafe.ai/api). There is no PHP SDK,
 * so the whole wire shape lives here and nowhere else.
 *
 * Request:  {"model": ..., "state": {...}, "questions": {"q": {"type": "choice",
 *           "instructions": ..., "criteria": {option_id: description}}}}
 * Response: {"model": "jev-x.y.z", "answers": {"q": {"type": "choice", "choice": id,
 *           "probabilities": {id: float}, "confidence": float}},
 *           "usage": {"input_tokens": int, "output_tokens": int}}
 *
 * 429 (rate limited) and 529 (overloaded) are retried with exponential backoff, as the
 * docs direct; every other failure is raised at once.
 */
final readonly class TypeSafeService implements TypeSafeServiceContract
{
    private const string QUESTION_ID = 'answer';

    /** Sleeps (ms) before each retry of a 429/529; one retry per entry. */
    private const array BACKOFF_MS = [500, 1000, 2000, 4000];

    public function __construct(
        private string $apiKey,
        private string $baseUrl = 'https://api.typesafe.ai',
        private string $model = 'jev-latest',
    ) {}

    public function choose(array $state, string $instructions, array $options): TypeSafeChoiceAnswer
    {
        $payload = [
            'model' => $this->model,
            'state' => $state,
            'questions' => [
                self::QUESTION_ID => [
                    'type' => 'choice',
                    'instructions' => $instructions,
                    'criteria' => $options,
                ],
            ],
        ];

        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout(30)
                ->retry(
                    self::BACKOFF_MS,
                    when: static fn (Throwable $e): bool => $e instanceof RequestException
                        && in_array($e->response->status(), [429, 529], true),
                    throw: false,
                )
                ->post(mb_rtrim($this->baseUrl, '/').'/v1/systemone', $payload);
        } catch (ConnectionException $e) {
            throw new TypeSafeException('TypeSafe request failed: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new TypeSafeException("TypeSafe returned HTTP {$response->status()}: ".mb_substr($response->body(), 0, 300));
        }

        return $this->parse($response, array_keys($options));
    }

    /**
     * @param  list<string|int>  $optionIds
     */
    private function parse(Response $response, array $optionIds): TypeSafeChoiceAnswer
    {
        $answer = $response->json('answers.'.self::QUESTION_ID);
        $choice = is_array($answer) ? ($answer['choice'] ?? null) : null;
        $probabilities = is_array($answer) ? ($answer['probabilities'] ?? null) : null;

        if (! is_string($choice) || ! in_array($choice, array_map(strval(...), $optionIds), true) || ! is_array($probabilities)) {
            throw new TypeSafeException('TypeSafe response has no well-formed choice answer.');
        }

        $ranked = [];
        foreach ($probabilities as $id => $probability) {
            $ranked[(string) $id] = (float) $probability;
        }
        arsort($ranked);

        return new TypeSafeChoiceAnswer(
            choice: $choice,
            probabilities: $ranked,
            confidence: (float) ($answer['confidence'] ?? 0.0),
            model: (string) ($response->json('model') ?? 'unknown'),
            inputTokens: (int) ($response->json('usage.input_tokens') ?? 0),
            outputTokens: (int) ($response->json('usage.output_tokens') ?? 0),
        );
    }
}
