<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Http\Resources\Api\CategoryResource;
use App\Models\Category;

final class ListCategories extends OwnedListTool
{
    public function name(): string
    {
        return 'list_categories';
    }

    public function description(): string
    {
        return 'Lists the user\'s spending and income categories.';
    }

    protected function model(): string
    {
        return Category::class;
    }

    protected function resource(): string
    {
        return CategoryResource::class;
    }

    protected function order(): array
    {
        return ['name'];
    }
}
