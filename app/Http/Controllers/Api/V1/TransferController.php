<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\TransactionResource;
use App\Services\Transfers\TransferReviewQueue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class TransferController extends OwnedReadController
{
    /**
     * Debit legs with a pending suggested partner.
     */
    public function index(Request $request, TransferReviewQueue $queue): AnonymousResourceCollection
    {
        return TransactionResource::collection(
            $queue->suggestedPairs($this->owner($request))->orderByDesc('post_date')->orderByDesc('id')->paginate($this->perPage($request))->withQueryString(),
        );
    }
}
