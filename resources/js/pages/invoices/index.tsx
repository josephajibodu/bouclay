import { Head, router } from '@inertiajs/react';
import { Receipt, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import CreateInvoiceDrawer from '@/components/invoices/create-invoice-drawer';
import {
    INVOICE_STATUS_COLOR,
    INVOICE_STATUS_LABEL,
} from '@/components/invoices/invoice-status';
import { Badge } from '@/components/ui/badge';
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
import { index, show } from '@/routes/invoices';
import type {
    CreateCustomerOption,
    CreateProductOption,
    InvoiceFilters,
    InvoiceListItem,
    Paginated,
} from '@/types';

type Props = {
    invoices: Paginated<InvoiceListItem>;
    filters: InvoiceFilters;
    hasAny: boolean;
    customers: CreateCustomerOption[];
    products: CreateProductOption[];
    teamCurrency: string;
    canManage: boolean;
};

const STATUS_OPTIONS: { value: string; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'open', label: 'Awaiting payment' },
    { value: 'paid', label: 'Paid' },
    { value: 'void', label: 'Void' },
    { value: 'uncollectible', label: 'Uncollectible' },
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

function money(amount: number, currency: string): string {
    return `${currency} ${(amount / 100).toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
}

function dateLabel(row: InvoiceListItem): string {
    if (row.paidAt) {
        return `Paid ${formatDate(row.paidAt)}`;
    }

    if (row.dueAt) {
        return `Due ${formatDate(row.dueAt)}`;
    }

    return formatDate(row.createdAt);
}

export default function InvoicesIndex({
    invoices,
    filters,
    hasAny,
    customers,
    products,
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

    const rows = invoices.data;
    const hasActiveFilters =
        filters.search !== '' || filters.status !== 'all';

    const changeStatus = (status: string) => {
        router.get(
            index().url,
            { search, status },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <div className="flex max-w-5xl flex-col gap-6 p-4">
            <Head title="Invoices" />

            <div className="flex items-start justify-between gap-4">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold">Invoices</h1>
                    <p className="text-sm text-muted-foreground">
                        Every bill sent to your customers — one-off or from a
                        subscription, paid or still awaiting payment.
                    </p>
                </div>

                {canManage && hasAny && (
                    <Button
                        onClick={() => setCreateOpen(true)}
                        data-test="create-invoice"
                    >
                        <Receipt /> New invoice
                    </Button>
                )}
            </div>

            {!hasAny ? (
                <EmptyState
                    canManage={canManage}
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
                                placeholder="Search by customer, email, or invoice number"
                                className="pl-8"
                                data-test="invoices-search"
                            />
                        </div>

                        <Select value={filters.status} onValueChange={changeStatus}>
                            <SelectTrigger className="w-40" data-test="status-filter">
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
                                ? 'No invoices match your search or filters.'
                                : 'No invoices here yet.'}
                        </div>
                    ) : (
                        <div className="rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow className="hover:bg-transparent">
                                        <TableHead>Invoice</TableHead>
                                        <TableHead>Customer</TableHead>
                                        <TableHead>Amount</TableHead>
                                        <TableHead>Method</TableHead>
                                        <TableHead>Due / Paid</TableHead>
                                        <TableHead>Status</TableHead>
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
                                            data-test="invoice-row"
                                        >
                                            <TableCell className="max-w-48 truncate font-medium">
                                                {row.number ?? row.publicId}
                                            </TableCell>
                                            <TableCell>
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium">
                                                        {row.customer.name ??
                                                            row.customer.email}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {row.customer.email}
                                                    </p>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {money(row.total, row.currency)}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {row.paymentMethodLabel}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {dateLabel(row)}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant="secondary"
                                                    className="gap-1"
                                                >
                                                    <span
                                                        className={`size-1.5 rounded-full ${INVOICE_STATUS_COLOR[row.status]}`}
                                                    />
                                                    {INVOICE_STATUS_LABEL[row.status]}
                                                </Badge>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}

                    <Pagination
                        invoices={invoices}
                        search={search}
                        status={filters.status}
                    />
                </>
            )}

            {canManage && (
                <CreateInvoiceDrawer
                    customers={customers}
                    products={products}
                    teamCurrency={teamCurrency}
                    open={createOpen}
                    onOpenChange={setCreateOpen}
                />
            )}
        </div>
    );
}

function Pagination({
    invoices,
    search,
    status,
}: {
    invoices: Paginated<InvoiceListItem>;
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
                {invoices.total === 0
                    ? '0 invoices'
                    : `${invoices.from}–${invoices.to} of ${invoices.total}`}
            </span>

            {invoices.last_page > 1 && (
                <div className="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={!invoices.prev_page_url}
                        onClick={() => go(invoices.prev_page_url)}
                    >
                        Previous
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={!invoices.next_page_url}
                        onClick={() => go(invoices.next_page_url)}
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
    onCreate,
}: {
    canManage: boolean;
    onCreate: () => void;
}) {
    return (
        <div className="space-y-4 rounded-lg border border-dashed p-8 text-center">
            <div className="mx-auto flex size-12 items-center justify-center rounded-full bg-muted">
                <Receipt className="size-6 text-muted-foreground" />
            </div>

            <div className="mx-auto max-w-md space-y-2">
                <p className="font-medium">No invoices yet</p>
                <p className="text-sm text-muted-foreground">
                    An invoice is a bill sent to a customer — a one-off charge
                    you create here, or a subscription renewal. It appears the
                    moment it&apos;s created, whether or not it&apos;s been paid
                    yet.
                </p>
            </div>

            {canManage && (
                <Button onClick={onCreate} data-test="create-first-invoice">
                    <Receipt /> Create your first invoice
                </Button>
            )}
        </div>
    );
}

InvoicesIndex.layout = () => ({
    breadcrumbs: [{ title: 'Invoices', href: index() }],
});
