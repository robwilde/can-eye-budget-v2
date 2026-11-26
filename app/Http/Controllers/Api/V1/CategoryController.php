<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CategoryController extends OwnedReadController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::query()->where('user_id', $this->owner($request)->id)->orderBy('name')->orderBy('id')->paginate($this->perPage($request))->withQueryString(),
        );
    }

    public function show(Request $request, int $id): CategoryResource
    {
        return new CategoryResource(Category::query()->where('user_id', $this->owner($request)->id)->findOrFail($id));
    }
}
