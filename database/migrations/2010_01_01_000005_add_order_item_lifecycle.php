<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('orders.database.tables.order_items', 'order_items');

        Schema::table($tableName, function (Blueprint $table): void {
            $table->string('status', 30)->default('active')->index()->after('currency');
            $table->timestampTz('shipped_at')->nullable()->after('status');
            $table->timestampTz('delivered_at')->nullable()->after('shipped_at');
            $table->timestampTz('returned_at')->nullable()->after('delivered_at');
            $table->timestampTz('canceled_at')->nullable()->after('returned_at');
        });
    }

    public function down(): void
    {
        $tableName = config('orders.database.tables.order_items', 'order_items');

        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropColumn(['status', 'shipped_at', 'delivered_at', 'returned_at', 'canceled_at']);
        });
    }
};
