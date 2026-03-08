<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $databaseConfig = (array) config('orders.database', []);
        $jsonType = (string) ($databaseConfig['json_column_type'] ?? commerce_json_column_type('orders', 'json'));

        Schema::create(config('orders.database.tables.order_items', 'order_items'), function (Blueprint $table) use ($jsonType): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id');

            // Purchasable relationship (polymorphic - Product, Variant, etc.)
            $table->nullableUuidMorphs('purchasable');

            // Item details (snapshotted at order time)
            $table->string('name');
            $table->string('sku')->nullable()->index();
            $table->unsignedInteger('quantity')->default(1);

            // Money fields (stored in cents)
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->string('currency', 3)->default('MYR');

            // Options (size, color, etc.)
            $table->{$jsonType}('options')->nullable();
            $table->{$jsonType}('metadata')->nullable();

            $table->nullableUuidMorphs('owner');

            $table->timestamps();

            // Indexes
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('orders.database.tables.order_items', 'order_items'));
    }
};
