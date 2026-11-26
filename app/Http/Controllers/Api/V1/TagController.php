<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\TagResource;
use App\Models\Tag;
use App\Services\Tags\TagDeleter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

final class TagController extends OwnedReadController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return TagResource::collection(
            Tag::query()->where('user_id', $this->owner($request)->id)->orderBy('name')->orderBy('id')->paginate($this->perPage($request))->withQueryString(),
        );
    }

    public function show(Request $request, int $id): TagResource
    {
        return new TagResource(Tag::query()->where('user_id', $this->owner($request)->id)->findOrFail($id));
    }

    /**
     * Creates a tag; the name is trimmed and unique per user, the colour is a #RRGGBB hex picked from the palette when omitted.
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $this->owner($request)->id;

        $validated = $this->validated($request, $userId, null, true);

        $tag = Tag::query()->create(['user_id' => $userId, ...$validated]);

        return new TagResource($tag)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Renames or recolours one of the user's tags (PUT and PATCH both accept a partial body).
     */
    public function update(Request $request, int $id): TagResource
    {
        $userId = $this->owner($request)->id;

        $tag = Tag::query()->where('user_id', $userId)->findOrFail($id);

        $tag->update($this->validated($request, $userId, $tag->id, false));

        return new TagResource($tag);
    }

    /**
     * Deletes one of the user's tags, first moving its transaction links to `replacement_id` when given.
     *
     * @throws Throwable
     */
    public function destroy(Request $request, int $id, TagDeleter $deleter): Response
    {
        $userId = $this->owner($request)->id;

        $tag = Tag::query()->where('user_id', $userId)->findOrFail($id);

        $validated = $request->validate([
            'replacement_id' => [
                'nullable',
                'integer',
                Rule::notIn([$tag->id]),
                Rule::exists(Tag::class, 'id')->where('user_id', $userId),
            ],
        ]);

        $replacementId = $validated['replacement_id'] ?? null;

        $deleter->delete($userId, $tag->id, $replacementId === null ? null : (int) $replacementId);

        return response()->noContent();
    }

    /**
     * @return array<string, string>
     */
    private function validated(Request $request, int $userId, ?int $ignoreId, bool $creating): array
    {
        $input = $request->only(['name', 'colour']);

        if (is_string($input['name'] ?? null)) {
            $input['name'] = mb_trim($input['name']);
        }

        $presence = $creating ? 'required' : 'sometimes';

        /** @var array<string, string> $validated */
        $validated = Validator::make($input, [
            'name' => [
                $presence,
                'string',
                'max:50',
                Rule::notIn([Tag::RESERVED_NAME]),
                Rule::unique(Tag::class, 'name')->where('user_id', $userId)->ignore($ignoreId),
            ],
            'colour' => ['sometimes', 'required', 'string', 'regex:/^#[0-9A-Fa-f]{6}\z/'],
        ])->validate();

        return $validated;
    }
}
