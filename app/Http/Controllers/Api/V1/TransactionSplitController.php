<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\TransactionResource;
use App\Models\Category;
use App\Services\Transactions\TransactionCategoryLines;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TransactionSplitController extends OwnedReadController
{
    public function update(Request $request, int $id, TransactionCategoryLines $lines): TransactionResource
    {
        $user = $this->owner($request);

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:2', 'max:'.TransactionCategoryLines::MAX_LINES],
            'lines.*.category_id' => ['required', 'integer', Rule::exists(Category::class, 'id')->where('user_id', $user->id)],
            'lines.*.amount' => ['required', 'integer', 'min:1', 'max:99999999999'],
            'lines.*.notes' => ['sometimes', 'nullable', 'string', 'max:'.TransactionCategoryLines::MAX_NOTES_LENGTH],
        ]);

        return new TransactionResource($lines->save($user, $id, array_values($data['lines'])));
    }

    public function destroy(Request $request, int $id, TransactionCategoryLines $lines): TransactionResource
    {
        return new TransactionResource($lines->clear($this->owner($request), $id));
    }
}
