<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $jsonType = commerce_json_column_type('orders', 'jsonb');

        Schema::create(config('orders.database.tables.order_refunds', 'order_refunds'), function (Blueprint $table) use ($jsonType): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id');
            $table->foreignUuid('payment_id')->nullable();

            $table->string('gateway', 50);
            $table->string('transaction_id')->nullable()->index();

            $table->unsignedBigInteger('amount')->default(0);
            $table->string('currency', 3)->default('MYR');

            $table->string('status', 20)->default('pending')->index();

            $table->string('reason');
            $table->text('notes')->nullable();

            $table->{$jsonType}('metadata')->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->nullableUuidMorphs('owner');
            $table->timestampsTz();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('orders.database.tables.order_refunds', 'order_refunds'));
    }
};
