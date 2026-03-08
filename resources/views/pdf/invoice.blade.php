<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #{{ $invoiceNumber }}</title>
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
                <h2>Invoice</h2>
                <table>
                    <tr>
                        <td>Invoice No:</td>
                        <td><strong>{{ $invoiceNumber }}</strong></td>
                    </tr>
                    <tr>
                        <td>Order No:</td>
                        <td>{{ $order->order_number }}</td>
                    </tr>
                    <tr>
                        <td>Invoice Date:</td>
                        <td>{{ $invoiceDate->format('d M Y') }}</td>
                    </tr>
                    <tr>
                        <td>Order Date:</td>
                        <td>{{ $order->created_at->format('d M Y') }}</td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Addresses -->
        <div class="addresses">
            @if($billingAddress)
                <div class="address-box">
                    <h3>Bill To</h3>
                    <p>
                        <strong>{{ $billingAddress->getFullName() }}</strong><br>
                        @if($billingAddress->company){{ $billingAddress->company }}<br>@endif
                        {{ $billingAddress->line1 }}<br>
                        @if($billingAddress->line2){{ $billingAddress->line2 }}<br>@endif
                        {{ $billingAddress->city }}, {{ $billingAddress->state }} {{ $billingAddress->postcode }}<br>
                        {{ $billingAddress->country }}
                        @if($billingAddress->phone)<br>{{ $billingAddress->phone }}@endif
                    </p>
                </div>
            @endif

            @if($shippingAddress)
                <div class="address-box">
                    <h3>Ship To</h3>
                    <p>
                        <strong>{{ $shippingAddress->getFullName() }}</strong><br>
                        @if($shippingAddress->company){{ $shippingAddress->company }}<br>@endif
                        {{ $shippingAddress->line1 }}<br>
                        @if($shippingAddress->line2){{ $shippingAddress->line2 }}<br>@endif
                        {{ $shippingAddress->city }}, {{ $shippingAddress->state }} {{ $shippingAddress->postcode }}<br>
                        {{ $shippingAddress->country }}
                        @if($shippingAddress->phone)<br>{{ $shippingAddress->phone }}@endif
                    </p>
                </div>
            @endif
        </div>

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
            <p>Thank you for your business!</p>
            <p>For questions about this invoice, please contact us.</p>
            <p>&copy; {{ date('Y') }} {{ config('app.name', 'AIArmada Commerce') }}. All rights reserved.</p>
        </div>
    </div>
</body>

</html>