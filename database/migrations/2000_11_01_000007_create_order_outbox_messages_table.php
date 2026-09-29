<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('orders.database.tables.order_outbox', 'order_outbox_messages'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id');

            $table->nullableUuidMorphs('owner');

            $table->string('event_class');
            $table->string('transaction_id');
            $table->string('gateway', 50);

            $table->string('status', 20);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('relayed_at')->nullable();
            $table->timestampTz('next_retry_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestampsTz();

            $table->index('order_id');
            $table->index(['status', 'created_at']);
            $table->index(['status', 'next_retry_at']);
            $table->index(['status', 'claimed_at']);
        });
    }
};
