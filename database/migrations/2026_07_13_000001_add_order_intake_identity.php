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

        Schema::table($tableName, function (Blueprint $table) use ($tableName, $ownerTypeCol, $ownerIdCol): void {
            $table->string('intake_source')->nullable()->after('order_number');
            $table->string('intake_id')->nullable()->after('intake_source');

            $table->unique(
                [$ownerTypeCol, $ownerIdCol, 'intake_source', 'intake_id'],
                $tableName . '_intake_unique',
            );
        });
    }
};
