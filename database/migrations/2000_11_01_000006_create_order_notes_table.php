<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('orders.database.tables.order_notes', 'order_notes'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id');

            $table->foreignUuid('user_id')->nullable();
            $table->text('content');
            $table->boolean('is_customer_visible')->default(false);

            $table->nullableUuidMorphs('owner');

            $table->timestamps();

            // Indexes
            $table->index(['order_id', 'created_at']);
            $table->index(['order_id', 'is_customer_visible']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('orders.database.tables.order_notes', 'order_notes'));
    }
};
