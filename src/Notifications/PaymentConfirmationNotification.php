<?php

declare(strict_types=1);

namespace AIArmada\Orders\Notifications;

use AIArmada\Orders\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PaymentConfirmationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Order $order,
        private readonly string $transactionId,
        private readonly string $gateway,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $eventName = (string) config('orders.notifications.payment_confirmation.event_name', 'AI Awakening');

        return (new MailMessage)
            ->from(
                config('orders.notifications.payment_confirmation.from_address', 'sales@unfairadvantage.my'),
                config('orders.notifications.payment_confirmation.from_name', config('app.name')),
            )
            ->subject(sprintf('Payment Confirmation - Unfair Advantage : %s', $eventName))
            ->markdown('orders::notifications.payment-confirmation', [
                'order' => $this->order,
                'transactionId' => $this->transactionId,
                'gateway' => $this->gateway,
                'eventName' => $eventName,
            ]);
    }
}
