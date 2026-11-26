<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\RuleResource;
use App\Models\UserRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class RuleController extends OwnedReadController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return RuleResource::collection(
            UserRule::query()->where('user_id', $this->owner($request)->id)->with('group')->orderBy('order')->orderBy('id')->paginate($this->perPage($request))->withQueryString(),
        );
    }

    public function show(Request $request, int $id): RuleResource
    {
        return new RuleResource(UserRule::query()->where('user_id', $this->owner($request)->id)->with('group')->findOrFail($id));
    }
}
