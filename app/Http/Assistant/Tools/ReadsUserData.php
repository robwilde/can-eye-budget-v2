<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Validator;

/**
 * Shared plumbing for the read tools: argument validation that returns an error instead of throwing, and
 * the REST API resources as the only shape data leaves in, so the assistant never sees a field the API hides.
 */
trait ReadsUserData
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>|string the validated arguments, or an error message
     */
    private function validated(array $arguments, array $rules): array|string
    {
        $arguments = $this->withoutBlanks($arguments);
        $validator = Validator::make($arguments, $rules);

        return $validator->fails() ? 'Invalid arguments: '.implode(' ', $validator->errors()->all()) : $validator->validated();
    }

    /**
     * The model often fills unused optional arguments with null or an empty string; those mean "not given".
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function withoutBlanks(array $arguments): array
    {
        return array_filter($arguments, static fn (mixed $value): bool => $value !== null && ! (is_string($value) && mb_trim($value) === ''));
    }

    /**
     * @param  iterable<mixed>  $models
     * @param  class-string<JsonResource>  $resource
     * @return list<array<string, mixed>>
     */
    private function present(iterable $models, string $resource): array
    {
        $request = new Request;
        $rows = [];

        foreach ($models as $model) {
            $rows[] = (new $resource($model))->resolve($request);
        }

        return $rows;
    }
}
