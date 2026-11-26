<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\TagResource;
use App\Models\Tag;
use App\Services\Tags\TagDeleter;
use App\Services\Tags\TagInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
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
    public function store(Request $request, TagInput $input): JsonResponse
    {
        $userId = $this->owner($request)->id;

        $validated = $input->validate($request->only(['name', 'colour']), $userId, null, true);

        $tag = Tag::query()->create(['user_id' => $userId, ...$validated]);

        return new TagResource($tag)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Renames or recolours one of the user's tags (PUT and PATCH both accept a partial body).
     */
    public function update(Request $request, int $id, TagInput $input): TagResource
    {
        $userId = $this->owner($request)->id;

        $tag = Tag::query()->where('user_id', $userId)->findOrFail($id);

        $tag->update($input->validate($request->only(['name', 'colour']), $userId, $tag->id, false));

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
}
