<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\TransferLinkSource;
use App\Http\Resources\Api\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class RejectedTransferController extends OwnedReadController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return TransactionResource::collection(
            Transaction::query()
                ->where('user_id', $this->owner($request)->id)
                ->current()
                ->where('transfer_link_source', TransferLinkSource::Unlinked->value)
                ->orderByDesc('post_date')
                ->orderByDesc('id')
                ->paginate($this->perPage($request))->withQueryString(),
        );
    }
}
