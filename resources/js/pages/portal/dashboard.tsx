import { Head, Link } from '@inertiajs/react';
import { CreditCard, ExternalLink, Receipt, RefreshCw } from 'lucide-react';
import { PortalShell } from '@/components/portal/portal-shell';
import { SubscriptionStatusBadge } from '@/components/subscriptions/subscription-status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { SubscriptionStatus } from '@/types';

type Business = {
    name: string;
    line1: string | null;
    line2: string | null;
    city: string | null;
    postalCode: string | null;
    country: string | null;
    website: string | null;
};

type PortalSubscription = {
    publicId: string;
    status: SubscriptionStatus;
    planLabel: string;
    trialEndsAt: string | null;
    currentPeriodEnd: string | null;
    endsAt: string | null;
};

type PortalInvoice = {
    publicId: string;
    number: string | null;
    currency: string;
    amountDue: number;
    dueAt: string | null;
    createdAt: string | null;
    productsLabel: string;
    payUrl: string;
};

type Props = {
    business: Business;
    customer: { name: string | null; email: string };
    paymentMethod: {
        brand: string | null;
        last4: string | null;
        expMonth: number | null;
        expYear: number | null;
        isExpired: boolean;
    } | null;
    subscriptions: PortalSubscription[];
    openInvoices: PortalInvoice[];
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

function formatMinor(amountMinor: number, currency: string): string {
    return `${currency} ${(amountMinor / 100).toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
}

function subscriptionDateLabel(sub: PortalSubscription): string {
    if (sub.status === 'trialing' && sub.trialEndsAt) {
        return `Trial ends ${formatDate(sub.trialEndsAt)}`;
    }

    if (sub.endsAt) {
        return `Ends ${formatDate(sub.endsAt)}`;
    }

    if (sub.status === 'canceled') {
        return 'Ended';
    }

    return sub.currentPeriodEnd
        ? `Renews ${formatDate(sub.currentPeriodEnd)}`
        : '—';
}

function expiryLabel(
    pm: NonNullable<Props['paymentMethod']>,
): string {
    if (!pm.expMonth || !pm.expYear) {
        return 'Expiry unknown';
    }

    const mm = String(pm.expMonth).padStart(2, '0');
    const yy = String(pm.expYear).slice(-2);

    return `${pm.isExpired ? 'Expired' : 'Expires'} ${mm}/${yy}`;
}

function Section({
    title,
    icon: Icon,
    children,
}: {
    title: string;
    icon: typeof CreditCard;
    children: React.ReactNode;
}) {
    return (
        <section className="rounded-xl border bg-background p-6 shadow-sm">
            <div className="mb-4 flex items-center gap-2">
                <Icon className="size-5 text-muted-foreground" />
                <h2 className="text-lg font-semibold">{title}</h2>
            </div>
            {children}
        </section>
    );
}

export default function PortalDashboard({
    business,
    customer,
    paymentMethod,
    subscriptions,
    openInvoices,
}: Props) {
    return (
        <>
            <Head title={`Account · ${business.name}`} />

            <PortalShell business={business} customer={customer}>
                <Section title="Payment method" icon={CreditCard}>
                    {paymentMethod === null ? (
                        <p className="text-sm text-muted-foreground">
                            No card on file yet. A card is saved when you pay an
                            invoice or checkout link from {business.name}.
                        </p>
                    ) : (
                        <div className="flex items-center gap-3">
                            <CreditCard
                                className={
                                    paymentMethod.isExpired
                                        ? 'size-5 text-destructive'
                                        : 'size-5 text-muted-foreground'
                                }
                            />
                            <div>
                                <p className="font-medium">
                                    {paymentMethod.brand ?? 'Card'} ····{' '}
                                    {paymentMethod.last4 ?? '••••'}
                                </p>
                                <p
                                    className={
                                        paymentMethod.isExpired
                                            ? 'text-xs text-destructive'
                                            : 'text-xs text-muted-foreground'
                                    }
                                >
                                    {expiryLabel(paymentMethod)}
                                </p>
                            </div>
                        </div>
                    )}
                </Section>

                <Section title="Subscriptions" icon={RefreshCw}>
                    {subscriptions.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            You don&apos;t have any subscriptions with{' '}
                            {business.name} yet.
                        </p>
                    ) : (
                        <div className="divide-y">
                            {subscriptions.map((sub) => (
                                <div
                                    key={sub.publicId}
                                    className="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {sub.planLabel}
                                        </span>
                                        <SubscriptionStatusBadge
                                            status={sub.status}
                                        />
                                    </div>
                                    <span className="text-sm text-muted-foreground">
                                        {subscriptionDateLabel(sub)}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </Section>

                <Section title="Open invoices" icon={Receipt}>
                    {openInvoices.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No open invoices — you&apos;re all caught up.
                        </p>
                    ) : (
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
                                            {invoice.productsLabel} ·{' '}
                                            {formatDate(invoice.createdAt)}
                                            {invoice.dueAt && (
                                                <> · Due {formatDate(invoice.dueAt)}</>
                                            )}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <span className="text-sm font-medium">
                                            {formatMinor(
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
                    )}
                </Section>
            </PortalShell>
        </>
    );
}
