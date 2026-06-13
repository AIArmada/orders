<x-mail::message>
# Payment Confirmed

Your payment for **{{ $eventName }}** has been confirmed.

**Transaction:** {{ $transactionId }}<br>
**Gateway:** {{ $gateway }}

<x-mail::button :url="config('app.url')">
View Order
</x-mail::button>

Thank you for your order.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
