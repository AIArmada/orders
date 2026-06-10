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
        $tableName = config('orders.database.tables.order_notes', 'order_notes');

        Schema::table($tableName, function (Blueprint $table): void {
            $table->string('visibility', 20)->default('internal')->after('content');
        });

        DB::statement("UPDATE {$tableName} SET visibility = 'customer' WHERE is_customer_visible = true");

        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropColumn('is_customer_visible');
        });
    }

    public function down(): void
    {
        $tableName = config('orders.database.tables.order_notes', 'order_notes');

        Schema::table($tableName, function (Blueprint $table): void {
            $table->boolean('is_customer_visible')->default(false)->after('content');
        });

        DB::statement("UPDATE {$tableName} SET is_customer_visible = true WHERE visibility = 'customer'");

        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropColumn('visibility');
        });
    }
};
