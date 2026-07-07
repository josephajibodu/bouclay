import { Head, Link } from '@inertiajs/react';
import { ChevronRight, CreditCard, ExternalLink } from 'lucide-react';
import { PortalCard, PortalListRow } from '@/components/portal/portal-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatPortalDate, formatPortalMoney } from '@/lib/portal-format';
import type { PortalOpenInvoice, PortalPayment, PortalSharedProps } from '@/types/portal';

type Props = PortalSharedProps & {
    payments: PortalPayment[];
    openInvoices: PortalOpenInvoice[];
};

export default function PortalPaymentsIndex({
    token,
    business,
    payments,
    openInvoices,
}: Props) {
    return (
        <>
            <Head title={`Payments · ${business.name}`} />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold">Payments</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Your payment history with {business.name}.
                    </p>
                </div>

                {openInvoices.length > 0 && (
                    <PortalCard title="Open invoices">
                        <div className="divide-y">
                            {openInvoices.map((invoice) => (
                                <div
                                    key={invoice.publicId}
                                    className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                                >
                                    <div>
                                        <p className="font-medium">
                                            {invoice.number ?? invoice.publicId}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {invoice.productsLabel}
                                            {invoice.dueAt && (
                                                <>
                                                    {' '}
                                                    · Due{' '}
                                                    {formatPortalDate(
                                                        invoice.dueAt,
                                                    )}
                                                </>
                                            )}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <span className="text-sm font-medium">
                                            {formatPortalMoney(
                                                invoice.amountDue,
                                                invoice.currency,
                                            )}
                                        </span>
                                        <Badge variant="secondary">Open</Badge>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            asChild
                                        >
                                            <Link
                                                href={invoice.payUrl}
                                                data-test="portal-invoice-pay-link"
                                            >
                                                Pay
                                                <ExternalLink className="size-3.5" />
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </PortalCard>
                )}

                <PortalCard title="Payment history">
                    {payments.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No payments yet.
                        </p>
                    ) : (
                        <div className="divide-y">
                            {payments.map((payment) => (
                                <PortalListRow key={payment.publicId}>
                                <Link
                                    href={payment.invoicePayUrl}
                                    className="flex items-center justify-between gap-3 px-2 py-3"
                                >
                                    <div>
                                        <p className="font-medium">
                                            {formatPortalDate(
                                                payment.processedAt,
                                            )}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {payment.description}
                                            {payment.paymentMethodLabel && (
                                                <> · {payment.paymentMethodLabel}</>
                                            )}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <span className="text-sm font-medium">
                                            {formatPortalMoney(
                                                payment.amount,
                                                payment.currency,
                                            )}
                                        </span>
                                        <Badge variant="secondary" className="gap-1">
                                            <span className="size-1.5 rounded-full bg-emerald-500" />
                                            {payment.statusLabel}
                                        </Badge>
                                        <ChevronRight className="size-4 text-muted-foreground" />
                                    </div>
                                </Link>
                                </PortalListRow>
                            ))}
                        </div>
                    )}
                </PortalCard>
            </div>
        </>
    );
}
