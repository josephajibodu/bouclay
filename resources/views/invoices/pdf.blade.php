<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #666; }
        .grid { width: 100%; margin-top: 24px; }
        .grid td { vertical-align: top; width: 50%; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 24px; }
        table.lines th, table.lines td { border-bottom: 1px solid #ddd; padding: 8px 4px; text-align: left; }
        table.lines th:last-child, table.lines td:last-child { text-align: right; }
        .totals { margin-top: 16px; width: 100%; }
        .totals td { padding: 4px 0; }
        .totals .label { text-align: right; padding-right: 12px; }
        .totals .amount { text-align: right; width: 120px; font-weight: bold; }
        .footer { margin-top: 32px; font-size: 11px; color: #666; }
    </style>
</head>
<body>
    @php
        $snapshot = $invoice->customer_snapshot ?? [];
        $billing = $invoice->billing_address ?? [];
        $money = fn (int $amount): string => $invoice->currency.' '.number_format($amount / 100, 2);
    @endphp

    <h1>Invoice</h1>
    <div class="muted">{{ $invoice->number ?? $invoice->public_id }}</div>

    <table class="grid">
        <tr>
            <td>
                <strong>{{ $invoice->team->name }}</strong><br>
                @if ($invoice->team->line1){{ $invoice->team->line1 }}<br>@endif
                @if ($invoice->team->line2){{ $invoice->team->line2 }}<br>@endif
                @if ($invoice->team->city){{ $invoice->team->city }}@endif
                @if ($invoice->team->postal_code) {{ $invoice->team->postal_code }}@endif
                @if ($invoice->team->country)<br>{{ $invoice->team->country }}@endif
            </td>
            <td>
                <strong>Bill to</strong><br>
                {{ $snapshot['name'] ?? $invoice->customer->name ?? 'Customer' }}<br>
                {{ $snapshot['email'] ?? $invoice->customer->email }}<br>
                @if (! empty($billing['singleLine']))
                    {{ $billing['singleLine'] }}
                @endif
            </td>
        </tr>
    </table>

    @if ($invoice->due_at)
        <p><strong>Due date:</strong> {{ $invoice->due_at->toFormattedDateString() }}</p>
    @endif

    <table class="lines">
        <thead>
            <tr>
                <th>Description</th>
                <th>Qty</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td>{{ $line->quantity }}</td>
                    <td>{{ $money($line->total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="label">Total due</td>
            <td class="amount">{{ $money($invoice->total) }}</td>
        </tr>
    </table>

    @if ($invoice->team->settings?->invoice_footer)
        <div class="footer">{{ $invoice->team->settings->invoice_footer }}</div>
    @endif
</body>
</html>
