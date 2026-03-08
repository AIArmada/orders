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

        Schema::create(config('orders.database.tables.order_addresses', 'order_addresses'), function (Blueprint $table) use ($jsonType): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id');

            $table->string('type', 20)->default('shipping'); // 'billing' or 'shipping'

            // Contact info
            $table->string('first_name');
            $table->string('last_name');
            $table->string('company')->nullable();

            // Address
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city');
            $table->string('state')->nullable();
            $table->string('postcode', 20);
            $table->string('country', 2)->default('MY');

            // Contact
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            $table->{$jsonType}('metadata')->nullable();
            $table->nullableUuidMorphs('owner');
            $table->timestamps();

            // Indexes
            $table->index(['order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('orders.database.tables.order_addresses', 'order_addresses'));
    }
};
