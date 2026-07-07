import { Head, Link, router } from '@inertiajs/react';
import {
    Check,
    ChevronLeft,
    Copy,
    CreditCard,
    Gift,
    MoreHorizontal,
    Pause,
    Pencil,
    Play,
    Receipt,
    RefreshCw,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import ManageSubscriptionItemSheet from '@/components/subscriptions/manage-subscription-item-sheet';
import { SUBSCRIPTION_STATUS_META } from '@/components/subscriptions/subscription-status';
import { SubscriptionStatusBadge } from '@/components/subscriptions/subscription-status-badge';
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
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { show as customerShow } from '@/routes/customers';
import { cancel, index, pause, resume, undoCancel } from '@/routes/subscriptions';
import { show as invoiceShow } from '@/routes/invoices';
import type {
    CreateProductOption,
    InvoiceSummary,
    PaymentListItem,
    SubscriptionDetail,
    SubscriptionItem,
    SubscriptionScheduledChange,
    SubscriptionTimelineEvent,
} from '@/types';

type Props = {
    subscription: SubscriptionDetail;
    customer: { id: number; name: string | null; email: string };
    paymentMethod: { brand: string | null; last4: string | null } | null;
    items: SubscriptionItem[];
    scheduledChanges: SubscriptionScheduledChange[];
    timeline: SubscriptionTimelineEvent[];
    invoices: InvoiceSummary[];
    payments: PaymentListItem[];
    permissions: { canManage: boolean };
    paymentLink: string | null;
    products: CreateProductOption[];
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

function daysUntil(iso: string | null): number | null {
    if (!iso) {
        return null;
    }

    const diff = new Date(iso).getTime() - Date.now();

    return Math.max(0, Math.ceil(diff / (1000 * 60 * 60 * 24)));
}

function money(amount: number | null, currency: string): string {
    if (amount === null) {
        return 'Custom';
    }

    return `${currency} ${amount.toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
}

/** Invoice amounts come from the server in minor units (kobo). */
function formatMinor(amountMinor: number, currency: string): string {
    return money(amountMinor / 100, currency);
}

export default function SubscriptionShow({
    subscription,
    customer,
    paymentMethod,
    items,
    scheduledChanges,
    timeline,
    invoices,
    payments,
    permissions,
    paymentLink,
    products,
}: Props) {
    const { canManage } = permissions;
    const [copied, setCopied] = useState(false);
    const [cancelOpen, setCancelOpen] = useState(false);
    const [manageItem, setManageItem] = useState<SubscriptionItem | null>(
        null,
    );

    const meta = SUBSCRIPTION_STATUS_META[subscription.status];
    const scheduledCancel = scheduledChanges.find((c) => c.action === 'cancel');
    const isTerminal =
        subscription.status === 'canceled' ||
        subscription.status === 'incomplete_expired';
    const canEditItems =
        canManage &&
        !isTerminal &&
        (subscription.status === 'active' ||
            subscription.status === 'past_due');

    const copyId = async () => {
        await navigator.clipboard.writeText(subscription.publicId);
        setCopied(true);
        toast.success('Subscription ID copied');
        window.setTimeout(() => setCopied(false), 2000);
    };

    const doPause = () =>
        router.post(pause(subscription.id).url, {}, { preserveScroll: true });
    const doResume = () =>
        router.post(resume(subscription.id).url, {}, { preserveScroll: true });
    const doUndoCancel = () =>
        router.post(undoCancel(subscription.id).url, {}, { preserveScroll: true });

    const doCancel = (mode: 'immediately' | 'period_end') => {
        router.post(
            cancel(subscription.id).url,
            { mode },
            { preserveScroll: true, onSuccess: () => setCancelOpen(false) },
        );
    };

    const primaryItem = items[0];
    const planTitle = primaryItem ? primaryItem.product.name : 'Subscription';
    const total = items.reduce((sum, item) => {
        if (item.price.unitAmount !== null) {
            return sum + item.price.unitAmount * item.quantity;
        }

        return sum;
    }, 0);

    // What's-next line under the title.
    const trialDays = daysUntil(subscription.trialEndsAt);
    const nextLine =
        subscription.status === 'trialing' && subscription.trialEndsAt
            ? `Trial ends in ${trialDays} day${trialDays === 1 ? '' : 's'} · ${formatDate(subscription.trialEndsAt)}`
            : subscription.status === 'active' && subscription.currentPeriodEnd
              ? `Renews ${formatDate(subscription.currentPeriodEnd)}`
              : meta.description;

    return (
        <div className="flex max-w-4xl flex-col gap-8 p-4 pb-24">
            <Head title={`${planTitle} · ${customer.name ?? customer.email}`} />

            <Link
                href={index()}
                className="flex w-fit items-center gap-1 text-sm text-muted-foreground transition-colors hover:text-foreground"
            >
                <ChevronLeft className="size-4" /> Subscriptions
            </Link>

            {/* Header */}
            <div className="flex flex-col gap-3">
                <div className="flex items-start justify-between gap-4">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <h1 className="text-2xl font-semibold">
                                {planTitle}
                            </h1>
                            <SubscriptionStatusBadge
                                status={subscription.status}
                            />
                            {scheduledCancel && (
                                <Badge
                                    variant="outline"
                                    className="border-amber-300 text-amber-600 dark:text-amber-500"
                                >
                                    cancels{' '}
                                    {formatDate(scheduledCancel.effectiveAt)}
                                </Badge>
                            )}
                        </div>
                        <Link
                            href={customerShow(customer.id)}
                            className="text-sm text-muted-foreground transition-colors hover:text-foreground"
                        >
                            {customer.name ?? customer.email}
                        </Link>
                        <p className="text-sm text-muted-foreground">
                            {nextLine}
                        </p>
                    </div>

                    {canManage && (
                        <ActionsMenu
                            status={subscription.status}
                            hasScheduledCancel={Boolean(scheduledCancel)}
                            onPause={doPause}
                            onResume={doResume}
                            onCancel={() => setCancelOpen(true)}
                            onUndoCancel={doUndoCancel}
                            onCopyId={copyId}
                        />
                    )}
                </div>

                <button
                    type="button"
                    onClick={copyId}
                    className="flex w-fit items-center gap-1.5 text-xs text-muted-foreground transition-colors hover:text-foreground"
                >
                    {subscription.publicId} · Started{' '}
                    {formatDate(subscription.createdAt)}
                    {copied ? (
                        <Check className="size-3.5 text-emerald-500" />
                    ) : (
                        <Copy className="size-3.5" />
                    )}
                </button>
            </div>

            {/* Status banner */}
            <StatusBanner
                subscription={subscription}
                scheduledCancel={scheduledCancel}
                onUndoCancel={doUndoCancel}
                canManage={canManage}
                paymentLink={paymentLink}
            />

            {/* Overview */}
            <section className="space-y-3">
                <h2 className="text-lg font-semibold">Overview</h2>
                <div className="grid grid-cols-2 gap-x-6 gap-y-4 rounded-lg border p-4 sm:grid-cols-3">
                    <Fact label="Status" value={meta.label} />
                    <Fact
                        label="Customer"
                        value={customer.name ?? customer.email}
                    />
                    <Fact
                        label="Amount"
                        value={`${money(total, subscription.currency)} total`}
                    />
                    <Fact
                        label="Collection"
                        value={
                            subscription.collectionMode === 'automatic'
                                ? 'Automatic'
                                : 'Manual invoice'
                        }
                    />
                    <Fact
                        label="Trial ends"
                        value={
                            subscription.trialEndsAt
                                ? formatDate(subscription.trialEndsAt)
                                : '—'
                        }
                    />
                    <Fact
                        label="Next billing"
                        value={formatDate(subscription.currentPeriodEnd)}
                    />
                </div>
            </section>

            {/* Items */}
            <section className="space-y-3">
                <div className="flex items-center justify-between gap-3">
                    <h2 className="text-lg font-semibold">Subscription items</h2>
                    {canEditItems && items.some((item) => itemCanBeManaged(item)) && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                setManageItem(
                                    items.find((item) => itemCanBeManaged(item)) ??
                                        null,
                                )
                            }
                            data-test="manage-items-button"
                        >
                            Manage items
                        </Button>
                    )}
                </div>
                <div className="divide-y rounded-lg border">
                    {items.map((item) => (
                        <div
                            key={item.id}
                            className="flex items-start justify-between gap-3 p-4"
                        >
                            <div className="flex items-start gap-3">
                                {item.trial ? (
                                    <Gift className="mt-0.5 size-5 text-blue-500" />
                                ) : (
                                    <Receipt className="mt-0.5 size-5 text-muted-foreground" />
                                )}
                                <div>
                                    <p className="font-medium">
                                        {item.product.name}
                                        {item.trial && (
                                            <Badge
                                                variant="secondary"
                                                className="ml-2"
                                            >
                                                On trial
                                            </Badge>
                                        )}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {item.trial
                                            ? `${item.trial.isFree ? 'Free' : item.price.label} · then ${item.trial.transitionPrice.label}`
                                            : item.price.label}
                                    </p>
                                </div>
                            </div>
                            <div className="flex items-start gap-2">
                                <div className="text-right text-sm">
                                    <p className="font-medium">
                                        {item.trial?.isFree
                                            ? 'Free'
                                            : money(
                                                  item.price.unitAmount,
                                                  item.price.currency,
                                              )}
                                    </p>
                                    <p className="text-muted-foreground">
                                        × {item.quantity}
                                    </p>
                                </div>
                                {canEditItems && itemCanBeManaged(item) && (
                                    <ItemActionsMenu
                                        onManage={() => setManageItem(item)}
                                    />
                                )}
                            </div>
                        </div>
                    ))}
                </div>
                {items.length > 1 && (
                    <div className="flex justify-end text-sm">
                        <span className="text-muted-foreground">
                            Total&nbsp;
                        </span>
                        <span className="font-semibold">
                            {money(total, subscription.currency)}
                        </span>
                    </div>
                )}
            </section>

            {/* Trial */}
            {primaryItem?.trial && (
                <TrialCard
                    trial={primaryItem.trial}
                    createdAt={subscription.createdAt}
                    collectionMode={subscription.collectionMode}
                    card={paymentMethod}
                />
            )}

            {/* Payment method */}
            <section className="space-y-3">
                <h2 className="text-lg font-semibold">Payment method</h2>
                <div className="flex items-center gap-3 rounded-lg border p-4">
                    <CreditCard className="size-5 text-muted-foreground" />
                    <p className="text-sm">
                        {paymentMethod
                            ? `${paymentMethod.brand ?? 'Card'} ···· ${paymentMethod.last4 ?? '••••'}`
                            : subscription.collectionMode === 'manual'
                              ? 'Invoiced — no card required'
                              : 'No card on file yet'}
                    </p>
                </div>
            </section>

            {/* Timeline */}
            <section className="space-y-3">
                <h2 className="text-lg font-semibold">Timeline</h2>
                <div className="rounded-lg border p-4">
                    <ol className="space-y-4">
                        {timeline.map((event, i) => (
                            <li
                                key={`${event.type}-${i}`}
                                className="flex items-center gap-3 text-sm"
                            >
                                <span className="size-2 shrink-0 rounded-full bg-muted-foreground/40" />
                                <span className="flex-1">{event.label}</span>
                                <span
                                    className="text-xs text-muted-foreground"
                                    title={event.at ?? ''}
                                >
                                    {formatDateTime(event.at)}
                                </span>
                            </li>
                        ))}
                    </ol>
                </div>
            </section>

            {/* Invoices */}
            <section className="space-y-3">
                <h2 className="text-lg font-semibold">Upcoming invoices</h2>
                {invoices.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Nothing has been billed on this subscription yet.
                    </div>
                ) : (
                    <div className="divide-y rounded-lg border">
                        {invoices.map((invoice) => (
                            <div
                                key={invoice.id}
                                role="link"
                                tabIndex={0}
                                className="flex cursor-pointer items-center justify-between gap-3 p-4 transition-colors hover:bg-muted/40"
                                onClick={() =>
                                    router.visit(invoiceShow(invoice.id))
                                }
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' || e.key === ' ') {
                                        router.visit(invoiceShow(invoice.id));
                                    }
                                }}
                                data-test="invoice-row"
                            >
                                <div className="flex items-center gap-3">
                                    <Receipt className="size-5 text-muted-foreground" />
                                    <div>
                                        <p className="font-medium">
                                            {invoice.number ?? invoice.publicId}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {formatDate(invoice.createdAt)}
                                            {invoice.dueAt
                                                ? ` · due ${formatDate(invoice.dueAt)}`
                                                : ''}
                                        </p>
                                    </div>
                                </div>
                                <div className="flex items-center gap-3">
                                    <span className="text-sm font-medium">
                                        {formatMinor(
                                            invoice.total,
                                            invoice.currency,
                                        )}
                                    </span>
                                    <Badge variant="secondary" className="gap-1">
                                        <span
                                            className={`size-1.5 rounded-full ${INVOICE_STATUS_COLOR[invoice.status]}`}
                                        />
                                        {INVOICE_STATUS_LABEL[invoice.status]}
                                    </Badge>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </section>

            {/* Payments */}
            <section className="space-y-3">
                <h2 className="text-lg font-semibold">Payments</h2>
                {payments.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Every charge attempted against this subscription —
                        succeeded, failed, or refunded — will be listed here.
                    </div>
                ) : (
                    <div className="divide-y rounded-lg border">
                        {payments.map((payment) => (
                            <div
                                key={payment.id}
                                className="flex items-center justify-between gap-3 p-4"
                            >
                                <div className="flex items-center gap-3">
                                    <CreditCard className="size-5 text-muted-foreground" />
                                    <div>
                                        <p className="font-medium">
                                            {formatMinor(
                                                payment.amount,
                                                payment.currency,
                                            )}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {payment.paymentMethodLabel} ·{' '}
                                            {formatDateTime(payment.processedAt)}
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
                )}
            </section>

            {/* Developer */}
            <section className="space-y-3">
                <h2 className="text-lg font-semibold">Developer</h2>
                <div className="space-y-2 rounded-lg border p-4 text-sm">
                    <div className="flex items-center justify-between gap-4">
                        <span className="text-muted-foreground">
                            Subscription ID
                        </span>
                        <button
                            type="button"
                            onClick={copyId}
                            className="flex items-center gap-1.5 font-mono transition-colors hover:text-foreground"
                        >
                            {subscription.publicId}
                            <Copy className="size-3.5 text-muted-foreground" />
                        </button>
                    </div>
                    <div className="flex items-center justify-between gap-4">
                        <span className="text-muted-foreground">Created</span>
                        <span>{formatDateTime(subscription.createdAt)}</span>
                    </div>
                </div>
            </section>

            <ManageSubscriptionItemSheet
                subscription={subscription}
                item={manageItem}
                products={products}
                open={manageItem !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setManageItem(null);
                    }
                }}
            />

            {/* Cancel dialog */}
            <Dialog open={cancelOpen} onOpenChange={setCancelOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Cancel this subscription?</DialogTitle>
                        <DialogDescription>
                            Choose when it ends. Canceling at the end of the
                            period lets {customer.name ?? 'the customer'} keep
                            access until then — the recommended choice.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="flex-col gap-2 sm:flex-col">
                        <Button
                            variant="outline"
                            onClick={() => doCancel('period_end')}
                            disabled={isTerminal}
                        >
                            Cancel at period end
                            {subscription.currentPeriodEnd
                                ? ` (${formatDate(subscription.currentPeriodEnd)})`
                                : ''}
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => doCancel('immediately')}
                            disabled={isTerminal}
                        >
                            Cancel immediately
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

function StatusBanner({
    subscription,
    scheduledCancel,
    onUndoCancel,
    canManage,
    paymentLink,
}: {
    subscription: SubscriptionDetail;
    scheduledCancel: SubscriptionScheduledChange | undefined;
    onUndoCancel: () => void;
    canManage: boolean;
    paymentLink: string | null;
}) {
    if (scheduledCancel) {
        return (
            <Banner tone="amber">
                <span>
                    Set to cancel on {formatDate(scheduledCancel.effectiveAt)}.
                    It stays active until then, and you can undo this anytime.
                </span>
                {canManage && (
                    <Button size="sm" variant="outline" onClick={onUndoCancel}>
                        Undo cancellation
                    </Button>
                )}
            </Banner>
        );
    }

    switch (subscription.status) {
        case 'trialing':
            return (
                <Banner tone="blue">
                    <span>
                        🎁 On a free trial. No payment has been taken yet. On{' '}
                        {formatDate(subscription.trialEndsAt)} this converts to
                        the regular price and the first charge runs.
                    </span>
                </Banner>
            );
        case 'incomplete':
            return (
                <Banner tone="amber">
                    <span>
                        Waiting for the first payment. Send the customer the
                        payment link — access should start once it&apos;s paid.
                    </span>
                    {canManage && paymentLink && (
                        <CopyPaymentLinkButton url={paymentLink} />
                    )}
                </Banner>
            );
        case 'past_due':
            return (
                <Banner tone="red">
                    <span>
                        A renewal payment failed. Bouclay will retry
                        automatically. Update the card to recover sooner.
                    </span>
                    {canManage && paymentLink && (
                        <CopyPaymentLinkButton url={paymentLink} />
                    )}
                </Banner>
            );
        case 'paused':
            return (
                <Banner tone="violet">
                    <span>
                        Billing is paused — no charges will be made.
                        {subscription.pauseResumesAt
                            ? ` Resumes ${formatDate(subscription.pauseResumesAt)}.`
                            : ''}
                    </span>
                </Banner>
            );
        case 'canceled':
            return (
                <Banner tone="zinc">
                    <span>
                        This subscription ended
                        {subscription.endsAt
                            ? ` on ${formatDate(subscription.endsAt)}`
                            : ''}
                        . Its history stays here for your records.
                    </span>
                </Banner>
            );
        default:
            return null;
    }
}

const TONES: Record<string, string> = {
    amber: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300',
    blue: 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-300',
    red: 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300',
    violet: 'border-violet-200 bg-violet-50 text-violet-800 dark:border-violet-900 dark:bg-violet-950/40 dark:text-violet-300',
    zinc: 'border-border bg-muted/40 text-muted-foreground',
};

function CopyPaymentLinkButton({ url }: { url: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        await navigator.clipboard.writeText(url);
        setCopied(true);
        toast.success('Payment link copied');
        window.setTimeout(() => setCopied(false), 2000);
    };

    return (
        <Button
            size="sm"
            variant="outline"
            onClick={copy}
            data-test="copy-payment-link"
        >
            {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
            Copy payment link
        </Button>
    );
}

function Banner({
    tone,
    children,
}: {
    tone: keyof typeof TONES;
    children: React.ReactNode;
}) {
    return (
        <div
            className={`flex items-center justify-between gap-4 rounded-lg border px-4 py-3 text-sm ${TONES[tone]}`}
        >
            {children}
        </div>
    );
}

function TrialCard({
    trial,
    createdAt,
    collectionMode,
    card,
}: {
    trial: NonNullable<SubscriptionItem['trial']>;
    createdAt: string | null;
    collectionMode: string;
    card: { brand: string | null; last4: string | null } | null;
}) {
    const days = daysUntil(trial.endsAt);

    return (
        <section className="space-y-3">
            <div className="flex items-center gap-2">
                <h2 className="text-lg font-semibold">Trial</h2>
                <Badge variant="secondary" className="gap-1">
                    <Gift className="size-3" />
                    {trial.isFree ? 'Free trial' : 'Paid trial'}
                </Badge>
            </div>
            <div className="space-y-3 rounded-lg border p-4">
                <div className="flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">
                        Started {formatDate(createdAt)}
                    </span>
                    <span className="font-medium">
                        {days} day{days === 1 ? '' : 's'} left
                    </span>
                    <span className="text-muted-foreground">
                        Ends {formatDate(trial.endsAt)}
                    </span>
                </div>
                <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                    <div
                        className="h-full rounded-full bg-blue-500"
                        style={{ width: `${trialProgress(createdAt, trial.endsAt)}%` }}
                    />
                </div>
                <p className="text-sm text-muted-foreground">
                    When the trial ends → converts to{' '}
                    {trial.transitionPrice.label} · first charge{' '}
                    {money(
                        trial.transitionPrice.unitAmount,
                        trial.transitionPrice.currency,
                    )}
                    .
                    {collectionMode === 'automatic' && card
                        ? ` Paid automatically with ${card.brand ?? 'card'} ···· ${card.last4 ?? '••••'}.`
                        : collectionMode === 'automatic'
                          ? ' Add a card before then to avoid interruption.'
                          : " We'll send an invoice."}
                </p>
            </div>
        </section>
    );
}

function trialProgress(start: string | null, end: string): number {
    if (!start) {
        return 0;
    }

    const startMs = new Date(start).getTime();
    const endMs = new Date(end).getTime();
    const now = Date.now();

    if (now >= endMs) {
        return 100;
    }

    return Math.min(
        100,
        Math.max(0, ((now - startMs) / (endMs - startMs)) * 100),
    );
}

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

function itemCanBeManaged(item: SubscriptionItem): boolean {
    return item.status === 'active' && item.trial === null;
}

function ItemActionsMenu({ onManage }: { onManage: () => void }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-8 shrink-0"
                    data-test="item-actions"
                >
                    <MoreHorizontal className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
                <DropdownMenuItem onClick={onManage}>
                    <Pencil /> Change plan or quantity
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function ActionsMenu({
    status,
    hasScheduledCancel,
    onPause,
    onResume,
    onCancel,
    onUndoCancel,
    onCopyId,
}: {
    status: SubscriptionDetail['status'];
    hasScheduledCancel: boolean;
    onPause: () => void;
    onResume: () => void;
    onCancel: () => void;
    onUndoCancel: () => void;
    onCopyId: () => void;
}) {
    const canPause = status === 'active' || status === 'trialing';
    const canResume = status === 'paused';
    const canCancel =
        status === 'active' ||
        status === 'trialing' ||
        status === 'past_due' ||
        status === 'paused';

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" data-test="subscription-actions">
                    Actions
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
                {canPause && (
                    <DropdownMenuItem onClick={onPause}>
                        <Pause /> Pause subscription
                    </DropdownMenuItem>
                )}
                {canResume && (
                    <DropdownMenuItem onClick={onResume}>
                        <Play /> Resume subscription
                    </DropdownMenuItem>
                )}
                {hasScheduledCancel && (
                    <DropdownMenuItem onClick={onUndoCancel}>
                        <RefreshCw /> Undo cancellation
                    </DropdownMenuItem>
                )}
                <DropdownMenuItem onClick={onCopyId}>
                    <Copy /> Copy subscription ID
                </DropdownMenuItem>
                {canCancel && !hasScheduledCancel && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            onClick={onCancel}
                        >
                            <Trash2 /> Cancel subscription
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

SubscriptionShow.layout = () => ({
    breadcrumbs: [{ title: 'Subscriptions', href: index() }],
});
