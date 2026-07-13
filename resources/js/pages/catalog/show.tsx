import { Head, Link } from '@inertiajs/react';
import {
    Check,
    ChevronLeft,
    Copy,
    ExternalLink,
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
import PaymentLinkModal from '@/components/catalog/payment-link-modal';
import PlanDrawer from '@/components/catalog/plan-drawer';
import PriceDetailDrawer from '@/components/catalog/price-detail-drawer';
import PricePhasesDrawer from '@/components/catalog/price-phases-drawer';
import { ProductMonogram } from '@/components/catalog/product-monogram';
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
import type { Plan, Price, ProductDetail } from '@/types';

async function copyToClipboard(value: string, label: string) {
    await navigator.clipboard.writeText(value);
    toast.success(`${label} copied`);
}

type Props = {
    product: ProductDetail;
    plans: Plan[];
    prices: Price[];
    permissions: {
        canManageProducts: boolean;
        canManagePlans: boolean;
        canManagePrices: boolean;
    };
};

const PLAN_STATUS_VARIANT: Record<
    Plan['status'],
    'secondary' | 'outline' | 'destructive'
> = {
    active: 'secondary',
    draft: 'outline',
    archived: 'destructive',
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
    plans,
    prices,
    permissions,
}: Props) {
    const [copied, setCopied] = useState(false);
    const [editProductOpen, setEditProductOpen] = useState(false);
    const [archiveProductOpen, setArchiveProductOpen] = useState(false);
    const [editMetadataOpen, setEditMetadataOpen] = useState(false);
    const [createPlanOpen, setCreatePlanOpen] = useState(false);
    const [editPlanTarget, setEditPlanTarget] = useState<Plan | null>(null);
    const [createPriceOpen, setCreatePriceOpen] = useState(false);
    const [createPricePlanId, setCreatePricePlanId] = useState<
        number | undefined
    >(undefined);
    const [editPriceTarget, setEditPriceTarget] = useState<Price | null>(null);
    const [archivePriceTarget, setArchivePriceTarget] = useState<Price | null>(
        null,
    );
    const [paymentLinkTarget, setPaymentLinkTarget] = useState<Price | null>(
        null,
    );
    const [detailPriceTarget, setDetailPriceTarget] = useState<Price | null>(
        null,
    );
    const [phasesPriceTarget, setPhasesPriceTarget] = useState<Price | null>(
        null,
    );

    const activePrices = prices.filter((p) => p.status === 'active');
    const planNameById = new Map(plans.map((p) => [p.id, p.name]));
    const metadataEntries = Object.entries(product.customData ?? {});

    const openCreatePrice = (planId?: number) => {
        setCreatePricePlanId(planId);
        setCreatePriceOpen(true);
    };

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
                        <p className="text-xs text-muted-foreground">Created</p>
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
                <div>
                    <p className="text-xs text-muted-foreground">Return link</p>
                    {product.websiteUrl ? (
                        <a
                            href={product.websiteUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                        >
                            {product.websiteUrl}
                            <ExternalLink className="size-3.5" />
                        </a>
                    ) : (
                        <p className="text-sm font-medium text-muted-foreground">
                            Not set — customers won't see a return link after
                            paying for this product.
                        </p>
                    )}
                </div>
            </section>

            <Separator />

            {/* Plans — the tiers a customer picks */}
            <section className="space-y-3">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h2 className="text-lg font-semibold">Plans</h2>
                        <p className="text-sm text-muted-foreground">
                            The named tiers customers pick — "Premium", "Team".
                            A draft or archived plan's prices can't be
                            subscribed to.
                        </p>
                    </div>
                    {permissions.canManagePlans && (
                        <Button
                            data-test="add-plan-trigger"
                            onClick={() => setCreatePlanOpen(true)}
                        >
                            <Plus /> Add plan
                        </Button>
                    )}
                </div>

                {plans.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        <p className="mb-1 font-medium text-foreground">
                            No plans yet
                        </p>
                        <p>
                            A recurring price is always a variant of a plan —
                            add a plan before pricing {product.name}.
                        </p>
                    </div>
                ) : (
                    <div className="divide-y rounded-lg border">
                        {plans.map((plan) => (
                            <div
                                key={plan.id}
                                className="flex items-center justify-between gap-4 p-4"
                                data-test="plan-row"
                            >
                                <div className="flex items-center gap-2">
                                    <span className="font-medium">
                                        {plan.name}
                                    </span>
                                    <Badge
                                        variant={
                                            PLAN_STATUS_VARIANT[plan.status]
                                        }
                                        className="capitalize"
                                    >
                                        {plan.status}
                                    </Badge>
                                    {plan.code && (
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {plan.code}
                                        </span>
                                    )}
                                </div>
                                {permissions.canManagePlans && (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="shrink-0"
                                                data-test="plan-row-actions-trigger"
                                            >
                                                <MoreHorizontal className="size-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    setEditPlanTarget(plan)
                                                }
                                                data-test="edit-plan-trigger"
                                            >
                                                Edit plan
                                            </DropdownMenuItem>
                                            {permissions.canManagePrices &&
                                                plan.status !== 'archived' && (
                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            openCreatePrice(
                                                                plan.id,
                                                            )
                                                        }
                                                        data-test="add-price-to-plan-trigger"
                                                    >
                                                        Add price to plan
                                                    </DropdownMenuItem>
                                                )}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </section>

            <Separator />

            {/* Pricing — the billable variants */}
            <section className="space-y-3">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h2 className="text-lg font-semibold">Pricing</h2>
                        <p className="text-sm text-muted-foreground">
                            Prices define how customers are billed. Recurring
                            prices belong to a plan; trials and phase schedules
                            live on the price itself.
                        </p>
                    </div>
                    {permissions.canManagePrices && (
                        <Button
                            data-test="add-price-trigger"
                            onClick={() => openCreatePrice()}
                        >
                            <Plus /> Add price
                        </Button>
                    )}
                </div>

                {prices.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        <p className="mb-1 font-medium text-foreground">
                            This product doesn't have a price yet
                        </p>
                        <p>
                            {product.name} can't be subscribed to until it has
                            at least one price — the amount and interval a
                            customer is billed.
                        </p>
                    </div>
                ) : (
                    <div className="divide-y rounded-lg border">
                        {prices.map((price) => (
                            <PriceRow
                                key={price.id}
                                price={price}
                                planName={
                                    price.planId !== null
                                        ? (planNameById.get(price.planId) ??
                                          null)
                                        : null
                                }
                                canManagePrices={permissions.canManagePrices}
                                onEdit={() => setEditPriceTarget(price)}
                                onArchive={() => setArchivePriceTarget(price)}
                                onConfigurePhases={() =>
                                    setPhasesPriceTarget(price)
                                }
                                onCreatePaymentLink={() =>
                                    setPaymentLinkTarget(price)
                                }
                                onOpenDetail={() => setDetailPriceTarget(price)}
                            />
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
                    canManagePrices={permissions.canManagePrices}
                    open={detailPriceTarget !== null}
                    onOpenChange={(open) => !open && setDetailPriceTarget(null)}
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
            <PaymentLinkModal
                productId={product.id}
                productName={product.name}
                price={paymentLinkTarget}
                open={paymentLinkTarget !== null}
                onOpenChange={(open) => !open && setPaymentLinkTarget(null)}
            />
            {editPriceTarget && (
                <EditPriceDrawer
                    productId={product.id}
                    price={editPriceTarget}
                    plans={plans}
                    open={editPriceTarget !== null}
                    onOpenChange={(open) => !open && setEditPriceTarget(null)}
                />
            )}
            {phasesPriceTarget && (
                <PricePhasesDrawer
                    productId={product.id}
                    price={phasesPriceTarget}
                    candidatePrices={prices}
                    open={phasesPriceTarget !== null}
                    onOpenChange={(open) => !open && setPhasesPriceTarget(null)}
                />
            )}

            {/* Plans: one create drawer + an edit drawer for the chosen row */}
            <PlanDrawer
                productId={product.id}
                open={createPlanOpen}
                onOpenChange={setCreatePlanOpen}
            />
            {editPlanTarget && (
                <PlanDrawer
                    productId={product.id}
                    plan={editPlanTarget}
                    open={editPlanTarget !== null}
                    onOpenChange={(open) => !open && setEditPlanTarget(null)}
                />
            )}

            {/* Price create drawer, opened from the header or a plan row */}
            <CreatePriceDrawer
                productId={product.id}
                productName={product.name}
                plans={plans}
                defaultPlanId={createPricePlanId}
                defaultCurrency={activePrices[0]?.currency ?? 'NGN'}
                open={createPriceOpen}
                onOpenChange={setCreatePriceOpen}
            />
        </div>
    );
}

function PriceRow({
    price,
    planName,
    canManagePrices,
    onEdit,
    onArchive,
    onConfigurePhases,
    onCreatePaymentLink,
    onOpenDetail,
}: {
    price: Price;
    planName: string | null;
    canManagePrices: boolean;
    onEdit: () => void;
    onArchive: () => void;
    onConfigurePhases: () => void;
    onCreatePaymentLink: () => void;
    onOpenDetail: () => void;
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
                    {planName && <Badge variant="outline">{planName}</Badge>}
                    {price.trialLength !== null && (
                        <Badge variant="outline">
                            <Gift className="size-3" /> {price.trialLength}-
                            {price.trialUnit} trial
                        </Badge>
                    )}
                    {price.phases.length > 0 && (
                        <Badge variant="outline">
                            {price.phases.length}-phase schedule
                        </Badge>
                    )}
                    {!price.purchasable && (
                        <Badge variant="outline">Phase-only</Badge>
                    )}
                </button>
                <p className="text-sm text-muted-foreground">
                    {price.pricingModel === 'graduated' ? (
                        'Graduated pricing'
                    ) : price.unitAmount !== null && price.billingInterval ? (
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
                                onSelect={onCreatePaymentLink}
                                data-test="create-payment-link-trigger"
                            >
                                {price.paymentLink
                                    ? 'View payment link'
                                    : 'Create payment link'}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                onSelect={onEdit}
                                data-test="edit-price-trigger"
                            >
                                Edit price
                            </DropdownMenuItem>
                            {price.type === 'recurring' &&
                                !price.hasBeenUsed && (
                                    <DropdownMenuItem
                                        onSelect={onConfigurePhases}
                                        data-test="configure-phases-trigger"
                                    >
                                        Configure phases
                                    </DropdownMenuItem>
                                )}
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
