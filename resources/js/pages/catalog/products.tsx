import { Head, Link, router } from '@inertiajs/react';
import { Package, Plus, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ProductMonogram } from '@/components/catalog/product-monogram';
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
import { CATALOG_STATUS_META } from '@/lib/status-colors';
import { formatPriceInterval } from '@/lib/utils';
import { create, index as productsIndex, show } from '@/routes/catalog/products';
import type { CatalogProduct, CatalogStatus } from '@/types';

type Props = {
    products: CatalogProduct[];
    categories: string[];
    canManage: boolean;
};

function pricingSummary(product: CatalogProduct): string {
    const activePrices = product.prices.filter((p) => p.status === 'active');

    if (activePrices.length === 0) {
        return 'No price';
    }

    if (activePrices.length > 1) {
        return `${activePrices.length} prices`;
    }

    const [price] = activePrices;

    if (price.unitAmount !== null && price.billingInterval) {
        return formatPriceInterval(
            price.unitAmount,
            price.currency,
            price.billingInterval,
            price.billingFrequency,
        );
    }

    return 'Custom pricing';
}

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

export default function Products({ products, categories, canManage }: Props) {
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<'all' | CatalogStatus>('active');
    const [category, setCategory] = useState<string>('all');

    const filtered = useMemo(() => {
        return products.filter((product) => {
            if (status !== 'all' && product.status !== status) {
                return false;
            }

            if (category !== 'all' && product.category !== category) {
                return false;
            }

            if (
                search.trim() &&
                !product.name.toLowerCase().includes(search.trim().toLowerCase())
            ) {
                return false;
            }

            return true;
        });
    }, [products, search, status, category]);

    const hasAnyProducts = products.length > 0;
    const hasActiveFilters =
        search.trim() !== '' || status !== 'active' || category !== 'all';

    const clearFilters = () => {
        setSearch('');
        setStatus('active');
        setCategory('all');
    };

    return (
        <div className="flex max-w-5xl flex-col gap-6 p-4">
            <Head title="Products" />

            <div className="flex items-start justify-between gap-4">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold">Products</h1>
                    <p className="text-sm text-muted-foreground">
                        What you sell. Add pricing to a product to start
                        billing for it.
                    </p>
                </div>

                {canManage && hasAnyProducts && (
                    <Button asChild data-test="create-product-trigger">
                        <Link href={create()}>
                            <Plus /> Create product
                        </Link>
                    </Button>
                )}
            </div>

            {!hasAnyProducts ? (
                <EmptyState canManage={canManage} />
            ) : (
                <>
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="relative w-full max-w-xs">
                            <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search products…"
                                className="pl-8"
                                data-test="products-search"
                            />
                        </div>

                        <Select
                            value={status}
                            onValueChange={(value) =>
                                setStatus(value as 'all' | CatalogStatus)
                            }
                        >
                            <SelectTrigger
                                className="w-40"
                                data-test="status-filter"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All statuses
                                </SelectItem>
                                <SelectItem value="active">Active</SelectItem>
                                <SelectItem value="archived">
                                    Archived
                                </SelectItem>
                            </SelectContent>
                        </Select>

                        {categories.length > 0 && (
                            <Select value={category} onValueChange={setCategory}>
                                <SelectTrigger
                                    className="w-52"
                                    data-test="category-filter"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All categories
                                    </SelectItem>
                                    {categories.map((c) => (
                                        <SelectItem key={c} value={c}>
                                            {c}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}

                        {hasActiveFilters && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={clearFilters}
                            >
                                Clear filters
                            </Button>
                        )}
                    </div>

                    {filtered.length === 0 ? (
                        <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                            No products match your filters.
                        </div>
                    ) : (
                        <div className="rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow className="hover:bg-transparent">
                                        <TableHead>Name</TableHead>
                                        <TableHead>Pricing</TableHead>
                                        <TableHead>Category</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Created</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {filtered.map((product) => (
                                        <TableRow
                                            key={product.id}
                                            className="cursor-pointer"
                                            onClick={() =>
                                                router.visit(show(product.id))
                                            }
                                            data-test="product-row"
                                        >
                                            <TableCell>
                                                <div className="flex items-center gap-3">
                                                    <ProductMonogram
                                                        id={product.id}
                                                        name={product.name}
                                                        className="size-8 text-xs"
                                                    />
                                                    <span className="font-medium">
                                                        {product.name}
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {pricingSummary(product)}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {product.category ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant="secondary"
                                                    className="gap-1.5"
                                                >
                                                    <span
                                                        className={`size-1.5 rounded-full ${CATALOG_STATUS_META[product.status].dot}`}
                                                    />
                                                    {
                                                        CATALOG_STATUS_META[
                                                            product.status
                                                        ].label
                                                    }
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {formatDate(product.createdAt)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}

                    <p className="text-sm text-muted-foreground">
                        {filtered.length}{' '}
                        {filtered.length === 1 ? 'item' : 'items'}
                    </p>
                </>
            )}
        </div>
    );
}

function EmptyState({ canManage }: { canManage: boolean }) {
    return (
        <div className="space-y-4 rounded-lg border border-dashed p-8 text-center">
            <div className="mx-auto flex size-12 items-center justify-center rounded-full bg-muted">
                <Package className="size-6 text-muted-foreground" />
            </div>

            <div className="mx-auto max-w-md space-y-2">
                <p className="font-medium">Your catalog is empty</p>
                <p className="text-sm text-muted-foreground">
                    A product is something you sell — a plan, a tier, an
                    add-on. Every product can carry one or more prices, which
                    is what actually decides how a customer gets billed.
                </p>
                <p className="text-sm text-muted-foreground">
                    Once you add a price, your product is ready to attach to
                    a subscription — no separate "publish" step.
                </p>
            </div>

            {canManage && (
                <Button asChild data-test="create-first-product">
                    <Link href={create()}>
                        <Plus /> Create your first product
                    </Link>
                </Button>
            )}

            <p className="text-xs text-muted-foreground">
                Not sure where to start? A single "Pro" product with one
                monthly price covers most launches.
            </p>
        </div>
    );
}

Products.layout = () => ({
    breadcrumbs: [{ title: 'Products', href: productsIndex() }],
});
