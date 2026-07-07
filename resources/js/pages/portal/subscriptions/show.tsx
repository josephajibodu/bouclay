import { Head, Link } from '@inertiajs/react';
import { ChevronRight, CreditCard } from 'lucide-react';
import { useState } from 'react';
import { CancelSubscriptionDialog } from '@/components/portal/cancel-subscription-dialog';
import {
    PortalCard,
    PortalListRow,
    PortalProductIcon,
} from '@/components/portal/portal-card';
import { SubscriptionStatusBadge } from '@/components/subscriptions/subscription-status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    formatPortalDate,
    formatPortalMoney,
    formatPortalPeriod,
} from '@/lib/portal-format';
import { index as paymentsIndex } from '@/routes/portal/payments';
import type {
    PortalSharedProps,
    PortalSubscriptionDetail,
} from '@/types/portal';

type Props = PortalSharedProps & {
    subscription: PortalSubscriptionDetail;
};

export default function PortalSubscriptionShow({
    token,
    business,
    paymentMethod: defaultPaymentMethod,
    subscription,
}: Props) {
    const [cancelOpen, setCancelOpen] = useState(false);
    const cardOnFile =
        subscription.paymentMethod ?? defaultPaymentMethod ?? null;

    const periodLabel = formatPortalPeriod(
        subscription.currentPeriodStart,
        subscription.currentPeriodEnd,
    );

    return (
        <>
            <Head title={`${subscription.productName} · ${business.name}`} />

            <div className="space-y-8">
                {/* Header */}
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex items-start gap-4">
                        <PortalProductIcon name={subscription.productName} />
                        <div className="space-y-2">
                            <h1 className="text-2xl font-semibold">
                                {subscription.productName}
                            </h1>
                            {subscription.priceLabel && (
                                <p className="text-muted-foreground">
                                    {subscription.priceLabel}
                                </p>
                            )}
                            <div className="flex flex-wrap items-center gap-2 text-sm">
                                <SubscriptionStatusBadge
                                    status={subscription.status}
                                />
                                <span className="text-muted-foreground">
                                    Started on{' '}
                                    {formatPortalDate(subscription.createdAt)}
                                </span>
                                {subscription.scheduledCancelAt && (
                                    <Badge variant="outline">
                                        Cancels{' '}
                                        {formatPortalDate(
                                            subscription.scheduledCancelAt,
                                        )}
                                    </Badge>
                                )}
                            </div>
                        </div>
                    </div>

                    {subscription.canCancel && (
                        <Button
                            variant="outline"
                            onClick={() => setCancelOpen(true)}
                            data-test="portal-cancel-subscription"
                        >
                            Cancel subscription
                        </Button>
                    )}
                </div>

                {/* Two-column grid */}
                <div className="grid gap-6 lg:grid-cols-5">
                    <div className="space-y-6 lg:col-span-2">
                        {/* Next payment */}
                        <PortalCard title="Next payment">
                            <div className="space-y-4">
                                <div>
                                    <p className="text-2xl font-semibold">
                                        {formatPortalMoney(
                                            subscription.nextPayment.amount,
                                            subscription.nextPayment.currency,
                                        )}
                                    </p>
                                    {subscription.nextPayment.dueAt && (
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            due{' '}
                                            {formatPortalDate(
                                                subscription.nextPayment.dueAt,
                                            )}
                                        </p>
                                    )}
                                </div>

                                {cardOnFile && (
                                    <div className="flex items-center gap-2 rounded-lg border border-border bg-zinc-50 px-3 py-2 text-sm">
                                        <CreditCard className="size-4 text-muted-foreground" />
                                        <span>
                                            {cardOnFile.brand ?? 'Card'} ····{' '}
                                            {cardOnFile.last4 ?? '••••'}
                                        </span>
                                    </div>
                                )}
                            </div>
                        </PortalCard>

                        {/* Recent payments */}
                        <PortalCard
                            title="Payments"
                            action={
                                <Link
                                    href={paymentsIndex(token)}
                                    className="text-sm font-medium text-blue-600 hover:text-blue-700 hover:underline"
                                >
                                    View all
                                </Link>
                            }
                        >
                            {subscription.recentPayments.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No payments yet.
                                </p>
                            ) : (
                                <div className="divide-y">
                                    {subscription.recentPayments.map(
                                        (payment) => (
                                            <PortalListRow key={payment.publicId}>
                                            <Link
                                                href={payment.invoicePayUrl}
                                                className="flex items-center justify-between gap-3 px-2 py-3"
                                            >
                                                <div>
                                                    <p className="text-sm font-medium">
                                                        {formatPortalDate(
                                                            payment.processedAt,
                                                        )}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {payment.description}
                                                    </p>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <span className="text-sm font-medium">
                                                        {formatPortalMoney(
                                                            payment.amount,
                                                            payment.currency,
                                                        )}
                                                    </span>
                                                    <Badge
                                                        variant="secondary"
                                                        className="gap-1"
                                                    >
                                                        <span className="size-1.5 rounded-full bg-emerald-500" />
                                                        {payment.statusLabel}
                                                    </Badge>
                                                    <ChevronRight className="size-4 text-muted-foreground" />
                                                </div>
                                            </Link>
                                            </PortalListRow>
                                        ),
                                    )}
                                </div>
                            )}
                        </PortalCard>
                    </div>

                    {/* Next payment summary */}
                    <PortalCard
                        title="Next payment summary"
                        className="lg:col-span-3"
                    >
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-muted-foreground">
                                        <th className="pb-3 font-medium">
                                            Item
                                        </th>
                                        <th className="pb-3 text-right font-medium">
                                            Qty
                                        </th>
                                        <th className="pb-3 text-right font-medium">
                                            Amount
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {subscription.nextPayment.lines.map(
                                        (line) => (
                                            <tr
                                                key={`${line.description}-${line.detail}`}
                                                className="border-b"
                                            >
                                                <td className="py-4 pr-4">
                                                    <p className="font-medium">
                                                        {line.description}
                                                    </p>
                                                    <div className="mt-1 flex flex-wrap items-center gap-2">
                                                        {periodLabel && (
                                                            <span className="text-xs text-muted-foreground">
                                                                {periodLabel}
                                                            </span>
                                                        )}
                                                        <Badge
                                                            variant="secondary"
                                                            className="text-xs"
                                                        >
                                                            {line.isRecurring
                                                                ? 'Recurring payment'
                                                                : 'One-time payment'}
                                                        </Badge>
                                                    </div>
                                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                                        {line.detail}
                                                    </p>
                                                </td>
                                                <td className="py-4 text-right align-top">
                                                    {line.quantity}
                                                </td>
                                                <td className="py-4 text-right align-top font-medium">
                                                    {formatPortalMoney(
                                                        line.amount,
                                                        line.currency,
                                                    )}
                                                </td>
                                            </tr>
                                        ),
                                    )}
                                </tbody>
                            </table>
                        </div>

                        <div className="mt-6 space-y-2 border-t pt-4 text-sm">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">
                                    Subtotal
                                </span>
                                <span>
                                    {formatPortalMoney(
                                        subscription.nextPayment.subtotal,
                                        subscription.nextPayment.currency,
                                    )}
                                </span>
                            </div>
                            {subscription.nextPayment.taxTotal > 0 && (
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Tax
                                    </span>
                                    <span>
                                        {formatPortalMoney(
                                            subscription.nextPayment.taxTotal,
                                            subscription.nextPayment.currency,
                                        )}
                                    </span>
                                </div>
                            )}
                            <div className="flex justify-between pt-2 text-base font-semibold">
                                <span>Total (inc. tax)</span>
                                <span>
                                    {formatPortalMoney(
                                        subscription.nextPayment.amount,
                                        subscription.nextPayment.currency,
                                    )}
                                </span>
                            </div>
                        </div>
                    </PortalCard>
                </div>
            </div>

            <CancelSubscriptionDialog
                token={token}
                subscription={subscription}
                open={cancelOpen}
                onOpenChange={setCancelOpen}
            />
        </>
    );
}
