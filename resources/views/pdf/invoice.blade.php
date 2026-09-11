<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @php
        $documentTitle = $documentTitle ?? 'Invoice';
        $documentNumber = $documentNumber ?? ($invoiceNumber ?? '');
        $documentNumberLabel = $documentNumberLabel ?? 'Invoice No:';
        $documentDate = $documentDate ?? ($invoiceDate ?? now());
        $documentDateLabel = $documentDateLabel ?? 'Invoice Date:';
        $documentFooterGreeting = $documentFooterGreeting ?? 'Thank you for your business!';
        $documentFooterNote = $documentFooterNote ?? 'For questions about this invoice, please contact us.';
    @endphp
    <title>{{ $documentTitle }} #{{ $documentNumber }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 12px;
            line-height: 1.5;
            color: #333;
            background: #fff;
        }

        .invoice {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 2px solid #4f46e5;
        }

        .company-info h1 {
            font-size: 24px;
            color: #4f46e5;
            margin-bottom: 5px;
        }

        .company-info p {
            color: #666;
            font-size: 11px;
        }

        .invoice-details {
            text-align: right;
        }

        .invoice-details h2 {
            font-size: 28px;
            color: #333;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 10px;
        }

        .invoice-details table {
            margin-left: auto;
        }

        .invoice-details td {
            padding: 3px 0;
        }

        .invoice-details td:first-child {
            color: #666;
            padding-right: 15px;
        }

        .addresses {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }

        .address-box {
            width: 48%;
        }

        .address-box h3 {
            font-size: 11px;
            text-transform: uppercase;
            color: #4f46e5;
            letter-spacing: 1px;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #e5e7eb;
        }

        .address-box p {
            color: #333;
            line-height: 1.6;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }

        .items-table thead {
            background: #4f46e5;
            color: #fff;
        }

        .items-table th {
            padding: 12px 15px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .items-table th:last-child,
        .items-table td:last-child {
            text-align: right;
        }

        .items-table th:nth-child(2),
        .items-table td:nth-child(2) {
            text-align: center;
        }

        .items-table th:nth-child(3),
        .items-table td:nth-child(3) {
            text-align: right;
        }

        .items-table tbody tr {
            border-bottom: 1px solid #e5e7eb;
        }

        .items-table tbody tr:nth-child(even) {
            background: #f9fafb;
        }

        .items-table td {
            padding: 12px 15px;
        }

        .totals {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 30px;
        }

        .totals-table {
            width: 300px;
        }

        .totals-table tr {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .totals-table tr.grand-total {
            font-size: 16px;
            font-weight: bold;
            color: #4f46e5;
            border-top: 2px solid #4f46e5;
            border-bottom: 2px solid #4f46e5;
            margin-top: 5px;
            padding: 12px 0;
        }

        .payment-info {
            background: #f9fafb;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }

        .payment-info h3 {
            font-size: 11px;
            text-transform: uppercase;
            color: #4f46e5;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-paid {
            background: #dcfce7;
            color: #166534;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .footer {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            color: #666;
            font-size: 10px;
        }

        .footer p {
            margin-bottom: 5px;
        }
    </style>
</head>

<body>
    <div class="invoice">
        <!-- Header -->
        <div class="header">
            <div class="company-info">
                <h1>{{ config('app.name', 'AIArmada Commerce') }}</h1>
                <p>{{ config('orders.company.address', '') }}</p>
                <p>{{ config('orders.company.phone', '') }}</p>
                <p>{{ config('orders.company.email', '') }}</p>
            </div>
            <div class="invoice-details">
                <h2>{{ $documentTitle }}</h2>
                <table>
                    <tr>
                        <td>{{ $documentNumberLabel }}</td>
                        <td><strong>{{ $documentNumber }}</strong></td>
                    </tr>
                    <tr>
                        <td>Order No:</td>
                        <td>{{ $order->order_number }}</td>
                    </tr>
                    <tr>
                        <td>{{ $documentDateLabel }}</td>
                        <td>{{ $documentDate->format('d M Y') }}</td>
                    </tr>
                    <tr>
                        <td>Order Date:</td>
                        <td>{{ $order->created_at->format('d M Y') }}</td>
                    </tr>
                </table>
            </div>
        </div>

        @php
            $hasText = static fn (mixed $value): bool => is_string($value) && $value !== '';
            $billingMetadata = $billing?->metadata;
            $billingContact = is_array($billingMetadata)
                && is_array($billingMetadata[\AIArmada\Orders\Models\Order::ADDRESS_CONTACT_METADATA_KEY] ?? null)
                ? $billingMetadata[\AIArmada\Orders\Models\Order::ADDRESS_CONTACT_METADATA_KEY]
                : [];
            $billingName = mb_trim(implode(' ', array_filter([
                $billingContact['first_name'] ?? null,
                $billingContact['last_name'] ?? null,
            ], $hasText)));
            $billingCompany = is_string($billingContact['company'] ?? null) ? $billingContact['company'] : null;
            $billingPhone = is_string($billingContact['phone'] ?? null) ? $billingContact['phone'] : null;
            $billingLocality = implode(', ', array_filter([
                $billing?->city,
                $billing?->state,
            ], $hasText));
            $billingLocation = mb_trim(implode(' ', array_filter([
                $billingLocality,
                $billing?->postcode,
            ], $hasText)));
            $billingCountry = $billing?->country_code ?? $billing?->country;

            $shippingMetadata = $shipping?->metadata;
            $shippingContact = is_array($shippingMetadata)
                && is_array($shippingMetadata[\AIArmada\Orders\Models\Order::ADDRESS_CONTACT_METADATA_KEY] ?? null)
                ? $shippingMetadata[\AIArmada\Orders\Models\Order::ADDRESS_CONTACT_METADATA_KEY]
                : [];
            $shippingName = mb_trim(implode(' ', array_filter([
                $shippingContact['first_name'] ?? null,
                $shippingContact['last_name'] ?? null,
            ], $hasText)));
            $shippingCompany = is_string($shippingContact['company'] ?? null) ? $shippingContact['company'] : null;
            $shippingPhone = is_string($shippingContact['phone'] ?? null) ? $shippingContact['phone'] : null;
            $shippingLocality = implode(', ', array_filter([
                $shipping?->city,
                $shipping?->state,
            ], $hasText));
            $shippingLocation = mb_trim(implode(' ', array_filter([
                $shippingLocality,
                $shipping?->postcode,
            ], $hasText)));
            $shippingCountry = $shipping?->country_code ?? $shipping?->country;
        @endphp

        <!-- Addresses -->
        @if($billing || $shipping)
            <div class="addresses">
            @if($billing)
                <div class="address-box">
                    <h3>Bill To</h3>
                    <p>
                        @if($billingName !== '')<strong>{{ $billingName }}</strong><br>@endif
                        @if($billingCompany !== null && $billingCompany !== ''){{ $billingCompany }}<br>@endif
                        @if($billing->line1){{ $billing->line1 }}<br>@endif
                        @if($billing->line2){{ $billing->line2 }}<br>@endif
                        @if($billingLocation !== ''){{ $billingLocation }}<br>@endif
                        @if($billingCountry){{ $billingCountry }}@endif
                        @if($billingPhone !== null && $billingPhone !== '')<br>{{ $billingPhone }}@endif
                    </p>
                </div>
            @endif

            @if($shipping)
                <div class="address-box">
                    <h3>Ship To</h3>
                    <p>
                        @if($shippingName !== '')<strong>{{ $shippingName }}</strong><br>@endif
                        @if($shippingCompany !== null && $shippingCompany !== ''){{ $shippingCompany }}<br>@endif
                        @if($shipping->line1){{ $shipping->line1 }}<br>@endif
                        @if($shipping->line2){{ $shipping->line2 }}<br>@endif
                        @if($shippingLocation !== ''){{ $shippingLocation }}<br>@endif
                        @if($shippingCountry){{ $shippingCountry }}@endif
                        @if($shippingPhone !== null && $shippingPhone !== '')<br>{{ $shippingPhone }}@endif
                    </p>
                </div>
            @endif
            </div>
        @endif

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 50%;">Description</th>
                    <th style="width: 15%;">Qty</th>
                    <th style="width: 15%;">Unit Price</th>
                    <th style="width: 20%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                    <tr>
                        <td>
                            {{ $item->name }}
                            @if($item->sku)<br><small style="color: #666;">SKU: {{ $item->sku }}</small>@endif
                        </td>
                        <td>{{ $item->quantity }}</td>
                        <td>{{ $item->getFormattedUnitPrice() }}</td>
                        <td>{{ $item->getFormattedTotal() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Totals -->
        <div class="totals">
            <div class="totals-table">
                <div
                    style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                    <span>Subtotal:</span>
                    <span>{{ $order->getFormattedSubtotal() }}</span>
                </div>
                @if($order->discount_total > 0)
                    <div
                        style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                        <span>Discount:</span>
                        <span>-{{ $order->getFormattedDiscountTotal() }}</span>
                    </div>
                @endif
                @if($order->shipping_total > 0)
                    <div
                        style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                        <span>Shipping:</span>
                        <span>{{ $order->getFormattedShippingTotal() }}</span>
                    </div>
                @endif
                @if($order->tax_total > 0)
                    <div
                        style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                        <span>Tax:</span>
                        <span>{{ $order->getFormattedTaxTotal() }}</span>
                    </div>
                @endif
                <div
                    style="display: flex; justify-content: space-between; padding: 12px 0; font-size: 16px; font-weight: bold; color: #4f46e5; border-top: 2px solid #4f46e5; margin-top: 5px;">
                    <span>Grand Total:</span>
                    <span>{{ $order->getFormattedGrandTotal() }}</span>
                </div>
            </div>
        </div>

        <!-- Payment Info -->
        @if($payments->count() > 0)
            <div class="payment-info">
                <h3>Payment Information</h3>
                @foreach($payments as $payment)
                    <p>
                        <strong>{{ ucfirst($payment->gateway) }}</strong> -
                        {{ $payment->getFormattedAmount() }}
                        <span class="status-badge status-paid">Paid</span>
                        @if($payment->paid_at)
                            on {{ $payment->paid_at->format('d M Y H:i') }}
                        @endif
                    </p>
                @endforeach
            </div>
        @else
            <div class="payment-info">
                <h3>Payment Status</h3>
                <p>
                    <span class="status-badge status-pending">Pending</span>
                </p>
            </div>
        @endif

        <!-- Footer -->
        <div class="footer">
            <p>{{ $documentFooterGreeting }}</p>
            <p>{{ $documentFooterNote }}</p>
            <p>&copy; {{ date('Y') }} {{ config('app.name', 'AIArmada Commerce') }}. All rights reserved.</p>
        </div>
    </div>
</body>

</html>
