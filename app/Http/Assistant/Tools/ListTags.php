<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Http\Resources\Api\TagResource;
use App\Models\Tag;

final class ListTags extends OwnedListTool
{
    public function name(): string
    {
        return 'list_tags';
    }

    public function description(): string
    {
        return 'Lists the user\'s tags.';
    }

    protected function model(): string
    {
        return Tag::class;
    }

    protected function resource(): string
    {
        return TagResource::class;
    }

    protected function order(): array
    {
        return ['name'];
    }
}
