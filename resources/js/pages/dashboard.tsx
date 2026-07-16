import type { InertiaLinkProps } from '@inertiajs/react';
import { Head, Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Activity,
    Box,
    Check,
    ChevronRight,
    CreditCard,
    FileText,
    Receipt,
    Users,
} from 'lucide-react';
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    INVOICE_STATUS_COLOR,
    INVOICE_STATUS_LABEL,
} from '@/components/invoices/invoice-status';
import {
    PAYMENT_STATUS_COLOR,
    PAYMENT_STATUS_LABEL,
} from '@/components/invoices/payment-status';
import PendingInvitationsModal from '@/components/pending-invitations-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as productsIndex } from '@/routes/catalog/products';
import { index as customersIndex } from '@/routes/customers';
import { edit as editGeneralSettings } from '@/routes/general';
import { index as invoicesIndex, show as showInvoice } from '@/routes/invoices';
import { index as subscriptionsIndex } from '@/routes/subscriptions';
import type {
    DashboardInvitation,
    DashboardSummary,
    OnboardingState,
} from '@/types';

type Props = {
    pendingInvitations?: DashboardInvitation[];
    onboarding: OnboardingState | null;
    summary: DashboardSummary;
};

type ChecklistItem = {
    key: string;
    title: string;
    description: string;
    href: NonNullable<InertiaLinkProps['href']> | null;
    cta: string;
    done: boolean;
};

export default function Dashboard({
    pendingInvitations = [],
    onboarding,
    summary,
}: Props) {
    const { currentTeam } = usePage().props;
    const [showInvitations, setShowInvitations] = useState(
        pendingInvitations.length > 0,
    );

    const dismissKey = currentTeam
        ? `onboarding-dismissed-${currentTeam.slug}`
        : null;
    const toastedKey = currentTeam
        ? `onboarding-complete-toasted-${currentTeam.slug}`
        : null;

    const [dismissed, setDismissed] = useState(
        () =>
            (dismissKey && sessionStorage.getItem(dismissKey) === '1') ?? false,
    );
    const [expanded, setExpanded] = useState(false);

    const items: ChecklistItem[] = useMemo(() => {
        if (!onboarding) {
            return [];
        }

        return [
            {
                key: 'business',
                title: 'Confirm your business details',
                description: 'We use this for invoices and tax settings.',
                href: editGeneralSettings(),
                cta: 'Review',
                done: onboarding.businessConfirmed,
            },
            {
                key: 'gateway',
                title: 'Connect a payment gateway',
                description:
                    'Bouclay never touches your money — your gateway does. Start with test keys, no risk.',
                href: onboarding.links.gateways,
                cta: 'Connect',
                done: onboarding.gatewayConnected,
            },
            {
                key: 'apiKey',
                title: 'Generate a Bouclay API key',
                description: 'This is how your app talks to Bouclay.',
                href: onboarding.links.apiKeys,
                cta: 'Generate',
                done: onboarding.apiKeyGenerated,
            },
            {
                key: 'webhook',
                title: 'Confirm your webhook is reachable',
                description:
                    'So subscription events reach your app in real time.',
                href: onboarding.links.webhooks,
                cta: 'Verify',
                done: onboarding.webhookVerified,
            },
            {
                key: 'firstProduct',
                title: 'Create your first product',
                description:
                    "What you'll actually sell — add a price to start billing for it.",
                href: onboarding.links.products,
                cta: 'Create',
                done: onboarding.firstProductCreated,
            },
        ];
    }, [onboarding]);

    const doneCount = items.filter((item) => item.done).length;
    const allDone = onboarding !== null && doneCount === items.length;

    useEffect(() => {
        if (
            allDone &&
            toastedKey &&
            sessionStorage.getItem(toastedKey) !== '1'
        ) {
            toast.success("You're ready to build");
            sessionStorage.setItem(toastedKey, '1');
        }
    }, [allDone, toastedKey]);

    const dismiss = () => {
        setDismissed(true);
        setExpanded(false);

        if (dismissKey) {
            sessionStorage.setItem(dismissKey, '1');
        }
    };

    const showFullChecklist =
        onboarding !== null &&
        !allDone &&
        !dismissed &&
        (doneCount === 0 || expanded);
    const showBanner =
        onboarding !== null &&
        !allDone &&
        !dismissed &&
        doneCount > 0 &&
        !expanded;
    const showCompletePill = onboarding !== null && allDone && !expanded;
    const showCompletedCard = onboarding !== null && allDone && expanded;

    let onboardingView: React.ReactNode = null;

    if (showFullChecklist || showCompletedCard) {
        onboardingView = (
            <OnboardingChecklist
                key="checklist"
                teamName={currentTeam?.name ?? 'your business'}
                items={items}
                doneCount={doneCount}
                allDone={allDone}
                onDismiss={expanded ? () => setExpanded(false) : dismiss}
                onSkip={doneCount === 0 ? dismiss : undefined}
            />
        );
    } else if (showBanner) {
        onboardingView = (
            <OnboardingBanner
                key="banner"
                items={items}
                doneCount={doneCount}
                onExpand={() => setExpanded(true)}
                onDismiss={dismiss}
            />
        );
    } else if (showCompletePill) {
        onboardingView = (
            <button
                key="pill"
                type="button"
                onClick={() => setExpanded(true)}
                className="flex w-fit animate-in items-center gap-2 rounded-full border bg-muted/30 px-3 py-1.5 text-sm text-muted-foreground duration-300 ease-out fade-in slide-in-from-top-2 hover:bg-muted/60"
                data-test="onboarding-complete-pill"
            >
                <Check className="size-3.5 text-emerald-500" />
                Setup complete
            </button>
        );
    }

    return (
        <>
            <Head title="Overview" />
            <PendingInvitationsModal
                invitations={pendingInvitations}
                open={pendingInvitations.length > 0 && showInvitations}
                onOpenChange={setShowInvitations}
            />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <AnimatedHeight>{onboardingView}</AnimatedHeight>

                <OverviewSummary summary={summary} />
            </div>
        </>
    );
}

function OverviewSummary({ summary }: { summary: DashboardSummary }) {
    const metrics = [
        {
            key: 'revenue',
            label: 'Revenue',
            value: money(summary.revenueLast30, summary.currency),
            helper: `${summary.successfulPaymentsLast30} successful payments in 30 days`,
            icon: Receipt,
            href: invoicesIndex(),
            accent: 'border-emerald-200 bg-emerald-50/70 text-emerald-950 hover:bg-emerald-50 dark:border-emerald-900/60 dark:bg-emerald-950/20 dark:text-emerald-50',
            iconAccent:
                'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/60 dark:text-emerald-300',
        },
        {
            key: 'subscriptions',
            label: 'Active subscriptions',
            value: summary.activeSubscriptions.toLocaleString(),
            helper: `${summary.trialingSubscriptions} trialing · ${summary.pastDueSubscriptions} past due`,
            icon: Activity,
            href: subscriptionsIndex(),
            accent: 'border-blue-200 bg-blue-50/70 text-blue-950 hover:bg-blue-50 dark:border-blue-900/60 dark:bg-blue-950/20 dark:text-blue-50',
            iconAccent:
                'bg-blue-100 text-blue-700 dark:bg-blue-900/60 dark:text-blue-300',
        },
        {
            key: 'customers',
            label: 'Customers',
            value: summary.customers.toLocaleString(),
            helper: 'People and businesses you bill',
            icon: Users,
            href: customersIndex(),
            accent: 'border-violet-200 bg-violet-50/70 text-violet-950 hover:bg-violet-50 dark:border-violet-900/60 dark:bg-violet-950/20 dark:text-violet-50',
            iconAccent:
                'bg-violet-100 text-violet-700 dark:bg-violet-900/60 dark:text-violet-300',
        },
        {
            key: 'invoices',
            label: 'Awaiting payment',
            value: money(summary.openInvoiceAmountDue, summary.currency),
            helper: `${summary.openInvoices} open invoices`,
            icon: FileText,
            href: invoicesIndex({ query: { status: 'open' } }),
            accent: 'border-amber-200 bg-amber-50/70 text-amber-950 hover:bg-amber-50 dark:border-amber-900/60 dark:bg-amber-950/20 dark:text-amber-50',
            iconAccent:
                'bg-amber-100 text-amber-700 dark:bg-amber-900/60 dark:text-amber-300',
        },
    ];

    const hasRecentActivity =
        summary.recentPayments.length > 0 || summary.recentInvoices.length > 0;

    return (
        <div className="flex w-full max-w-7xl flex-col gap-6">
            <div className="flex items-start justify-between gap-4">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold">Overview</h1>
                    <p className="text-sm text-muted-foreground">
                        A quick read on your current billing setup and recent
                        customer activity.
                    </p>
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {metrics.map((metric) => (
                    <MetricCard
                        key={metric.key}
                        label={metric.label}
                        value={metric.value}
                        helper={metric.helper}
                        icon={metric.icon}
                        href={metric.href}
                        accent={metric.accent}
                        iconAccent={metric.iconAccent}
                    />
                ))}
            </div>

            <div className="grid gap-4 xl:grid-cols-[minmax(28rem,0.95fr)_minmax(0,1.4fr)]">
                <div className="rounded-xl border border-indigo-200 bg-indigo-50/60 p-6 dark:border-indigo-900/60 dark:bg-indigo-950/20">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <h2 className="font-semibold text-indigo-950 dark:text-indigo-50">
                                Catalog readiness
                            </h2>
                            <p className="max-w-sm text-sm text-indigo-900/70 dark:text-indigo-200/70">
                                Products and prices available for billing.
                            </p>
                        </div>
                        <Button asChild variant="outline" size="sm">
                            <Link href={productsIndex()}>View catalog</Link>
                        </Button>
                    </div>

                    <div className="mt-6 grid gap-3 sm:grid-cols-2">
                        <Fact
                            icon={Box}
                            label="Active products"
                            value={summary.activeProducts.toLocaleString()}
                            className="border-indigo-200 bg-white/70 text-indigo-950 dark:border-indigo-900/60 dark:bg-background/40 dark:text-indigo-50"
                        />
                        <Fact
                            icon={CreditCard}
                            label="Active prices"
                            value={summary.activePrices.toLocaleString()}
                            className="border-sky-200 bg-white/70 text-sky-950 dark:border-sky-900/60 dark:bg-background/40 dark:text-sky-50"
                        />
                    </div>
                </div>

                <RecentPayments summary={summary} />
            </div>

            <RecentInvoices summary={summary} />

            {!hasRecentActivity && (
                <div className="rounded-xl border border-dashed p-6 text-sm text-muted-foreground">
                    Once you create invoices or collect payments, recent billing
                    activity will appear here.
                </div>
            )}
        </div>
    );
}

function MetricCard({
    label,
    value,
    helper,
    icon: Icon,
    href,
    accent,
    iconAccent,
}: {
    label: string;
    value: string;
    helper: string;
    icon: LucideIcon;
    href: NonNullable<InertiaLinkProps['href']>;
    accent: string;
    iconAccent: string;
}) {
    return (
        <Link
            href={href}
            className={cn('rounded-xl border p-5 transition-colors', accent)}
        >
            <div className="flex items-start justify-between gap-4">
                <div className="space-y-2">
                    <p className="text-sm opacity-70">{label}</p>
                    <p className="text-2xl font-semibold">{value}</p>
                </div>
                <span className={cn('rounded-full p-2', iconAccent)}>
                    <Icon className="size-4" />
                </span>
            </div>
            <p className="mt-4 text-sm opacity-70">{helper}</p>
        </Link>
    );
}

function Fact({
    icon: Icon,
    label,
    value,
    className,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    className?: string;
}) {
    return (
        <div className={cn('rounded-lg border p-4', className)}>
            <div className="flex items-center gap-2 text-sm opacity-70">
                <Icon className="size-4" />
                {label}
            </div>
            <p className="mt-3 text-2xl font-semibold">{value}</p>
        </div>
    );
}

function RecentPayments({ summary }: { summary: DashboardSummary }) {
    return (
        <div className="rounded-xl border p-6">
            <div className="flex items-center justify-between gap-4">
                <div>
                    <h2 className="font-semibold">Recent payments</h2>
                    <p className="text-sm text-muted-foreground">
                        Latest charge attempts across invoices.
                    </p>
                </div>
            </div>

            {summary.recentPayments.length === 0 ? (
                <EmptyActivity icon={CreditCard} label="No payments yet" />
            ) : (
                <div className="mt-4 divide-y rounded-lg border">
                    {summary.recentPayments.map((payment) => (
                        <div
                            key={payment.id}
                            className="flex items-center justify-between gap-4 p-4"
                        >
                            <div className="min-w-0">
                                <p className="truncate font-medium">
                                    {payment.customer.name ??
                                        payment.customer.email}
                                </p>
                                <p className="truncate text-sm text-muted-foreground">
                                    {payment.productsLabel}
                                </p>
                            </div>
                            <div className="shrink-0 text-right">
                                <p className="font-medium">
                                    {money(payment.amount, payment.currency)}
                                </p>
                                <StatusBadge
                                    label={PAYMENT_STATUS_LABEL[payment.status]}
                                    color={PAYMENT_STATUS_COLOR[payment.status]}
                                />
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

function RecentInvoices({ summary }: { summary: DashboardSummary }) {
    return (
        <div className="rounded-xl border p-6">
            <div className="flex items-center justify-between gap-4">
                <div>
                    <h2 className="font-semibold">Recent invoices</h2>
                    <p className="text-sm text-muted-foreground">
                        The newest bills created for your customers.
                    </p>
                </div>
                <Button asChild variant="outline" size="sm">
                    <Link href={invoicesIndex()}>View invoices</Link>
                </Button>
            </div>

            {summary.recentInvoices.length === 0 ? (
                <EmptyActivity icon={FileText} label="No invoices yet" />
            ) : (
                <div className="mt-4 divide-y rounded-lg border">
                    {summary.recentInvoices.map((invoice) => (
                        <Link
                            key={invoice.id}
                            href={showInvoice(invoice.id)}
                            className="flex items-center justify-between gap-4 p-4 transition-colors hover:bg-muted/30"
                        >
                            <div className="min-w-0">
                                <p className="truncate font-medium">
                                    {invoice.number ?? invoice.publicId}
                                </p>
                                <p className="truncate text-sm text-muted-foreground">
                                    {invoice.customer.name ??
                                        invoice.customer.email}
                                </p>
                            </div>
                            <div className="shrink-0 text-right">
                                <p className="font-medium">
                                    {money(invoice.total, invoice.currency)}
                                </p>
                                <StatusBadge
                                    label={INVOICE_STATUS_LABEL[invoice.status]}
                                    color={INVOICE_STATUS_COLOR[invoice.status]}
                                />
                            </div>
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}

function StatusBadge({ label, color }: { label: string; color: string }) {
    return (
        <Badge variant="secondary" className="mt-1 gap-1.5">
            <span className={cn('size-1.5 rounded-full', color)} />
            {label}
        </Badge>
    );
}

function EmptyActivity({
    icon: Icon,
    label,
}: {
    icon: LucideIcon;
    label: string;
}) {
    return (
        <div className="mt-4 flex items-center gap-3 rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
            <span className="rounded-full bg-muted p-2">
                <Icon className="size-4" />
            </span>
            {label}
        </div>
    );
}

function money(amount: number, currency: string): string {
    return `${currency} ${(amount / 100).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

/**
 * Animates height and opacity when its child changes or becomes null, so
 * swapping/hiding onboarding views resizes smoothly instead of jumping.
 * Keeps rendering the outgoing child for one transition cycle so it fades
 * out in place rather than vanishing before the collapse finishes.
 */
function AnimatedHeight({ children }: { children: React.ReactNode }) {
    const innerRef = useRef<HTMLDivElement>(null);
    const isVisible = Boolean(children);

    const [wasVisible, setWasVisible] = useState(isVisible);
    const [renderedChildren, setRenderedChildren] = useState(children);
    const [height, setHeight] = useState(0);

    if (isVisible !== wasVisible) {
        setWasVisible(isVisible);

        if (!isVisible) {
            setHeight(0);
        }
    }

    if (isVisible && children !== renderedChildren) {
        setRenderedChildren(children);
    }

    useEffect(() => {
        if (isVisible) {
            return;
        }

        const timeout = setTimeout(() => setRenderedChildren(null), 300);

        return () => clearTimeout(timeout);
    }, [isVisible]);

    useLayoutEffect(() => {
        const node = innerRef.current;

        if (!node || !isVisible) {
            return;
        }

        setHeight(node.scrollHeight);
    }, [renderedChildren, isVisible]);

    return (
        <div
            className="overflow-hidden transition-[height,opacity] duration-300 ease-in-out"
            style={{ height, opacity: isVisible ? 1 : 0 }}
        >
            <div ref={innerRef}>{renderedChildren}</div>
        </div>
    );
}

function OnboardingChecklist({
    teamName,
    items,
    doneCount,
    allDone,
    onDismiss,
    onSkip,
}: {
    teamName: string;
    items: ChecklistItem[];
    doneCount: number;
    allDone: boolean;
    onDismiss?: () => void;
    onSkip?: () => void;
}) {
    return (
        <div
            className="animate-in space-y-4 rounded-xl border p-6 duration-300 ease-out fade-in slide-in-from-top-2"
            data-test="onboarding-checklist"
        >
            <div className="flex items-start justify-between gap-4">
                <div className="space-y-1">
                    <h2 className="text-lg font-semibold">
                        Welcome to {teamName}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Let's get you ready to accept real subscriptions.
                    </p>
                </div>
                {onDismiss && (
                    <Button variant="ghost" size="sm" onClick={onDismiss}>
                        {allDone ? 'Collapse' : 'Dismiss'}
                    </Button>
                )}
            </div>

            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <div className="flex gap-1">
                    {items.map((item) => (
                        <span
                            key={item.key}
                            className={cn(
                                'size-2 rounded-full transition-colors duration-300',
                                item.done ? 'bg-emerald-500' : 'bg-muted',
                            )}
                        />
                    ))}
                </div>
                {doneCount} of {items.length} done
            </div>

            <div className="divide-y rounded-lg border">
                {items.map((item) => (
                    <div
                        key={item.key}
                        className="flex items-center justify-between gap-4 p-4"
                        data-test={`onboarding-item-${item.key}`}
                    >
                        <div className="flex items-start gap-3">
                            <span
                                className={cn(
                                    'mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border transition-colors duration-300',
                                    item.done &&
                                        'border-emerald-500 bg-emerald-500 text-white',
                                )}
                            >
                                {item.done && (
                                    <Check className="size-3 animate-in duration-200 spin-in-45 zoom-in" />
                                )}
                            </span>
                            <div>
                                <p className="font-medium">{item.title}</p>
                                <p className="text-sm text-muted-foreground">
                                    {item.description}
                                </p>
                            </div>
                        </div>

                        {item.href && !item.done ? (
                            <Button asChild variant="outline" size="sm">
                                <Link href={item.href}>
                                    {item.cta} <ChevronRight />
                                </Link>
                            </Button>
                        ) : item.done ? (
                            <span className="text-sm text-muted-foreground">
                                Done
                            </span>
                        ) : null}
                    </div>
                ))}
            </div>

            {onSkip && (
                <button
                    type="button"
                    onClick={onSkip}
                    className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    data-test="onboarding-skip"
                >
                    Skip for now, I'll explore first →
                </button>
            )}
        </div>
    );
}

function OnboardingBanner({
    items,
    doneCount,
    onExpand,
    onDismiss,
}: {
    items: ChecklistItem[];
    doneCount: number;
    onExpand: () => void;
    onDismiss: () => void;
}) {
    const nextItem = items.find((item) => !item.done);

    return (
        <div
            className="flex animate-in items-center justify-between gap-4 rounded-lg border bg-muted/30 px-4 py-2 duration-300 ease-out fade-in slide-in-from-top-2"
            data-test="onboarding-banner"
        >
            <button
                type="button"
                onClick={onExpand}
                className="flex items-center gap-2 text-sm"
            >
                <div className="flex gap-1">
                    {items.map((item) => (
                        <span
                            key={item.key}
                            className={cn(
                                'size-2 rounded-full transition-colors duration-300',
                                item.done
                                    ? 'bg-emerald-500'
                                    : 'bg-muted-foreground/30',
                            )}
                        />
                    ))}
                </div>
                <span className="font-medium">
                    {doneCount} of {items.length} · Finish setup
                </span>
            </button>

            <div className="flex items-center gap-2">
                {nextItem?.href && (
                    <Button asChild variant="ghost" size="sm">
                        <Link href={nextItem.href}>
                            {nextItem.cta} <ChevronRight />
                        </Link>
                    </Button>
                )}
                <Button variant="ghost" size="sm" onClick={onDismiss}>
                    Dismiss
                </Button>
            </div>
        </div>
    );
}

Dashboard.layout = () => ({
    breadcrumbs: [{ title: 'Overview', href: dashboard() }],
});
