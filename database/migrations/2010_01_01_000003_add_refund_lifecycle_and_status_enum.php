<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('orders.database.tables.order_refunds', 'order_refunds');

        Schema::table($tableName, function (Blueprint $table): void {
            $table->timestampTz('failed_at')->nullable()->after('refunded_at');
        });
    }

    public function down(): void
    {
        $tableName = config('orders.database.tables.order_refunds', 'order_refunds');

        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropColumn('failed_at');
        });
    }
};
