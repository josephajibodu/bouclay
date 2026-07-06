<x-mail::message>
# Invoice from {{ $businessName }}

@if ($dueDate)
Your invoice **{{ $invoice->number }}** for **{{ $amountDue }}** is due on **{{ $dueDate }}**.
@else
Your invoice **{{ $invoice->number }}** for **{{ $amountDue }}** is ready.
@endif

The invoice is attached as a PDF. Use the button below to pay online.

<x-mail::button :url="$actionUrl">
{{ $actionLabel }}
</x-mail::button>

If you have already paid, you can ignore this email.

Thanks,<br>
{{ $businessName }}
</x-mail::message>
