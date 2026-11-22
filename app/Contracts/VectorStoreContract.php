<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Exceptions\VectorStoreException;

interface VectorStoreContract
{
    /**
     * @return string the id of the new store
     *
     * @throws VectorStoreException
     */
    public function createStore(string $name): string;

    /**
     * @return string the id of the stored file
     *
     * @throws VectorStoreException
     */
    public function addFile(string $storeId, string $filename, string $bytes): string;

    /**
     * @return list<array{filename: string, excerpt: string, score: float}>
     *
     * @throws VectorStoreException
     */
    public function search(string $storeId, string $query, int $limit): array;
}
