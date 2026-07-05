import { Head, Link, router } from '@inertiajs/react';
import { RefreshCw, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { CustomerMonogram } from '@/components/customers/customer-monogram';
import CreateSubscriptionDrawer from '@/components/subscriptions/create-subscription-drawer';
import { SubscriptionStatusBadge } from '@/components/subscriptions/subscription-status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index as products } from '@/routes/catalog/products';
import { index, show } from '@/routes/subscriptions';
import type {
    CreateCustomerOption,
    CreateProductOption,
    CreateTrialOfferOption,
    Paginated,
    SubscriptionFilters,
    SubscriptionListItem,
} from '@/types';

type Props = {
    subscriptions: Paginated<SubscriptionListItem>;
    filters: SubscriptionFilters;
    hasAny: boolean;
    hasRecurringPrices: boolean;
    customers: CreateCustomerOption[];
    products: CreateProductOption[];
    trialOffers: CreateTrialOfferOption[];
    teamCurrency: string;
    canManage: boolean;
};

const STATUS_OPTIONS: { value: string; label: string }[] = [
    { value: 'all_active', label: 'All active' },
    { value: 'trialing', label: 'On trial' },
    { value: 'active', label: 'Active' },
    { value: 'past_due', label: 'Past due' },
    { value: 'paused', label: 'Paused' },
    { value: 'canceled', label: 'Canceled' },
    { value: 'incomplete', label: 'Awaiting payment' },
    { value: 'all', label: 'All' },
];

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

/** The "Next billing" cell doubles as the trial-end signal (§6.2). */
function nextBilling(row: SubscriptionListItem): string {
    if (row.status === 'trialing' && row.trialEndsAt) {
        return `${formatDate(row.trialEndsAt)} (trial)`;
    }

    if (row.status === 'incomplete' || row.status === 'canceled') {
        return '—';
    }

    return formatDate(row.currentPeriodEnd);
}

export default function SubscriptionsIndex({
    subscriptions,
    filters,
    hasAny,
    hasRecurringPrices,
    customers,
    products: productOptions,
    trialOffers,
    teamCurrency,
    canManage,
}: Props) {
    const [search, setSearch] = useState(filters.search);
    const [createOpen, setCreateOpen] = useState(false);
    const isFirstRender = useRef(true);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        const handle = window.setTimeout(() => {
            router.get(
                index().url,
                { search, status: filters.status },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);

        return () => window.clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const rows = subscriptions.data;
    const hasActiveFilters =
        filters.search !== '' || filters.status !== 'all_active';

    const changeStatus = (status: string) => {
        router.get(
            index().url,
            { search, status },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <div className="flex max-w-5xl flex-col gap-6 p-4">
            <Head title="Subscriptions" />

            <div className="flex items-start justify-between gap-4">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold">Subscriptions</h1>
                    <p className="text-sm text-muted-foreground">
                        Recurring plans billing your customers on a schedule.
                    </p>
                </div>

                {canManage && hasAny && (
                    <Button
                        onClick={() => setCreateOpen(true)}
                        data-test="create-subscription"
                    >
                        <RefreshCw /> New subscription
                    </Button>
                )}
            </div>

            {!hasAny ? (
                <EmptyState
                    canManage={canManage}
                    hasRecurringPrices={hasRecurringPrices}
                    onCreate={() => setCreateOpen(true)}
                />
            ) : (
                <>
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="relative w-full max-w-xs">
                            <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search customer, plan, or ID…"
                                className="pl-8"
                                data-test="subscriptions-search"
                            />
                        </div>

                        <Select
                            value={filters.status}
                            onValueChange={changeStatus}
                        >
                            <SelectTrigger
                                className="w-40"
                                data-test="status-filter"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {STATUS_OPTIONS.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {rows.length === 0 ? (
                        <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                            {hasActiveFilters
                                ? 'No subscriptions match your search.'
                                : 'No subscriptions here yet.'}
                        </div>
                    ) : (
                        <div className="rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow className="hover:bg-transparent">
                                        <TableHead>Customer</TableHead>
                                        <TableHead>Plan</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Next billing</TableHead>
                                        <TableHead>Created</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map((row) => (
                                        <TableRow
                                            key={row.id}
                                            className="cursor-pointer"
                                            onClick={() =>
                                                router.visit(show(row.id))
                                            }
                                            data-test="subscription-row"
                                        >
                                            <TableCell>
                                                <div className="flex items-center gap-3">
                                                    <CustomerMonogram
                                                        id={row.customer.id}
                                                        name={row.customer.name}
                                                        email={
                                                            row.customer.email
                                                        }
                                                        className="size-8 text-xs"
                                                    />
                                                    <div className="min-w-0">
                                                        <p className="truncate font-medium">
                                                            {row.customer.name ??
                                                                row.customer
                                                                    .email}
                                                        </p>
                                                        <p className="truncate text-xs text-muted-foreground">
                                                            {row.customer.email}
                                                        </p>
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {row.planLabel}
                                            </TableCell>
                                            <TableCell>
                                                <div className="space-y-1">
                                                    <SubscriptionStatusBadge
                                                        status={row.status}
                                                    />
                                                    {row.cancelsAt && (
                                                        <p className="text-xs text-amber-600 dark:text-amber-500">
                                                            cancels{' '}
                                                            {formatDate(
                                                                row.cancelsAt,
                                                            )}
                                                        </p>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {nextBilling(row)}
                                            </TableCell>
                                            <TableCell
                                                className="text-muted-foreground"
                                                title={row.createdAt ?? ''}
                                            >
                                                {formatDate(row.createdAt)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}

                    <Pagination
                        subscriptions={subscriptions}
                        search={search}
                        status={filters.status}
                    />
                </>
            )}

            {canManage && (
                <CreateSubscriptionDrawer
                    customers={customers}
                    products={productOptions}
                    trialOffers={trialOffers}
                    teamCurrency={teamCurrency}
                    open={createOpen}
                    onOpenChange={setCreateOpen}
                />
            )}
        </div>
    );
}

function Pagination({
    subscriptions,
    search,
    status,
}: {
    subscriptions: Paginated<SubscriptionListItem>;
    search: string;
    status: string;
}) {
    const go = (url: string | null) => {
        if (url) {
            router.get(
                url,
                { search, status },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }
    };

    return (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
            <span>
                {subscriptions.total === 0
                    ? '0 subscriptions'
                    : `${subscriptions.from}–${subscriptions.to} of ${subscriptions.total}`}
            </span>

            {subscriptions.last_page > 1 && (
                <div className="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={!subscriptions.prev_page_url}
                        onClick={() => go(subscriptions.prev_page_url)}
                    >
                        Previous
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={!subscriptions.next_page_url}
                        onClick={() => go(subscriptions.next_page_url)}
                    >
                        Next
                    </Button>
                </div>
            )}
        </div>
    );
}

function EmptyState({
    canManage,
    hasRecurringPrices,
    onCreate,
}: {
    canManage: boolean;
    hasRecurringPrices: boolean;
    onCreate: () => void;
}) {
    return (
        <div className="space-y-4 rounded-lg border border-dashed p-8 text-center">
            <div className="mx-auto flex size-12 items-center justify-center rounded-full bg-muted">
                <RefreshCw className="size-6 text-muted-foreground" />
            </div>

            <div className="mx-auto max-w-md space-y-2">
                <p className="font-medium">No subscriptions yet</p>
                <p className="text-sm text-muted-foreground">
                    A subscription bills a customer for one or more of your
                    prices on a repeating schedule — and keeps charging their
                    card until it's canceled. It's how recurring revenue works
                    in Bouclay.
                </p>
            </div>

            {canManage &&
                (hasRecurringPrices ? (
                    <Button onClick={onCreate} data-test="create-first-subscription">
                        <RefreshCw /> Create your first subscription
                    </Button>
                ) : (
                    <div className="space-y-2">
                        <Button asChild variant="outline">
                            <Link href={products()}>Create a price first</Link>
                        </Button>
                        <p className="text-xs text-muted-foreground">
                            Subscriptions bill a recurring price — create one in
                            your catalog to get started.
                        </p>
                    </div>
                ))}
        </div>
    );
}

SubscriptionsIndex.layout = () => ({
    breadcrumbs: [{ title: 'Subscriptions', href: index() }],
});
