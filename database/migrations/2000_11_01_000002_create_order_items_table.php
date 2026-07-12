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

        Schema::create(config('orders.database.tables.order_items', 'order_items'), function (Blueprint $table) use ($jsonType): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id');

            $table->nullableUuidMorphs('purchasable');

            $table->string('name');
            $table->string('sku')->nullable()->index();
            $table->unsignedInteger('quantity')->default(1);

            $table->unsignedBigInteger('unit_price')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->string('currency', 3)->default('MYR');

            $table->string('status', 30)->default('active')->index();
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('returned_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();

            $table->{$jsonType}('options')->nullable();
            $table->{$jsonType}('metadata')->nullable();

            $table->nullableUuidMorphs('owner');

            $table->timestampsTz();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('orders.database.tables.order_items', 'order_items'));
    }
};
