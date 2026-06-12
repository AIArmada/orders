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
        $jsonType = (string) ($databaseConfig['json_column_type'] ?? commerce_json_column_type('orders', 'jsonb'));

        Schema::create(config('orders.database.tables.orders', 'orders'), function (Blueprint $table) use ($jsonType): void {
            $table->uuid('id')->primary();
            $table->string('order_number')->unique();
            $table->string('status', 50)->default('created')->index();

            $table->nullableUuidMorphs('customer');

            $table->nullableUuidMorphs('owner');

            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount_total')->default(0);
            $table->unsignedBigInteger('shipping_total')->default(0);
            $table->unsignedBigInteger('tax_total')->default(0);
            $table->unsignedBigInteger('grand_total')->default(0);
            $table->string('currency', 3)->default('MYR');

            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();

            $table->{$jsonType}('metadata')->nullable();

            $table->timestampTz('paid_at')->nullable()->index();
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->timestampTz('payment_failed_at')->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->timestampsTz();

            $table->index(['status', 'created_at']);
            $table->index(['customer_type', 'customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('orders.database.tables.orders', 'orders'));
    }
};
