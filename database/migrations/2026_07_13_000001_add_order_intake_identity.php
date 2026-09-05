<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('orders.database.tables.orders', 'orders');
        $ownerTypeCol = 'owner_type';
        $ownerIdCol = 'owner_id';

        if (! Schema::hasTable($tableName)) {
            return;
        }

        if (! Schema::hasColumn($tableName, 'intake_source')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('intake_source')->nullable()->after('order_number');
            });
        }

        if (! Schema::hasColumn($tableName, 'intake_id')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('intake_id')->nullable()->after('intake_source');
            });
        }

        if (! Schema::hasIndex($tableName, $tableName . '_intake_unique')) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $ownerTypeCol, $ownerIdCol): void {
                $table->unique(
                    [$ownerTypeCol, $ownerIdCol, 'intake_source', 'intake_id'],
                    $tableName . '_intake_unique',
                );
            });
        }
    }
};
