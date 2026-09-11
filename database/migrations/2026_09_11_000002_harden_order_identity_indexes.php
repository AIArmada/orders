<?php

declare(strict_types=1);

use AIArmada\CommerceSupport\Support\ConnectionDriver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $ordersTable = (string) config('orders.database.tables.orders', 'orders');
        $paymentsTable = (string) config('orders.database.tables.order_payments', 'order_payments');

        if (Schema::hasTable($ordersTable)) {
            $this->assertColumns($ordersTable, [
                'owner_type',
                'owner_id',
                'intake_source',
                'intake_id',
            ]);
            $this->assertCompleteOwnerTuples($ordersTable);
            $this->assertNoDuplicateIntakeIdentities($ordersTable);
        }

        if (Schema::hasTable($paymentsTable)) {
            $this->assertColumns($paymentsTable, [
                'order_id',
                'gateway',
                'transaction_id',
            ]);
            $this->assertNoDuplicatePaymentIdentities($paymentsTable);
        }

        if (! in_array(ConnectionDriver::name(Schema::getConnection()), ['pgsql', 'sqlite'], true)) {
            return;
        }

        if (Schema::hasTable($ordersTable)) {
            $this->replaceIntakeUniqueIndex($ordersTable);
        }

        if (Schema::hasTable($paymentsTable)) {
            $this->replacePaymentUniqueIndex($paymentsTable);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertColumns(string $tableName, array $columns): void
    {
        foreach ($columns as $columnName) {
            if (Schema::hasColumn($tableName, $columnName)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Order identity migration cannot run because [%s] is missing column [%s].',
                $tableName,
                $columnName,
            ));
        }
    }

    private function assertCompleteOwnerTuples(string $tableName): void
    {
        $partialOwnerRows = DB::table($tableName)
            ->where(function (Builder $query): void {
                $query->where(function (Builder $nested): void {
                    $nested->whereNull('owner_type')->whereNotNull('owner_id');
                })->orWhere(function (Builder $nested): void {
                    $nested->whereNotNull('owner_type')->whereNull('owner_id');
                });
            })
            ->count();

        if ($partialOwnerRows === 0) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Order identity migration blocked: [%s] contains %d partially-owned rows. '
            . 'Resolve owner tuples without deleting data, then rerun the migration.',
            $tableName,
            $partialOwnerRows,
        ));
    }

    private function assertNoDuplicateIntakeIdentities(string $tableName): void
    {
        $duplicateGroups = DB::table($tableName)
            ->select('owner_type', 'owner_id', 'intake_source', 'intake_id')
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->whereNotNull('intake_source')
            ->whereNotNull('intake_id')
            ->groupBy('owner_type', 'owner_id', 'intake_source', 'intake_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicateGroups->isEmpty()) {
            return;
        }

        $duplicateRows = $duplicateGroups->sum(static fn (object $group): int => (int) $group->duplicate_count);

        throw new RuntimeException(sprintf(
            'Order identity migration blocked: [%s] contains %d duplicate intake groups covering %d rows. '
            . 'Resolve the duplicates without deleting data, then rerun the migration.',
            $tableName,
            $duplicateGroups->count(),
            $duplicateRows,
        ));
    }

    private function assertNoDuplicatePaymentIdentities(string $tableName): void
    {
        $duplicateGroups = DB::table($tableName)
            ->select('order_id', 'gateway', 'transaction_id')
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->whereNotNull('transaction_id')
            ->groupBy('order_id', 'gateway', 'transaction_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicateGroups->isEmpty()) {
            return;
        }

        $duplicateRows = $duplicateGroups->sum(static fn (object $group): int => (int) $group->duplicate_count);

        throw new RuntimeException(sprintf(
            'Order identity migration blocked: [%s] contains %d duplicate payment groups covering %d rows. '
            . 'Resolve the duplicates without deleting data, then rerun the migration.',
            $tableName,
            $duplicateGroups->count(),
            $duplicateRows,
        ));
    }

    private function replaceIntakeUniqueIndex(string $tableName): void
    {
        $oldIndex = $tableName . '_intake_unique';
        $newIndex = $tableName . '_intake_non_null_unique';

        if (Schema::hasIndex($tableName, $oldIndex)) {
            Schema::table($tableName, static function (Blueprint $table) use ($oldIndex): void {
                $table->dropUnique($oldIndex);
            });
        }

        if (Schema::hasIndex($tableName, $newIndex)) {
            return;
        }

        $this->createPartialUniqueIndex(
            $tableName,
            $newIndex,
            ['owner_type', 'owner_id', 'intake_source', 'intake_id'],
            ['intake_source', 'intake_id'],
        );
    }

    private function replacePaymentUniqueIndex(string $tableName): void
    {
        $oldIndexes = [
            'order_payments_order_gateway_transaction_unique',
            $tableName . '_order_gateway_transaction_unique',
        ];
        $newIndex = $tableName . '_order_gateway_transaction_non_null_unique';

        foreach (array_unique($oldIndexes) as $oldIndex) {
            if (! Schema::hasIndex($tableName, $oldIndex)) {
                continue;
            }

            Schema::table($tableName, static function (Blueprint $table) use ($oldIndex): void {
                $table->dropUnique($oldIndex);
            });
        }

        if (Schema::hasIndex($tableName, $newIndex)) {
            return;
        }

        $this->createPartialUniqueIndex(
            $tableName,
            $newIndex,
            ['order_id', 'gateway', 'transaction_id'],
            ['transaction_id'],
        );
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $nonnullColumns
     */
    private function createPartialUniqueIndex(
        string $tableName,
        string $indexName,
        array $columns,
        array $nonnullColumns,
    ): void {
        $grammar = DB::connection()->getQueryGrammar();
        $wrappedColumns = implode(', ', array_map($grammar->wrap(...), $columns));
        $conditions = implode(
            ' AND ',
            array_map(
                static fn (string $column): string => $grammar->wrap($column) . ' IS NOT NULL',
                $nonnullColumns,
            ),
        );

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (%s) WHERE %s',
            $grammar->wrap($indexName),
            $grammar->wrapTable($tableName),
            $wrappedColumns,
            $conditions,
        ));
    }
};
