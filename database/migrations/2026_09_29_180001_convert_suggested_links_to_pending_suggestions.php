<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A suggestion is not a transfer until confirmed. An earlier build linked suggested pairs
 * through transfer_pair_id; move them to suggested_pair_id. Kept separate from the schema
 * migration so rolling back restores the links before the column is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('transactions')
            ->where('transfer_link_source', 'suggested')
            ->whereNotNull('transfer_pair_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('transactions')->where('id', $row->id)->update([
                        'suggested_pair_id' => $row->transfer_pair_id,
                        'transfer_pair_id' => null,
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('transactions')
            ->where('transfer_link_source', 'suggested')
            ->whereNotNull('suggested_pair_id')
            ->whereNull('transfer_pair_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('transactions')->where('id', $row->id)->update([
                        'transfer_pair_id' => $row->suggested_pair_id,
                    ]);
                }
            });
    }
};
