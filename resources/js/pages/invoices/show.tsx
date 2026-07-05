import { Head, Link, router } from '@inertiajs/react';
import {
    Ban,
    Check,
    ChevronLeft,
    Copy,
    Download,
    Eye,
    Receipt,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import {
    INVOICE_STATUS_COLOR,
    INVOICE_STATUS_LABEL,
} from '@/components/invoices/invoice-status';
import {
    PAYMENT_STATUS_COLOR,
    PAYMENT_STATUS_LABEL,
} from '@/components/invoices/payment-status';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { show as customerShow } from '@/routes/customers';
import {
    index as invoicesIndex,
    uncollectible,
    voidMethod as voidInvoice,
} from '@/routes/invoices';
import { show as subscriptionShow } from '@/routes/subscriptions';
import type { InvoiceDetail } from '@/types';

type Props = {
    invoice: InvoiceDetail;
    permissions: { canManage: boolean };
};

function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function formatDateTime(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function formatPeriod(start: string | null, end: string | null): string {
    if (!start || !end) {
        return '—';
    }

    return `${formatDate(start)} – ${formatDate(end)}`;
}

function money(amount: number, currency: string): string {
    return `${currency} ${(amount / 100).toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
}

function businessAddress(business: InvoiceDetail['business']): string | null {
    const parts = [
        business.line1,
        business.line2,
        business.city,
        business.postalCode,
        business.country,
    ].filter(Boolean);

    return parts.length > 0 ? parts.join(', ') : null;
}

export default function InvoiceShow({ invoice, permissions }: Props) {
    const { canManage } = permissions;
    const [copied, setCopied] = useState<'id' | 'number' | null>(null);

    const title = invoice.number ?? invoice.publicId;
    const statusTimestamp =
        invoice.status === 'paid'
            ? invoice.paidAt
            : invoice.status === 'void'
              ? invoice.voidedAt
              : invoice.finalizedAt ?? invoice.createdAt;

    const copy = async (value: string, kind: 'id' | 'number') => {
        await navigator.clipboard.writeText(value);
        setCopied(kind);
        toast.success(kind === 'id' ? 'Invoice ID copied' : 'Invoice number copied');
        window.setTimeout(() => setCopied(null), 2000);
    };

    const doVoid = () =>
        router.post(voidInvoice(invoice.id).url, {}, { preserveScroll: true });

    const doUncollectible = () =>
        router.post(uncollectible(invoice.id).url, {}, { preserveScroll: true });

    return (
        <div className="flex max-w-3xl flex-col gap-8 p-4 pb-24">
            <Head title={`Invoice ${title}`} />

            <Link
                href={invoicesIndex()}
                className="flex w-fit items-center gap-1 text-sm text-muted-foreground transition-colors hover:text-foreground"
            >
                <ChevronLeft className="size-4" /> Invoices
            </Link>

            {/* Dashboard chrome — navigation + actions only */}
            <div className="flex flex-col gap-3">
                <div className="flex items-start justify-between gap-4">
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-2xl font-semibold">
                                Invoice {title}
                            </h1>
                            <Badge variant="secondary" className="gap-1">
                                <span
                                    className={`size-1.5 rounded-full ${INVOICE_STATUS_COLOR[invoice.status]}`}
                                />
                                {INVOICE_STATUS_LABEL[invoice.status]}
                            </Badge>
                        </div>
                        <Link
                            href={customerShow(invoice.customer.id)}
                            className="text-sm text-muted-foreground transition-colors hover:text-foreground"
                        >
                            {invoice.customer.name ?? invoice.customer.email}
                        </Link>
                        {statusTimestamp && (
                            <p className="text-sm text-muted-foreground">
                                {invoice.status === 'paid'
                                    ? `Paid ${formatDateTime(invoice.paidAt)}`
                                    : invoice.status === 'void'
                                      ? `Voided ${formatDateTime(invoice.voidedAt)}`
                                      : `Issued ${formatDateTime(invoice.finalizedAt ?? invoice.createdAt)}`}
                            </p>
                        )}
                    </div>

                    {canManage && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="outline"
                                    data-test="invoice-actions"
                                >
                                    Actions
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-56">
                                <DropdownMenuItem
                                    disabled
                                    title="PDF export lands in a later pass"
                                >
                                    <Download /> Download PDF
                                </DropdownMenuItem>
                                {invoice.subscription && (
                                    <DropdownMenuItem asChild>
                                        <Link
                                            href={subscriptionShow(
                                                invoice.subscription.id,
                                            )}
                                        >
                                            <Eye /> View subscription
                                        </Link>
                                    </DropdownMenuItem>
                                )}
                                {(invoice.canVoid ||
                                    invoice.canMarkUncollectible) && (
                                    <>
                                        <DropdownMenuSeparator />
                                        {invoice.canVoid && (
                                            <DropdownMenuItem onClick={doVoid}>
                                                <Ban /> Void invoice
                                            </DropdownMenuItem>
                                        )}
                                        {invoice.canMarkUncollectible && (
                                            <DropdownMenuItem
                                                onClick={doUncollectible}
                                            >
                                                <Ban /> Mark uncollectible
                                            </DropdownMenuItem>
                                        )}
                                    </>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                    <button
                        type="button"
                        onClick={() => copy(invoice.publicId, 'id')}
                        className="flex items-center gap-1.5 transition-colors hover:text-foreground"
                    >
                        {invoice.publicId}
                        {copied === 'id' ? (
                            <Check className="size-3.5 text-emerald-500" />
                        ) : (
                            <Copy className="size-3.5" />
                        )}
                    </button>
                    {invoice.number && (
                        <button
                            type="button"
                            onClick={() => copy(invoice.number!, 'number')}
                            className="flex items-center gap-1.5 transition-colors hover:text-foreground"
                        >
                            #{invoice.number}
                            {copied === 'number' ? (
                                <Check className="size-3.5 text-emerald-500" />
                            ) : (
                                <Copy className="size-3.5" />
                            )}
                        </button>
                    )}
                </div>
            </div>

            {/* Overview */}
            <section className="space-y-3">
                <h2 className="text-lg font-semibold">Overview</h2>
                <div className="grid grid-cols-2 gap-x-6 gap-y-4 rounded-lg border p-4 sm:grid-cols-4">
                    <Fact label="Type" value={invoice.billingReasonLabel} />
                    <Fact
                        label="Payment method"
                        value={invoice.paymentMethodLabel}
                    />
                    <Fact
                        label="Billing period"
                        value={formatPeriod(
                            invoice.periodStart,
                            invoice.periodEnd,
                        )}
                    />
                    <Fact
                        label="Invoice number"
                        value={invoice.number ?? invoice.publicId}
                    />
                </div>
            </section>

            {/* Amount */}
            <section className="grid gap-4 sm:grid-cols-2">
                <div className="rounded-lg border bg-muted/30 p-4">
                    <p className="text-xs text-muted-foreground">Amount</p>
                    <p className="mt-1 text-2xl font-semibold">
                        {money(invoice.total, invoice.currency)}
                    </p>
                    <Badge variant="secondary" className="mt-2 gap-1">
                        <span
                            className={`size-1.5 rounded-full ${INVOICE_STATUS_COLOR[invoice.status]}`}
                        />
                        {INVOICE_STATUS_LABEL[invoice.status]}
                    </Badge>
                </div>
                <div className="rounded-lg border p-4">
                    <p className="text-xs font-medium tracking-widest text-muted-foreground uppercase">
                        Payment breakdown
                    </p>
                    <dl className="mt-3 space-y-2 text-sm">
                        <BreakdownRow
                            label="Subtotal"
                            value={money(invoice.subtotal, invoice.currency)}
                        />
                        {invoice.discountTotal > 0 && (
                            <BreakdownRow
                                label="Discount"
                                value={`−${money(invoice.discountTotal, invoice.currency)}`}
                            />
                        )}
                        {invoice.taxTotal > 0 && (
                            <BreakdownRow
                                label="Tax"
                                value={money(invoice.taxTotal, invoice.currency)}
                            />
                        )}
                        <BreakdownRow
                            label="Total"
                            value={money(invoice.total, invoice.currency)}
                            bold
                        />
                        {invoice.amountPaid > 0 && (
                            <BreakdownRow
                                label="Amount paid"
                                value={money(invoice.amountPaid, invoice.currency)}
                            />
                        )}
                        {invoice.amountDue > 0 && invoice.status === 'open' && (
                            <BreakdownRow
                                label="Amount due"
                                value={money(invoice.amountDue, invoice.currency)}
                                bold
                            />
                        )}
                    </dl>
                </div>
            </section>

            {/* Paper invoice — always light, even in dark mode */}
            <div
                className="overflow-hidden rounded-xl border border-stone-200 bg-white text-stone-900 shadow-sm dark:border-stone-300"
                data-test="invoice-document"
            >
                <div className="space-y-8 p-8 sm:p-10">
                    {/* Masthead */}
                    <div className="flex flex-col gap-6 sm:flex-row sm:items-start sm:justify-between">
                        <div className="space-y-1">
                            <p className="text-xs font-medium tracking-widest text-stone-500 uppercase">
                                From
                            </p>
                            <p className="text-lg font-semibold text-stone-900">
                                {invoice.business.name}
                            </p>
                            {businessAddress(invoice.business) && (
                                <p className="max-w-xs text-sm text-stone-600">
                                    {businessAddress(invoice.business)}
                                </p>
                            )}
                        </div>

                        <div className="space-y-1 sm:text-right">
                            <p className="text-2xl font-bold tracking-tight text-stone-900">
                                Invoice
                            </p>
                            {invoice.number && (
                                <p className="font-mono text-sm text-stone-600">
                                    {invoice.number}
                                </p>
                            )}
                            <p className="text-sm text-stone-600">
                                Issued {formatDate(invoice.finalizedAt ?? invoice.createdAt)}
                            </p>
                            {invoice.dueAt && invoice.status === 'open' && (
                                <p className="text-sm font-medium text-amber-700">
                                    Due {formatDate(invoice.dueAt)}
                                </p>
                            )}
                        </div>
                    </div>

                    {/* Bill to */}
                    <div className="grid gap-6 sm:grid-cols-2">
                        <div className="space-y-1">
                            <p className="text-xs font-medium tracking-widest text-stone-500 uppercase">
                                Bill to
                            </p>
                            {invoice.customer.name && (
                                <p className="font-medium text-stone-900">
                                    {invoice.customer.name}
                                </p>
                            )}
                            <p className="text-sm text-stone-600">
                                {invoice.customer.email}
                            </p>
                            {invoice.billingAddress?.singleLine && (
                                <p className="text-sm text-stone-600">
                                    {invoice.billingAddress.singleLine}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2 text-sm sm:text-right">
                            <DetailRow
                                label="Type"
                                value={invoice.billingReasonLabel}
                            />
                            <DetailRow
                                label="Payment method"
                                value={invoice.paymentMethodLabel}
                            />
                            {invoice.periodStart && invoice.periodEnd && (
                                <DetailRow
                                    label="Billing period"
                                    value={formatPeriod(
                                        invoice.periodStart,
                                        invoice.periodEnd,
                                    )}
                                />
                            )}
                        </div>
                    </div>

                    {/* Line items */}
                    <div>
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-stone-200 text-left text-xs tracking-widest text-stone-500 uppercase">
                                    <th className="pb-3 font-medium">Description</th>
                                    <th className="hidden pb-3 text-right font-medium sm:table-cell">
                                        Qty
                                    </th>
                                    <th className="hidden pb-3 text-right font-medium sm:table-cell">
                                        Unit price
                                    </th>
                                    <th className="pb-3 text-right font-medium">
                                        Amount
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-stone-100">
                                {invoice.lines.map((line) => (
                                    <tr key={line.id}>
                                        <td className="py-4 pr-4">
                                            <p className="font-medium text-stone-900">
                                                {line.productName
                                                    ? `${line.productName} (Quantity: ${line.quantity})`
                                                    : line.description}
                                            </p>
                                            <p className="mt-0.5 text-xs text-stone-500">
                                                {line.description}
                                            </p>
                                            {line.periodStart &&
                                                line.periodEnd && (
                                                    <p className="mt-0.5 text-xs text-stone-500">
                                                        {formatPeriod(
                                                            line.periodStart,
                                                            line.periodEnd,
                                                        )}
                                                    </p>
                                                )}
                                        </td>
                                        <td className="hidden py-4 text-right text-stone-600 sm:table-cell">
                                            {line.quantity}
                                        </td>
                                        <td className="hidden py-4 text-right text-stone-600 sm:table-cell">
                                            {money(
                                                line.unitAmount,
                                                invoice.currency,
                                            )}
                                        </td>
                                        <td className="py-4 text-right font-medium text-stone-900">
                                            {money(line.total, invoice.currency)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Totals */}
                    <div className="flex justify-end">
                        <dl className="w-full max-w-xs space-y-2 text-sm">
                            <TotalRow
                                label="Subtotal"
                                value={money(
                                    invoice.subtotal,
                                    invoice.currency,
                                )}
                            />
                            {invoice.discountTotal > 0 && (
                                <TotalRow
                                    label="Discount"
                                    value={`−${money(invoice.discountTotal, invoice.currency)}`}
                                />
                            )}
                            {invoice.taxTotal > 0 && (
                                <TotalRow
                                    label="Tax"
                                    value={money(
                                        invoice.taxTotal,
                                        invoice.currency,
                                    )}
                                />
                            )}
                            <div className="border-t border-stone-200 pt-2">
                                <TotalRow
                                    label="Total"
                                    value={money(invoice.total, invoice.currency)}
                                    bold
                                />
                            </div>
                            {invoice.amountPaid > 0 && (
                                <TotalRow
                                    label="Amount paid"
                                    value={money(
                                        invoice.amountPaid,
                                        invoice.currency,
                                    )}
                                />
                            )}
                            {invoice.amountDue > 0 &&
                                invoice.status === 'open' && (
                                    <TotalRow
                                        label="Amount due"
                                        value={money(
                                            invoice.amountDue,
                                            invoice.currency,
                                        )}
                                        bold
                                    />
                                )}
                        </dl>
                    </div>

                    {invoice.invoiceFooter && (
                        <p className="border-t border-stone-100 pt-6 text-center text-xs text-stone-500">
                            {invoice.invoiceFooter}
                        </p>
                    )}
                </div>
            </div>

            {/* Payments */}
            {invoice.payments.length > 0 && (
                <section className="space-y-3">
                    <h2 className="text-lg font-semibold">Payments</h2>
                    <div className="divide-y rounded-lg border">
                        {invoice.payments.map((payment) => (
                            <div
                                key={payment.id}
                                className="flex items-center justify-between gap-3 p-4"
                            >
                                <div className="flex items-center gap-3">
                                    <Receipt className="size-5 text-muted-foreground" />
                                    <div>
                                        <p className="font-medium">
                                            {money(
                                                payment.amount,
                                                payment.currency,
                                            )}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {payment.paymentMethodLabel}
                                            {payment.processedAt
                                                ? ` · ${formatDateTime(payment.processedAt)}`
                                                : ''}
                                            {payment.failureReason
                                                ? ` · ${payment.failureReason}`
                                                : ''}
                                        </p>
                                    </div>
                                </div>
                                <Badge variant="secondary" className="gap-1">
                                    <span
                                        className={`size-1.5 rounded-full ${PAYMENT_STATUS_COLOR[payment.status]}`}
                                    />
                                    {PAYMENT_STATUS_LABEL[payment.status]}
                                </Badge>
                            </div>
                        ))}
                    </div>
                </section>
            )}
        </div>
    );
}

function DetailRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between gap-4 sm:flex-col sm:items-end">
            <dt className="text-stone-500">{label}</dt>
            <dd className="font-medium text-stone-800">{value}</dd>
        </div>
    );
}

function TotalRow({
    label,
    value,
    bold = false,
}: {
    label: string;
    value: string;
    bold?: boolean;
}) {
    return (
        <div className="flex items-center justify-between gap-4">
            <dt className={bold ? 'font-semibold text-stone-900' : 'text-stone-600'}>
                {label}
            </dt>
            <dd className={bold ? 'font-semibold text-stone-900' : 'text-stone-800'}>
                {value}
            </dd>
        </div>
    );
}

InvoiceShow.layout = () => ({
    breadcrumbs: [{ title: 'Invoices', href: invoicesIndex() }],
});

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div className="space-y-0.5">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="truncate text-sm" title={value}>
                {value}
            </p>
        </div>
    );
}

function BreakdownRow({
    label,
    value,
    bold = false,
}: {
    label: string;
    value: string;
    bold?: boolean;
}) {
    return (
        <div className="flex items-center justify-between gap-4">
            <dt className={bold ? 'font-semibold' : 'text-muted-foreground'}>
                {label}
            </dt>
            <dd className={bold ? 'font-semibold' : ''}>{value}</dd>
        </div>
    );
}
