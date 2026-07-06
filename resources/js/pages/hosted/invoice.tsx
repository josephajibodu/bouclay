import { Form, Head } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { pay } from '@/routes/hosted/invoices';

type HostedInvoice = {
    publicId: string;
    number: string | null;
    status: string;
    collectionMode: 'automatic' | 'manual';
    currency: string;
    total: number;
    amountDue: number;
    dueAt: string | null;
    paidAt: string | null;
    customer: { name: string | null; email: string };
    billingAddress: {
        singleLine?: string | null;
    } | null;
    business: {
        name: string;
        line1: string | null;
        line2: string | null;
        city: string | null;
        postalCode: string | null;
        country: string | null;
    };
    invoiceFooter: string | null;
    lines: Array<{
        description: string;
        quantity: number;
        unitAmount: number;
        total: number;
    }>;
    canPay: boolean;
    payUrl: string;
};

type Props = {
    invoice: HostedInvoice;
    paymentMessage: string | null;
    paymentSuccess: boolean | null;
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

function money(amount: number, currency: string): string {
    return `${currency} ${(amount / 100).toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
}

function businessAddress(business: HostedInvoice['business']): string | null {
    const parts = [
        business.line1,
        business.line2,
        business.city,
        business.postalCode,
        business.country,
    ].filter(Boolean);

    return parts.length > 0 ? parts.join(', ') : null;
}

export default function HostedInvoice({
    invoice,
    paymentMessage,
    paymentSuccess,
}: Props) {
    const title = invoice.number ?? invoice.publicId;
    const isPaid = invoice.status === 'paid';

    return (
        <div className="min-h-screen bg-muted/30 px-4 py-10">
            <Head title={`Invoice ${title}`} />

            <div className="mx-auto flex max-w-2xl flex-col gap-6">
                {paymentMessage && (
                    <Alert variant={paymentSuccess ? 'default' : 'destructive'}>
                        <AlertDescription>{paymentMessage}</AlertDescription>
                    </Alert>
                )}

                {isPaid && (
                    <Alert>
                        <CheckCircle2 className="size-4" />
                        <AlertDescription>
                            This invoice was paid on {formatDate(invoice.paidAt)}.
                        </AlertDescription>
                    </Alert>
                )}

                <div className="rounded-xl border bg-background p-8 shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="text-sm text-muted-foreground">
                                {invoice.business.name}
                            </p>
                            <h1 className="mt-1 text-2xl font-semibold">
                                Invoice {title}
                            </h1>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {invoice.customer.name ?? invoice.customer.email}
                            </p>
                        </div>
                        <Badge variant={isPaid ? 'default' : 'secondary'}>
                            {isPaid ? 'Paid' : 'Open'}
                        </Badge>
                    </div>

                    <div className="mt-8 grid gap-6 sm:grid-cols-2">
                        <div>
                            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                From
                            </p>
                            <p className="mt-1 font-medium">
                                {invoice.business.name}
                            </p>
                            {businessAddress(invoice.business) && (
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {businessAddress(invoice.business)}
                                </p>
                            )}
                        </div>
                        <div>
                            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                Bill to
                            </p>
                            <p className="mt-1 font-medium">
                                {invoice.customer.name ?? 'Customer'}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {invoice.customer.email}
                            </p>
                            {invoice.billingAddress?.singleLine && (
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {invoice.billingAddress.singleLine}
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="mt-8 overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-muted-foreground">
                                    <th className="pb-2 font-medium">
                                        Description
                                    </th>
                                    <th className="pb-2 text-right font-medium">
                                        Qty
                                    </th>
                                    <th className="pb-2 text-right font-medium">
                                        Amount
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoice.lines.map((line) => (
                                    <tr
                                        key={`${line.description}-${line.quantity}`}
                                        className="border-b"
                                    >
                                        <td className="py-3 pr-4">
                                            {line.description}
                                        </td>
                                        <td className="py-3 text-right">
                                            {line.quantity}
                                        </td>
                                        <td className="py-3 text-right">
                                            {money(
                                                line.total,
                                                invoice.currency,
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="mt-6 flex items-end justify-between gap-4 border-t pt-4">
                        <div className="text-sm text-muted-foreground">
                            {invoice.dueAt && (
                                <p>Due {formatDate(invoice.dueAt)}</p>
                            )}
                        </div>
                        <div className="text-right">
                            <p className="text-sm text-muted-foreground">
                                Total due
                            </p>
                            <p className="text-2xl font-semibold">
                                {money(invoice.amountDue, invoice.currency)}
                            </p>
                        </div>
                    </div>

                    {invoice.invoiceFooter && (
                        <p className="mt-6 text-sm text-muted-foreground">
                            {invoice.invoiceFooter}
                        </p>
                    )}

                    {invoice.canPay && (
                        <Form
                            {...pay.form(invoice.publicId)}
                            className="mt-8"
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    className="w-full sm:w-auto"
                                    disabled={processing}
                                >
                                    {processing ? 'Redirecting…' : 'Pay now'}
                                </Button>
                            )}
                        </Form>
                    )}
                </div>
            </div>
        </div>
    );
}
