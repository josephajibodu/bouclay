import { Head, router } from '@inertiajs/react';
import {
    Copy,
    MoreHorizontal,
    Plus,
    Search,
    Trash2,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import CreateCustomerDrawer from '@/components/customers/create-customer-drawer';
import { CustomerMonogram } from '@/components/customers/customer-monogram';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import { nonDefaultParams } from '@/lib/utils';
import { archive, bulkArchive, index, show } from '@/routes/customers';
import type {
    CustomerFilters,
    CustomerListItem,
    Paginated,
} from '@/types';

type Props = {
    customers: Paginated<CustomerListItem>;
    filters: CustomerFilters;
    hasAny: boolean;
    teamCurrency: string;
    canManage: boolean;
};

const DEFAULT_FILTERS: CustomerFilters = { search: '', status: 'active' };

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

async function copyId(value: string) {
    await navigator.clipboard.writeText(value);
    toast.success('Customer ID copied');
}

export default function CustomersIndex({
    customers,
    filters,
    hasAny,
    teamCurrency,
    canManage,
}: Props) {
    const [search, setSearch] = useState(filters.search);
    const [selected, setSelected] = useState<number[]>([]);
    const [createOpen, setCreateOpen] = useState(false);
    const isFirstRender = useRef(true);

    // Debounced server-side search — the list is paginated server-side
    // (CUSTOMERS_DESIGN §5.2, §14.2).
    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        const handle = window.setTimeout(() => {
            router.get(
                index().url,
                nonDefaultParams(
                    { search, status: filters.status },
                    DEFAULT_FILTERS,
                ),
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);

        return () => window.clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const rows = customers.data;

    // Selection can't outlive the rows it points at — after a filter or page
    // change, silently drop ids that are no longer on screen.
    const rowIds = new Set(rows.map((row) => row.id));
    const validSelected = selected.filter((id) => rowIds.has(id));
    const allSelected = rows.length > 0 && validSelected.length === rows.length;
    const hasActiveFilters = filters.search !== '' || filters.status !== 'active';

    const changeStatus = (status: string) => {
        router.get(
            index().url,
            nonDefaultParams({ search, status }, DEFAULT_FILTERS),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const toggleAll = () => {
        setSelected(allSelected ? [] : rows.map((row) => row.id));
    };

    const toggleRow = (id: number) => {
        setSelected((current) =>
            current.includes(id)
                ? current.filter((value) => value !== id)
                : [...current, id],
        );
    };

    const archiveSelected = () => {
        if (
            !window.confirm(
                `Archive ${validSelected.length} ${validSelected.length === 1 ? 'customer' : 'customers'}? They'll stop appearing in your active list and can't be subscribed to new plans. You can restore them anytime.`,
            )
        ) {
            return;
        }

        router.post(
            bulkArchive().url,
            { ids: validSelected },
            { preserveScroll: true, onSuccess: () => setSelected([]) },
        );
    };

    const archiveOne = (row: CustomerListItem) => {
        if (
            !window.confirm(
                `Archive this customer? They'll stop appearing in your active list and can't be subscribed to new plans. You can restore them anytime.`,
            )
        ) {
            return;
        }

        router.delete(archive(row.id).url, { preserveScroll: true });
    };

    return (
        <div className="flex max-w-5xl flex-col gap-6 p-4">
            <Head title="Customers" />

            <div className="flex items-start justify-between gap-4">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold">Customers</h1>
                    <p className="text-sm text-muted-foreground">
                        The people and businesses you bill.
                    </p>
                </div>

                {canManage && hasAny && (
                    <CreateCustomerDrawer
                        open={createOpen}
                        onOpenChange={setCreateOpen}
                        teamCurrency={teamCurrency}
                    >
                        <Button data-test="create-customer-trigger">
                            <Plus /> Create
                        </Button>
                    </CreateCustomerDrawer>
                )}
            </div>

            {!hasAny ? (
                <EmptyState
                    canManage={canManage}
                    teamCurrency={teamCurrency}
                    createOpen={createOpen}
                    setCreateOpen={setCreateOpen}
                />
            ) : (
                <>
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="relative w-full max-w-xs">
                            <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search name or email…"
                                className="pl-8"
                                data-test="customers-search"
                            />
                        </div>

                        <Select
                            value={filters.status}
                            onValueChange={changeStatus}
                        >
                            <SelectTrigger
                                className="w-36"
                                data-test="status-filter"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="active">Active</SelectItem>
                                <SelectItem value="archived">
                                    Archived
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    {rows.length === 0 ? (
                        <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                            {hasActiveFilters
                                ? 'No customers match your search.'
                                : 'No archived customers.'}
                        </div>
                    ) : (
                        <div className="rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow className="hover:bg-transparent">
                                        {canManage && (
                                            <TableHead className="w-10">
                                                <Checkbox
                                                    checked={allSelected}
                                                    onCheckedChange={toggleAll}
                                                    aria-label="Select all"
                                                />
                                            </TableHead>
                                        )}
                                        <TableHead>Customer</TableHead>
                                        <TableHead>Email</TableHead>
                                        <TableHead>Created</TableHead>
                                        <TableHead className="w-10" />
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
                                            data-test="customer-row"
                                        >
                                            {canManage && (
                                                <TableCell
                                                    onClick={(e) =>
                                                        e.stopPropagation()
                                                    }
                                                >
                                                    <Checkbox
                                                        checked={selected.includes(
                                                            row.id,
                                                        )}
                                                        onCheckedChange={() =>
                                                            toggleRow(row.id)
                                                        }
                                                        aria-label={`Select ${row.email}`}
                                                    />
                                                </TableCell>
                                            )}
                                            <TableCell>
                                                <div className="flex items-center gap-3">
                                                    <CustomerMonogram
                                                        id={row.id}
                                                        name={row.name}
                                                        email={row.email}
                                                        className="size-8 text-xs"
                                                    />
                                                    <span className="font-medium">
                                                        {row.name ?? row.email}
                                                    </span>
                                                    {row.status ===
                                                        'archived' && (
                                                        <Badge
                                                            variant="outline"
                                                            className="capitalize"
                                                        >
                                                            Archived
                                                        </Badge>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {row.email}
                                            </TableCell>
                                            <TableCell
                                                className="text-muted-foreground"
                                                title={row.createdAt ?? ''}
                                            >
                                                {formatDate(row.createdAt)}
                                            </TableCell>
                                            <TableCell
                                                onClick={(e) =>
                                                    e.stopPropagation()
                                                }
                                            >
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-8"
                                                        >
                                                            <MoreHorizontal />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuItem
                                                            onClick={() =>
                                                                copyId(
                                                                    row.publicId,
                                                                )
                                                            }
                                                        >
                                                            <Copy /> Copy ID
                                                        </DropdownMenuItem>
                                                        {canManage &&
                                                            row.status ===
                                                                'active' && (
                                                                <>
                                                                    <DropdownMenuSeparator />
                                                                    <DropdownMenuItem
                                                                        variant="destructive"
                                                                        onClick={() =>
                                                                            archiveOne(
                                                                                row,
                                                                            )
                                                                        }
                                                                    >
                                                                        <Trash2 />{' '}
                                                                        Archive
                                                                    </DropdownMenuItem>
                                                                </>
                                                            )}
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}

                    <Pagination customers={customers} search={search} status={filters.status} />
                </>
            )}

            {validSelected.length > 0 && (
                <div className="fixed bottom-6 left-1/2 z-50 flex -translate-x-1/2 items-center gap-4 rounded-lg bg-foreground px-4 py-3 text-background shadow-lg">
                    <span className="text-sm font-medium">
                        {validSelected.length} selected
                    </span>
                    <Button
                        variant="destructive"
                        size="sm"
                        onClick={archiveSelected}
                    >
                        <Trash2 /> Archive
                    </Button>
                    <button
                        type="button"
                        onClick={() => setSelected([])}
                        className="text-background/70 transition-colors hover:text-background"
                        aria-label="Clear selection"
                    >
                        <X className="size-4" />
                    </button>
                </div>
            )}
        </div>
    );
}

function Pagination({
    customers,
    search,
    status,
}: {
    customers: Paginated<CustomerListItem>;
    search: string;
    status: string;
}) {
    const go = (url: string | null) => {
        if (url) {
            router.get(
                url,
                nonDefaultParams({ search, status }, DEFAULT_FILTERS),
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }
    };

    return (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
            <span>
                {customers.total === 0
                    ? '0 customers'
                    : `${customers.from}–${customers.to} of ${customers.total}`}
            </span>

            {customers.last_page > 1 && (
                <div className="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={!customers.prev_page_url}
                        onClick={() => go(customers.prev_page_url)}
                    >
                        Previous
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={!customers.next_page_url}
                        onClick={() => go(customers.next_page_url)}
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
    teamCurrency,
    createOpen,
    setCreateOpen,
}: {
    canManage: boolean;
    teamCurrency: string;
    createOpen: boolean;
    setCreateOpen: (open: boolean) => void;
}) {
    return (
        <div className="space-y-4 rounded-lg border border-dashed p-8 text-center">
            <div className="mx-auto flex size-12 items-center justify-center rounded-full bg-muted">
                <Users className="size-6 text-muted-foreground" />
            </div>

            <div className="mx-auto max-w-md space-y-2">
                <p className="font-medium">No customers yet</p>
                <p className="text-sm text-muted-foreground">
                    Customers are the people and businesses you bill. Add one,
                    give them a payment method, and you can put them on a
                    subscription. Everything about a customer — cards,
                    addresses, payments — lives on their profile.
                </p>
            </div>

            {canManage && (
                <CreateCustomerDrawer
                    open={createOpen}
                    onOpenChange={setCreateOpen}
                    teamCurrency={teamCurrency}
                >
                    <Button data-test="create-first-customer">
                        <Plus /> Create your first customer
                    </Button>
                </CreateCustomerDrawer>
            )}

            <p className="text-xs text-muted-foreground">
                You only need a name and email to start. Customers are shared
                across test and live — only the keys used to charge differ.
            </p>
        </div>
    );
}

CustomersIndex.layout = () => ({
    breadcrumbs: [{ title: 'Customers', href: index() }],
});
