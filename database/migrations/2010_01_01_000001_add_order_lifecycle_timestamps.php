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
        $tableName = config('orders.database.tables.orders', 'orders');

        Schema::table($tableName, function (Blueprint $table): void {
            $table->timestampTz('payment_failed_at')->nullable()->after('canceled_at');
            $table->timestampTz('refunded_at')->nullable()->after('payment_failed_at');
            $table->timestampTz('completed_at')->nullable()->after('refunded_at');
        });

        Schema::table($tableName, function (Blueprint $table): void {
            $table->string('status', 50)->default('created')->change();
        });

        if (DB::connection()->getDriverName() !== 'sqlite') {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                UPDATE {$tableName}
                SET completed_at = (metadata->'completion'->>'completed_at')::timestamp with time zone
                WHERE status = 'completed'
                  AND metadata->'completion'->>'completed_at' IS NOT NULL
                  AND completed_at IS NULL
            ");
        }
        }
    }

    public function down(): void
    {
        $tableName = config('orders.database.tables.orders', 'orders');

        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropColumn(['payment_failed_at', 'refunded_at', 'completed_at']);
        });

        Schema::table($tableName, function (Blueprint $table): void {
            $table->string('status', 50)->default('processing')->change();
        });
    }
};
