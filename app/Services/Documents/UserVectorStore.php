<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Contracts\VectorStoreContract;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The user's own vector store, created on their first upload. The user row is locked while the
 * store is created, so two jobs at once cannot make two stores.
 */
final readonly class UserVectorStore
{
    public function __construct(private VectorStoreContract $store) {}

    public function idFor(User $user): string
    {
        return DB::transaction(function () use ($user): string {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->vector_store_id === null) {
                $locked->vector_store_id = $this->store->createStore('user-'.$locked->id);
                $locked->save();
            }

            return $locked->vector_store_id;
        });
    }
}
