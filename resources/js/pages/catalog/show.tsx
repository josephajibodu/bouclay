import { Head, Link, usePage } from '@inertiajs/react';
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
import ArchivePriceModal from '@/components/catalog/archive-price-modal';
import ArchiveProductModal from '@/components/catalog/archive-product-modal';
import CreatePriceDrawer from '@/components/catalog/create-price-drawer';
import EditMetadataDrawer from '@/components/catalog/edit-metadata-drawer';
import EditPriceDrawer from '@/components/catalog/edit-price-drawer';
import EditProductDrawer from '@/components/catalog/edit-product-drawer';
import { ProductMonogram } from '@/components/catalog/product-monogram';
import RemoveTrialModal from '@/components/catalog/remove-trial-modal';
import TrialDrawer from '@/components/catalog/trial-drawer';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import {
    formatPriceInterval,
    formatMoney,
    toMonthlyEquivalent,
} from '@/lib/utils';
import { index as productsIndex } from '@/routes/catalog/products';
import type { Price, ProductDetail } from '@/types';

type Props = {
    product: ProductDetail;
    prices: Price[];
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

export default function ProductShow({ product, prices, permissions }: Props) {
    const { currentTeam } = usePage().props;

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
    const [trialDrawerPrice, setTrialDrawerPrice] = useState<Price | null>(
        null,
    );
    const [removeTrialTarget, setRemoveTrialTarget] = useState<Price | null>(
        null,
    );

    if (!currentTeam) {
        return null;
    }

    const activePrices = prices.filter((p) => p.status === 'active');
    const pricesWithTrial = activePrices.filter((p) => p.trial !== null);
    const eligibleForTrial = activePrices
        .filter((p) => p.trial === null)
        .map((p) => ({ id: p.id, label: priceLabel(p) }));
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
                    href={productsIndex(currentTeam.slug)}
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
                            currentTeamSlug={currentTeam.slug}
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
                            currentTeamSlug={currentTeam.slug}
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
                                currentTeamSlug={currentTeam.slug}
                                productId={product.id}
                                productName={product.name}
                                canManagePrices={permissions.canManagePrices}
                                canManageTrials={
                                    permissions.canManageTrialOffers
                                }
                                editOpen={editPriceTarget?.id === price.id}
                                onEditOpenChange={(open) =>
                                    setEditPriceTarget(open ? price : null)
                                }
                                onArchive={() => setArchivePriceTarget(price)}
                                trialOpen={trialDrawerPrice?.id === price.id}
                                onTrialOpenChange={(open) =>
                                    setTrialDrawerPrice(open ? price : null)
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
                            they're charged.
                        </p>
                    </div>
                    {permissions.canManageTrialOffers &&
                        activePrices.length > 0 && (
                            <TrialDrawer
                                currentTeamSlug={currentTeam.slug}
                                productId={product.id}
                                productName={product.name}
                                eligiblePrices={eligibleForTrial}
                                open={createTrialOpen}
                                onOpenChange={setCreateTrialOpen}
                            >
                                <Button
                                    data-test="create-trial-trigger"
                                    disabled={eligibleForTrial.length === 0}
                                >
                                    <Plus /> Create trial
                                </Button>
                            </TrialDrawer>
                        )}
                </div>

                {activePrices.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Add a price first — trials attach to a specific
                        price.
                    </div>
                ) : pricesWithTrial.length === 0 ? (
                    <div className="space-y-1 rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        <p className="font-medium text-foreground">
                            No free trial on this product yet
                        </p>
                        <p>
                            Trials are entirely optional — create one
                            whenever you're ready.
                        </p>
                    </div>
                ) : (
                    <div className="divide-y rounded-lg border">
                        {pricesWithTrial.map((price) => (
                            <div
                                key={price.id}
                                className="flex items-center justify-between gap-4 p-4"
                            >
                                <div className="flex items-center gap-2 text-sm">
                                    <Gift className="size-4 text-muted-foreground" />
                                    <span className="font-medium">
                                        {price.trial!.durationAmount}{' '}
                                        {price.trial!.durationUnit}
                                        {price.trial!.durationAmount === 1
                                            ? ''
                                            : 's'}{' '}
                                        free trial
                                    </span>
                                    <span className="text-muted-foreground">
                                        on {priceLabel(price)}
                                    </span>
                                    <Badge variant="secondary">Active</Badge>
                                </div>
                                {permissions.canManageTrialOffers && (
                                    <div className="flex items-center gap-2">
                                        <TrialDrawer
                                            currentTeamSlug={
                                                currentTeam.slug
                                            }
                                            productId={product.id}
                                            productName={product.name}
                                            priceId={price.id}
                                            trial={price.trial}
                                            open={
                                                trialDrawerPrice?.id ===
                                                price.id
                                            }
                                            onOpenChange={(open) =>
                                                setTrialDrawerPrice(
                                                    open ? price : null,
                                                )
                                            }
                                        >
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                            >
                                                Edit
                                            </Button>
                                        </TrialDrawer>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="text-destructive hover:text-destructive"
                                            onClick={() =>
                                                setRemoveTrialTarget(price)
                                            }
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                )}
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
                            currentTeamSlug={currentTeam.slug}
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
                currentTeamSlug={currentTeam.slug}
                productId={product.id}
                productName={product.name}
                open={archiveProductOpen}
                onOpenChange={setArchiveProductOpen}
            />
            {archivePriceTarget && (
                <ArchivePriceModal
                    currentTeamSlug={currentTeam.slug}
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
            {removeTrialTarget?.trial && (
                <RemoveTrialModal
                    currentTeamSlug={currentTeam.slug}
                    productId={product.id}
                    productName={product.name}
                    trialId={removeTrialTarget.trial.id}
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
    currentTeamSlug,
    productId,
    productName,
    canManagePrices,
    canManageTrials,
    editOpen,
    onEditOpenChange,
    onArchive,
    trialOpen,
    onTrialOpenChange,
}: {
    price: Price;
    currentTeamSlug: string;
    productId: number;
    productName: string;
    canManagePrices: boolean;
    canManageTrials: boolean;
    editOpen: boolean;
    onEditOpenChange: (open: boolean) => void;
    onArchive: () => void;
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

    return (
        <div className="flex items-center justify-between gap-4 p-4">
            <div className="min-w-0 space-y-1">
                <div className="flex items-center gap-2">
                    <span className="font-medium">
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
                </div>
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
                {price.trial ? (
                    <p className="flex items-center gap-1 text-sm text-muted-foreground">
                        <Gift className="size-3.5" /> Free trial:{' '}
                        {price.trial.durationAmount} {price.trial.durationUnit}
                        {price.trial.durationAmount === 1 ? '' : 's'}
                    </p>
                ) : (
                    canManageTrials &&
                    price.status === 'active' && (
                        <TrialDrawer
                            currentTeamSlug={currentTeamSlug}
                            productId={productId}
                            productName={productName}
                            priceId={price.id}
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

            {canManagePrices && price.status === 'active' && (
                <div className="flex shrink-0 items-center gap-2">
                    <EditPriceDrawer
                        currentTeamSlug={currentTeamSlug}
                        productId={productId}
                        price={price}
                        open={editOpen}
                        onOpenChange={onEditOpenChange}
                    >
                        <Button variant="ghost" size="sm">
                            Edit
                        </Button>
                    </EditPriceDrawer>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="text-destructive hover:text-destructive"
                        onClick={onArchive}
                        data-test="archive-price-trigger"
                    >
                        Archive
                    </Button>
                </div>
            )}
        </div>
    );
}

ProductShow.layout = (props: {
    currentTeam?: { slug: string } | null;
    product?: ProductDetail;
}) => ({
    breadcrumbs: [
        {
            title: 'Products',
            href: props.currentTeam ? productsIndex(props.currentTeam.slug) : '/',
        },
    ],
});
