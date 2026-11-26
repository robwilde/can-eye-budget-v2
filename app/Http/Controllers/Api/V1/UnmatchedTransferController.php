<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\TransactionResource;
use App\Services\Transfers\TransferReviewQueue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class UnmatchedTransferController extends OwnedReadController
{
    /**
     * Transfer-looking rows with no match.
     */
    public function index(Request $request, TransferReviewQueue $queue): AnonymousResourceCollection
    {
        return TransactionResource::collection(
            $queue->unmatched($this->owner($request))->orderByDesc('post_date')->orderByDesc('id')->paginate($this->perPage($request))->withQueryString(),
        );
    }
}
