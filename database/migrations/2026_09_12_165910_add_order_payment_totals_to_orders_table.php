<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = (string) config('orders.database.tables.orders', 'orders');

        if (! Schema::hasTable($tableName)) {
            return;
        }

        $columns = [
            'paid_total',
            'refunded_total',
            'pending_refunded_total',
        ];
        $missingColumns = [];

        foreach ($columns as $column) {
            if (! Schema::hasColumn($tableName, $column)) {
                $missingColumns[] = $column;
            }
        }

        if ($missingColumns === []) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($missingColumns): void {
            foreach ($missingColumns as $column) {
                $table->unsignedBigInteger($column)->default(0);
            }
        });
    }
};
