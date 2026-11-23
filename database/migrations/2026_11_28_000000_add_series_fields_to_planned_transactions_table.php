<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planned_transactions', function (Blueprint $table) {
            $table->string('type')->default('bill')->after('direction');
            $table->string('origin')->default('declared')->after('type');
            $table->string('merchant_key')->nullable()->after('description');
            $table->string('payment_url', 2048)->nullable()->after('merchant_key');
            $table->text('notes')->nullable()->after('payment_url');
            $table->boolean('autopay')->default(false)->after('notes');
            $table->unsignedSmallInteger('end_after_count')->nullable()->after('until_date');
            $table->date('renewal_on')->nullable()->after('end_after_count');
            $table->date('trial_ends_on')->nullable()->after('renewal_on');
            $table->date('cancelled_on')->nullable()->after('trial_ends_on');
            $table->unsignedSmallInteger('reminder_days')->default(3)->after('cancelled_on');
            $table->unsignedSmallInteger('grace_days')->default(3)->after('reminder_days');
            $table->unsignedSmallInteger('amount_tolerance_bps')->default(750)->after('grace_days');
            $table->unsignedSmallInteger('match_early_days')->default(2)->after('amount_tolerance_bps');
            $table->unsignedSmallInteger('match_late_days')->default(7)->after('match_early_days');
            $table->string('amount_strategy')->default('fixed')->after('match_late_days');
        });

        DB::transaction(function (): void {
            DB::table('planned_transactions')
                ->whereIn('id', DB::table('bnpl_orders')->whereNotNull('planned_transaction_id')->select('planned_transaction_id'))
                ->update(['type' => 'installment']);

            DB::table('planned_transactions')->where('direction', 'credit')->update(['type' => 'income']);

            DB::table('planned_transactions')->whereNotNull('transfer_to_account_id')->update(['type' => 'transfer']);
        });
    }

    public function down(): void
    {
        Schema::table('planned_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'type',
                'origin',
                'merchant_key',
                'payment_url',
                'notes',
                'autopay',
                'end_after_count',
                'renewal_on',
                'trial_ends_on',
                'cancelled_on',
                'reminder_days',
                'grace_days',
                'amount_tolerance_bps',
                'match_early_days',
                'match_late_days',
                'amount_strategy',
            ]);
        });
    }
};
