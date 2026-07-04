import { Head, Link } from '@inertiajs/react';
import { Package, Plus, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ProductMonogram } from '@/components/catalog/product-monogram';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatPriceInterval } from '@/lib/utils';
import { create, index as productsIndex, show } from '@/routes/catalog/products';
import type { CatalogProduct, CatalogStatus } from '@/types';

type Props = {
    products: CatalogProduct[];
    categories: string[];
    canManage: boolean;
};

export default function Products({ products, categories, canManage }: Props) {
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<'all' | CatalogStatus>('all');
    const [category, setCategory] = useState<string | null>(null);

    const filtered = useMemo(() => {
        return products.filter((product) => {
            if (status !== 'all' && product.status !== status) {
                return false;
            }

            if (category && product.category !== category) {
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
    const hasActiveFilters = search.trim() !== '' || status !== 'all' || category !== null;

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

                        <ToggleGroup
                            type="single"
                            variant="outline"
                            value={status}
                            onValueChange={(value) =>
                                value && setStatus(value as 'all' | CatalogStatus)
                            }
                        >
                            <ToggleGroupItem
                                value="all"
                                className="data-[state=on]:border-primary data-[state=on]:bg-primary data-[state=on]:text-primary-foreground"
                            >
                                All
                            </ToggleGroupItem>
                            <ToggleGroupItem
                                value="active"
                                className="data-[state=on]:border-primary data-[state=on]:bg-primary data-[state=on]:text-primary-foreground"
                            >
                                Active
                            </ToggleGroupItem>
                            <ToggleGroupItem
                                value="archived"
                                className="data-[state=on]:border-primary data-[state=on]:bg-primary data-[state=on]:text-primary-foreground"
                            >
                                Archived
                            </ToggleGroupItem>
                        </ToggleGroup>

                        {categories.length > 0 && (
                            <div className="flex flex-wrap gap-1.5">
                                {categories.map((c) => (
                                    <Badge
                                        key={c}
                                        variant={
                                            category === c
                                                ? 'default'
                                                : 'secondary'
                                        }
                                        className="cursor-pointer"
                                        onClick={() =>
                                            setCategory(
                                                category === c ? null : c,
                                            )
                                        }
                                    >
                                        {c}
                                    </Badge>
                                ))}
                            </div>
                        )}
                    </div>

                    {filtered.length === 0 ? (
                        <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                            {hasActiveFilters ? (
                                <div className="space-y-3">
                                    <p>No products match your filters.</p>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setSearch('');
                                            setStatus('all');
                                            setCategory(null);
                                        }}
                                    >
                                        Clear filters
                                    </Button>
                                </div>
                            ) : (
                                <p>No products yet.</p>
                            )}
                        </div>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {filtered.map((product) => (
                                <ProductCard
                                    key={product.id}
                                    product={product}
                                />
                            ))}
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

function ProductCard({ product }: { product: CatalogProduct }) {
    const activePrices = product.prices.filter((p) => p.status === 'active');
    const [firstPrice, ...rest] = activePrices;

    return (
        <Link href={show(product.id)} data-test="product-card">
            <Card className="h-full gap-3 p-4 transition-colors hover:border-foreground/20">
                <div className="flex items-start gap-3">
                    <ProductMonogram id={product.id} name={product.name} />
                    <div className="min-w-0 flex-1 space-y-1">
                        <p className="truncate font-medium">
                            {product.name}
                        </p>
                        <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Badge
                                variant={
                                    product.status === 'active'
                                        ? 'secondary'
                                        : 'outline'
                                }
                                className="capitalize"
                            >
                                {product.status}
                            </Badge>
                            <span>
                                ·{' '}
                                {activePrices.length === 0
                                    ? 'no prices'
                                    : `${activePrices.length} price${activePrices.length === 1 ? '' : 's'}`}
                            </span>
                        </div>
                    </div>
                </div>

                <div className="text-sm">
                    {firstPrice ? (
                        <span className="font-medium">
                            {firstPrice.unitAmount !== null &&
                            firstPrice.billingInterval
                                ? formatPriceInterval(
                                      firstPrice.unitAmount,
                                      firstPrice.currency,
                                      firstPrice.billingInterval,
                                      firstPrice.billingFrequency,
                                  )
                                : 'Custom pricing'}
                            {rest.length > 0 && (
                                <span className="font-normal text-muted-foreground">
                                    {' '}
                                    + {rest.length} more
                                </span>
                            )}
                        </span>
                    ) : (
                        <span className="text-muted-foreground">
                            No price yet
                        </span>
                    )}
                </div>
            </Card>
        </Link>
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
