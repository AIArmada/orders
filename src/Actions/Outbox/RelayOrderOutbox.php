<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions\Outbox;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Orders\Enums\OutboxStatus;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderOutboxMessage;
use AIArmada\Orders\States\Canceled;
use AIArmada\Orders\States\Fraud;
use AIArmada\Orders\States\Refunded;
use AIArmada\Orders\Support\OutboxRelayOptions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Re-dispatch outbox rows the live after-commit path never marked relayed
 * (crash between commit and dispatch). Delivery is at-least-once:
 * consumers of the replayable events must be idempotent.
 */
final class RelayOrderOutbox
{
    use AsAction;

    public string $commandSignature = 'orders:outbox-relay {--limit= : Maximum rows to relay}';

    public string $commandDescription = 'Relay pending order outbox messages missed by live dispatch.';

    /**
     * @return array{relayed: int, failed: int, dead: int, skipped: int, suppressed: int}
     */
    public function handle(?int $limit = null): array
    {
        $limit ??= (int) config('orders.outbox.batch_limit', 100);
        $graceCutoff = CarbonImmutable::now()->subSeconds((int) config('orders.outbox.relay_grace_seconds', 60));
        $options = OutboxRelayOptions::fromConfig();

        $counts = ['relayed' => 0, 'failed' => 0, 'dead' => 0, 'skipped' => 0, 'suppressed' => 0];

        // Intentionally cross-tenant: the relay is a system operation that
        // re-scopes per row to the row's order owner before dispatching.
        $rows = OrderOutboxMessage::query()
            ->system()
            ->where(function ($query) use ($graceCutoff): void {
                $query
                    ->where(function ($pending) use ($graceCutoff): void {
                        $pending->pendingRelayable($graceCutoff);
                    })
                    ->orWhere(function ($failed): void {
                        $failed->failedRetryable(CarbonImmutable::now());
                    });
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            $counts[$this->relayRow($row, $options)]++;
        }

        return $counts;
    }

    public function asCommand(Command $command): void
    {
        $limit = $command->option('limit');

        // No signature default: the config value wins unless overridden.
        $result = $this->handle($limit !== null ? (int) $limit : null);

        $command->info(sprintf(
            'Outbox relay complete — relayed: %d, failed: %d, dead: %d, skipped: %d, suppressed: %d.',
            $result['relayed'],
            $result['failed'],
            $result['dead'],
            $result['skipped'],
            $result['suppressed'],
        ));
    }

    /**
     * @return 'relayed'|'failed'|'dead'|'skipped'|'suppressed'
     */
    private function relayRow(OrderOutboxMessage $row, OutboxRelayOptions $options): string
    {
        // Fail closed: never instantiate a class that is not on the allowlist.
        if (! in_array($row->event_class, OrderOutboxMessage::REPLAYABLE_EVENTS, true)) {
            $this->markDead($row, "Event class is not replayable: {$row->event_class}.");

            return 'dead';
        }

        $order = Order::query()
            ->withoutOwnerScope()
            ->find($row->order_id);

        if ($order === null) {
            $this->markDead($row, "Order {$row->order_id} no longer exists; nothing to replay.");

            return 'dead';
        }

        // Conditional claim: exactly one relayer wins each row. The
        // attempt counter increments atomically and is read back so a
        // sweep requeue landing between the batch read and the claim
        // cannot inject a stale-derived count.
        $claimed = OrderOutboxMessage::query()
            ->system()
            ->whereKey($row->getKey())
            ->whereIn('status', [OutboxStatus::Pending->value, OutboxStatus::Failed->value])
            ->update([
                'status' => OutboxStatus::Relaying->value,
                'claimed_at' => CarbonImmutable::now(),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => CarbonImmutable::now(),
            ]);

        if ($claimed === 0) {
            return 'skipped';
        }

        $attempts = (int) OrderOutboxMessage::query()
            ->system()
            ->whereKey($row->getKey())
            ->value('attempts');

        try {
            $eventClass = $row->event_class;

            /** @var 'dead'|'suppressed'|null $terminal */
            $terminal = DB::transaction(function () use ($eventClass, $order, $row): ?string {
                // Serialize replay with lifecycle changes: the order lock
                // is held through dispatch, so a cancel/refund either
                // commits first (row suppressed below, cleanup already
                // ran) or blocks until dispatch commits (cleanup runs
                // after, same as the live path). A deadlock here fails
                // the row into retry like any other dispatch error.
                $locked = Order::query()
                    ->withoutOwnerScope()
                    ->whereKey($order->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $locked instanceof Order) {
                    $this->markDead($row, "Order {$row->order_id} no longer exists; nothing to replay.");

                    return 'dead';
                }

                if ($locked->status instanceof Canceled || $locked->status instanceof Refunded || $locked->status instanceof Fraud) {
                    $this->markSuppressed($row, 'Order was cancelled, refunded, or flagged as fraud; fulfillment suppressed.');

                    return 'suppressed';
                }

                OwnerContext::withOwner($order->owner, static function () use ($eventClass, $order, $row): void {
                    event(new $eventClass($order, $row->transaction_id, $row->gateway));
                });

                return null;
            });

            if ($terminal !== null) {
                return $terminal;
            }
        } catch (Throwable $exception) {
            Log::warning('orders.outbox.relay_failed', [
                'outbox_id' => $row->getKey(),
                'order_id' => $row->order_id,
                'event_class' => $row->event_class,
                'attempt' => $attempts,
                'message' => $exception->getMessage(),
            ]);

            if ($attempts >= $options->maxAttempts) {
                $this->markDead($row, $exception->getMessage());

                return 'dead';
            }

            OrderOutboxMessage::query()
                ->system()
                ->whereKey($row->getKey())
                ->where('status', OutboxStatus::Relaying->value)
                ->update([
                    'status' => OutboxStatus::Failed->value,
                    'claimed_at' => null,
                    'next_retry_at' => CarbonImmutable::now()->addSeconds(
                        min($options->retryMaxSeconds, $options->retryBaseSeconds * $attempts)
                    ),
                    'last_error' => $exception->getMessage(),
                    'updated_at' => CarbonImmutable::now(),
                ]);

            return 'failed';
        }

        OrderOutboxMessage::query()
            ->system()
            ->whereKey($row->getKey())
            ->where('status', OutboxStatus::Relaying->value)
            ->update([
                'status' => OutboxStatus::Relayed->value,
                'claimed_at' => null,
                'relayed_at' => CarbonImmutable::now(),
                'next_retry_at' => null,
                'last_error' => null,
                'updated_at' => CarbonImmutable::now(),
            ]);

        return 'relayed';
    }

    private function markSuppressed(OrderOutboxMessage $row, string $reason): void
    {
        OrderOutboxMessage::query()
            ->system()
            ->whereKey($row->getKey())
            ->where('status', OutboxStatus::Relaying->value)
            ->update([
                'status' => OutboxStatus::Suppressed->value,
                'claimed_at' => null,
                'next_retry_at' => null,
                'last_error' => $reason,
                'updated_at' => CarbonImmutable::now(),
            ]);
    }

    private function markDead(OrderOutboxMessage $row, string $reason): void
    {
        OrderOutboxMessage::query()
            ->system()
            ->whereKey($row->getKey())
            ->whereIn('status', [
                OutboxStatus::Pending->value,
                OutboxStatus::Failed->value,
                OutboxStatus::Relaying->value,
            ])
            ->update([
                'status' => OutboxStatus::Dead->value,
                'claimed_at' => null,
                'next_retry_at' => null,
                'last_error' => $reason,
                'updated_at' => CarbonImmutable::now(),
            ]);
    }
}
