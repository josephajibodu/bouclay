import { Head, Link } from '@inertiajs/react';
import {
    Check,
    ChevronLeft,
    Copy,
    Gift,
    MoreHorizontal,
    Pencil,
    Plus,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import ArchivePriceModal from '@/components/catalog/archive-price-modal';
import ArchiveProductModal from '@/components/catalog/archive-product-modal';
import CreatePriceDrawer from '@/components/catalog/create-price-drawer';
import EditMetadataDrawer from '@/components/catalog/edit-metadata-drawer';
import EditPriceDrawer from '@/components/catalog/edit-price-drawer';
import EditProductDrawer from '@/components/catalog/edit-product-drawer';
import PriceDetailDrawer from '@/components/catalog/price-detail-drawer';
import { ProductMonogram } from '@/components/catalog/product-monogram';
import RemoveTrialModal from '@/components/catalog/remove-trial-modal';
import TrialDrawer from '@/components/catalog/trial-drawer';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import {
    formatPriceInterval,
    formatMoney,
    toMonthlyEquivalent,
} from '@/lib/utils';
import { index as productsIndex } from '@/routes/catalog/products';
import type { OtherProduct, Price, ProductDetail, TrialOffer } from '@/types';

async function copyToClipboard(value: string, label: string) {
    await navigator.clipboard.writeText(value);
    toast.success(`${label} copied`);
}

type Props = {
    product: ProductDetail;
    prices: Price[];
    trials: TrialOffer[];
    otherProducts: OtherProduct[];
    permissions: {
        canManageProducts: boolean;
        canManagePrices: boolean;
        canManageTrialOffers: boolean;
    };
};

function priceLabel(price: Price): string {
    if (price.name) {
        return price.name;
    }

    if (price.pricingModel === 'graduated') {
        return 'Graduated price';
    }

    if (price.unitAmount !== null && price.billingInterval) {
        return formatPriceInterval(
            price.unitAmount,
            price.currency,
            price.billingInterval,
            price.billingFrequency,
        );
    }

    return 'Price';
}

export default function ProductShow({
    product,
    prices,
    trials,
    otherProducts,
    permissions,
}: Props) {
    const [copied, setCopied] = useState(false);
    const [editProductOpen, setEditProductOpen] = useState(false);
    const [archiveProductOpen, setArchiveProductOpen] = useState(false);
    const [editMetadataOpen, setEditMetadataOpen] = useState(false);
    const [createPriceOpen, setCreatePriceOpen] = useState(false);
    const [createTrialOpen, setCreateTrialOpen] = useState(false);
    const [editPriceTarget, setEditPriceTarget] = useState<Price | null>(null);
    const [archivePriceTarget, setArchivePriceTarget] = useState<Price | null>(
        null,
    );
    const [detailPriceTarget, setDetailPriceTarget] = useState<Price | null>(
        null,
    );
    const [trialRowTarget, setTrialRowTarget] = useState<number | null>(null);
    const [editTrialTarget, setEditTrialTarget] = useState<TrialOffer | null>(
        null,
    );
    const [removeTrialTarget, setRemoveTrialTarget] =
        useState<TrialOffer | null>(null);

    const activePrices = prices.filter((p) => p.status === 'active');
    const priceRefs = prices.map((p) => ({ id: p.id, label: priceLabel(p) }));
    const metadataEntries = Object.entries(product.customData ?? {});

    const copyId = async () => {
        await navigator.clipboard.writeText(product.publicId);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 2000);
    };

    return (
        <div className="flex max-w-4xl flex-col gap-8 p-4 pb-24">
            <Head title={product.name} />

            <div>
                <Link
                    href={productsIndex()}
                    className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ChevronLeft className="size-4" /> Products
                </Link>
            </div>

            {/* Header */}
            <div className="flex items-start justify-between gap-4">
                <div className="flex items-start gap-3">
                    <ProductMonogram id={product.id} name={product.name} />
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <h1 className="text-2xl font-semibold">
                                {product.name}
                            </h1>
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
                        </div>
                        <button
                            type="button"
                            onClick={copyId}
                            className="flex items-center gap-1.5 font-mono text-sm text-muted-foreground hover:text-foreground"
                            data-test="copy-product-id"
                        >
                            {product.publicId}
                            {copied ? (
                                <Check className="size-3.5" />
                            ) : (
                                <Copy className="size-3.5" />
                            )}
                        </button>
                        {product.description && (
                            <p className="max-w-md text-sm text-muted-foreground">
                                {product.description}
                            </p>
                        )}
                    </div>
                </div>

                {permissions.canManageProducts && (
                    <div className="flex items-center gap-2">
                        <EditProductDrawer
                            product={product}
                            open={editProductOpen}
                            onOpenChange={setEditProductOpen}
                        >
                            <Button
                                variant="outline"
                                size="sm"
                                data-test="edit-product-trigger"
                            >
                                Edit
                            </Button>
                        </EditProductDrawer>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="icon">
                                    <MoreHorizontal className="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {product.status === 'active' ? (
                                    <DropdownMenuItem
                                        variant="destructive"
                                        onSelect={() =>
                                            setArchiveProductOpen(true)
                                        }
                                        data-test="archive-product-trigger"
                                    >
                                        Archive product
                                    </DropdownMenuItem>
                                ) : (
                                    <DropdownMenuItem
                                        onSelect={() =>
                                            setArchiveProductOpen(true)
                                        }
                                    >
                                        Reactivate product
                                    </DropdownMenuItem>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                )}
            </div>

            <Separator />

            {/* Product information */}
            <section className="space-y-3">
                <h2 className="text-lg font-semibold">Product information</h2>
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Category
                        </p>
                        <p className="text-sm font-medium">
                            {product.category ?? '—'}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Created
                        </p>
                        <p className="text-sm font-medium">
                            {product.createdAt
                                ? new Date(
                                      product.createdAt,
                                  ).toLocaleDateString(undefined, {
                                      year: 'numeric',
                                      month: 'short',
                                      day: 'numeric',
                                  })
                                : '—'}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Active prices
                        </p>
                        <p className="text-sm font-medium">
                            {activePrices.length}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Active subscribers
                        </p>
                        <p className="text-sm font-medium">
                            0{' '}
                            <span className="text-xs font-normal text-muted-foreground">
                                (coming soon)
                            </span>
                        </p>
                    </div>
                </div>
            </section>

            <Separator />

            {/* Pricing — the primary section */}
            <section className="space-y-3">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h2 className="text-lg font-semibold">Pricing</h2>
                        <p className="text-sm text-muted-foreground">
                            Products are containers — prices define how
                            customers are actually billed.
                        </p>
                    </div>
                    {permissions.canManagePrices && (
                        <CreatePriceDrawer
                            productId={product.id}
                            productName={product.name}
                            defaultCurrency={
                                activePrices[0]?.currency ?? 'NGN'
                            }
                            open={createPriceOpen}
                            onOpenChange={setCreatePriceOpen}
                        >
                            <Button data-test="add-price-trigger">
                                <Plus /> Add price
                            </Button>
                        </CreatePriceDrawer>
                    )}
                </div>

                {prices.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        <p className="mb-1 font-medium text-foreground">
                            This product doesn't have a price yet
                        </p>
                        <p>
                            {product.name} can't be subscribed to until it
                            has at least one price — the amount and interval
                            a customer is billed.
                        </p>
                    </div>
                ) : (
                    <div className="divide-y rounded-lg border">
                        {prices.map((price) => (
                            <PriceRow
                                key={price.id}
                                price={price}
                                trials={trials}
                                productId={product.id}
                                productName={product.name}
                                priceRefs={priceRefs}
                                otherProducts={otherProducts}
                                canManagePrices={permissions.canManagePrices}
                                canManageTrials={
                                    permissions.canManageTrialOffers
                                }
                                onEdit={() => setEditPriceTarget(price)}
                                onArchive={() => setArchivePriceTarget(price)}
                                onOpenDetail={() =>
                                    setDetailPriceTarget(price)
                                }
                                trialOpen={trialRowTarget === price.id}
                                onTrialOpenChange={(open) =>
                                    setTrialRowTarget(open ? price.id : null)
                                }
                            />
                        ))}
                    </div>
                )}
            </section>

            <Separator />

            {/* Trials */}
            <section className="space-y-3">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h2 className="text-lg font-semibold">Trials</h2>
                        <p className="text-sm text-muted-foreground">
                            Let new customers try {product.name} before
                            transitioning to a regular price.
                        </p>
                    </div>
                    {permissions.canManageTrialOffers && (
                        <TrialDrawer
                            productId={product.id}
                            productName={product.name}
                            prices={priceRefs}
                            otherProducts={otherProducts}
                            open={createTrialOpen}
                            onOpenChange={setCreateTrialOpen}
                        >
                            <Button
                                data-test="create-trial-trigger"
                                disabled={prices.length === 0}
                            >
                                <Plus /> Create trial
                            </Button>
                        </TrialDrawer>
                    )}
                </div>

                {prices.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Add a price first — a trial needs at least one price
                        to reference.
                    </div>
                ) : trials.length === 0 ? (
                    <div className="space-y-1 rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        <p className="font-medium text-foreground">
                            No trials on this product yet
                        </p>
                        <p>
                            Trials are entirely optional — create one
                            whenever you're ready.
                        </p>
                    </div>
                ) : (
                    <div className="divide-y rounded-lg border">
                        {trials.map((trial) => (
                            <div
                                key={trial.id}
                                className="flex items-center justify-between gap-4 p-4"
                            >
                                <div className="min-w-0 space-y-1">
                                    <div className="flex items-center gap-2">
                                        <Gift className="size-4 shrink-0 text-muted-foreground" />
                                        <span className="font-medium">
                                            {trial.name}
                                        </span>
                                        <Badge
                                            variant={
                                                trial.active
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {trial.active
                                                ? 'Active'
                                                : 'Inactive'}
                                        </Badge>
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {trial.trialPrice.label}
                                        {trial.durationIterations > 1 &&
                                            ` × ${trial.durationIterations}`}
                                        {' → '}
                                        {trial.transitionToDifferentProduct &&
                                        trial.transitionProduct
                                            ? `${trial.transitionProduct.name}: `
                                            : ''}
                                        {trial.transitionPrice.label}
                                    </p>
                                </div>
                                <div className="flex shrink-0 items-center gap-2">
                                    {permissions.canManageTrialOffers && (
                                        <>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setEditTrialTarget(trial)
                                                }
                                            >
                                                Edit
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="text-destructive hover:text-destructive"
                                                onClick={() =>
                                                    setRemoveTrialTarget(trial)
                                                }
                                            >
                                                Remove
                                            </Button>
                                        </>
                                    )}
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                data-test="trial-row-actions-trigger"
                                            >
                                                <MoreHorizontal className="size-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    copyToClipboard(
                                                        trial.publicId,
                                                        'Trial ID',
                                                    )
                                                }
                                                data-test="copy-trial-id-action"
                                            >
                                                Copy trial ID
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </section>

            <Separator />

            {/* Metadata */}
            <section className="space-y-3">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h2 className="text-lg font-semibold">Metadata</h2>
                        <p className="text-sm text-muted-foreground">
                            Your own key/value data, visible via the API.
                        </p>
                    </div>
                    {permissions.canManageProducts && (
                        <EditMetadataDrawer
                            productId={product.id}
                            customData={product.customData}
                            open={editMetadataOpen}
                            onOpenChange={setEditMetadataOpen}
                        >
                            <Button
                                variant="outline"
                                size="sm"
                                data-test="edit-metadata-trigger"
                            >
                                <Pencil /> Edit
                            </Button>
                        </EditMetadataDrawer>
                    )}
                </div>

                {metadataEntries.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        No metadata yet. Metadata lets you attach your own
                        key/value data to this product for use via the API.
                    </div>
                ) : (
                    <div className="divide-y rounded-lg border text-sm">
                        {metadataEntries.map(([key, value]) => (
                            <div
                                key={key}
                                className="flex items-center justify-between gap-4 p-3"
                            >
                                <span className="font-mono text-muted-foreground">
                                    {key}
                                </span>
                                <span className="truncate font-medium">
                                    {value}
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </section>

            <ArchiveProductModal
                productId={product.id}
                productName={product.name}
                open={archiveProductOpen}
                onOpenChange={setArchiveProductOpen}
            />
            {detailPriceTarget && (
                <PriceDetailDrawer
                    price={detailPriceTarget}
                    productName={product.name}
                    trials={trials}
                    canManagePrices={permissions.canManagePrices}
                    open={detailPriceTarget !== null}
                    onOpenChange={(open) =>
                        !open && setDetailPriceTarget(null)
                    }
                    onEdit={() => {
                        setEditPriceTarget(detailPriceTarget);
                        setDetailPriceTarget(null);
                    }}
                    onArchive={() => {
                        setArchivePriceTarget(detailPriceTarget);
                        setDetailPriceTarget(null);
                    }}
                />
            )}
            {archivePriceTarget && (
                <ArchivePriceModal
                    productId={product.id}
                    priceId={archivePriceTarget.id}
                    priceLabel={priceLabel(archivePriceTarget)}
                    isLastActivePrice={
                        activePrices.length === 1 &&
                        archivePriceTarget.status === 'active'
                    }
                    open={archivePriceTarget !== null}
                    onOpenChange={(open) =>
                        !open && setArchivePriceTarget(null)
                    }
                />
            )}
            {editPriceTarget && (
                <EditPriceDrawer
                    productId={product.id}
                    price={editPriceTarget}
                    open={editPriceTarget !== null}
                    onOpenChange={(open) => !open && setEditPriceTarget(null)}
                />
            )}
            {editTrialTarget && (
                <TrialDrawer
                    productId={product.id}
                    productName={product.name}
                    prices={priceRefs}
                    otherProducts={otherProducts}
                    trial={editTrialTarget}
                    open={editTrialTarget !== null}
                    onOpenChange={(open) => !open && setEditTrialTarget(null)}
                />
            )}
            {removeTrialTarget && (
                <RemoveTrialModal
                    productId={product.id}
                    productName={product.name}
                    trialId={removeTrialTarget.id}
                    open={removeTrialTarget !== null}
                    onOpenChange={(open) =>
                        !open && setRemoveTrialTarget(null)
                    }
                />
            )}
        </div>
    );
}

function PriceRow({
    price,
    trials,
    productId,
    productName,
    priceRefs,
    otherProducts,
    canManagePrices,
    canManageTrials,
    onEdit,
    onArchive,
    onOpenDetail,
    trialOpen,
    onTrialOpenChange,
}: {
    price: Price;
    trials: TrialOffer[];
    productId: number;
    productName: string;
    priceRefs: { id: number; label: string }[];
    otherProducts: OtherProduct[];
    canManagePrices: boolean;
    canManageTrials: boolean;
    onEdit: () => void;
    onArchive: () => void;
    onOpenDetail: () => void;
    trialOpen: boolean;
    onTrialOpenChange: (open: boolean) => void;
}) {
    const monthlyEquivalent =
        price.unitAmount !== null &&
        price.billingInterval &&
        price.billingInterval !== 'month'
            ? toMonthlyEquivalent(
                  price.unitAmount,
                  price.billingInterval,
                  price.billingFrequency,
              )
            : null;

    const leadsToThisPrice = trials.find(
        (t) => t.transitionPrice.id === price.id,
    );
    const isTrialPriceFor = trials.find((t) => t.trialPrice.id === price.id);

    return (
        <div className="flex items-center justify-between gap-4 p-4">
            <div className="min-w-0 space-y-1">
                <button
                    type="button"
                    onClick={onOpenDetail}
                    className="flex items-center gap-2 text-left"
                    data-test="price-row-detail-trigger"
                >
                    <span className="font-medium underline-offset-4 hover:underline">
                        {price.name ?? 'Price'}
                    </span>
                    <Badge
                        variant={
                            price.status === 'active' ? 'secondary' : 'outline'
                        }
                        className="capitalize"
                    >
                        {price.status}
                    </Badge>
                    {isTrialPriceFor && (
                        <Badge variant="outline">
                            Trial price for {isTrialPriceFor.name}
                        </Badge>
                    )}
                </button>
                <p className="text-sm text-muted-foreground">
                    {price.pricingModel === 'graduated' ? (
                        'Graduated pricing'
                    ) : price.unitAmount !== null &&
                      price.billingInterval ? (
                        <>
                            {formatPriceInterval(
                                price.unitAmount,
                                price.currency,
                                price.billingInterval,
                                price.billingFrequency,
                            )}
                            {monthlyEquivalent !== null && (
                                <span>
                                    {' '}
                                    (≈{' '}
                                    {formatMoney(
                                        monthlyEquivalent,
                                        price.currency,
                                    )}
                                    /mo)
                                </span>
                            )}
                        </>
                    ) : (
                        'One-time'
                    )}
                </p>
                {leadsToThisPrice ? (
                    <p className="flex items-center gap-1 text-sm text-muted-foreground">
                        <Gift className="size-3.5" /> Trial "
                        {leadsToThisPrice.name}" leads here
                    </p>
                ) : (
                    canManageTrials &&
                    price.status === 'active' && (
                        <TrialDrawer
                            productId={productId}
                            productName={productName}
                            prices={priceRefs}
                            otherProducts={otherProducts}
                            defaultTransitionPriceId={price.id}
                            open={trialOpen}
                            onOpenChange={onTrialOpenChange}
                        >
                            <button
                                type="button"
                                className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                            >
                                + Add trial
                            </button>
                        </TrialDrawer>
                    )
                )}
            </div>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="shrink-0"
                        data-test="price-row-actions-trigger"
                    >
                        <MoreHorizontal className="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        onSelect={() =>
                            copyToClipboard(price.publicId, 'Price ID')
                        }
                        data-test="copy-price-id-action"
                    >
                        Copy price ID
                    </DropdownMenuItem>
                    {canManagePrices && price.status === 'active' && (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                onSelect={onEdit}
                                data-test="edit-price-trigger"
                            >
                                Edit price
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={onArchive}
                                data-test="archive-price-trigger"
                            >
                                Archive price
                            </DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

ProductShow.layout = () => ({
    breadcrumbs: [{ title: 'Products', href: productsIndex() }],
});
