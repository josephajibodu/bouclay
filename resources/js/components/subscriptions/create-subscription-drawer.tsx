import { useForm } from '@inertiajs/react';
import { Gift, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/subscriptions';
import type {
    CollectionMode,
    CreateCustomerOption,
    CreateProductOption,
    CreateTrialOfferOption,
} from '@/types';

type Props = {
    customers: CreateCustomerOption[];
    products: CreateProductOption[];
    trialOffers: CreateTrialOfferOption[];
    teamCurrency: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Launched from a customer's own page — pre-fill and lock it (§7.2). */
    fixedCustomerId?: number | null;
};

type PriceLine = {
    key: string;
    kind: 'price';
    productId: number;
    priceId: number;
    productName: string;
    priceLabel: string;
    unitAmount: number | null;
    currency: string;
    quantity: number;
};

type TrialLine = {
    key: string;
    kind: 'trial';
    productId: number;
    trialOfferId: number;
    productName: string;
    trialLabel: string;
    isFree: boolean;
    unitAmount: number | null;
    transitionLabel: string;
    quantity: number;
};

type Line = PriceLine | TrialLine;

function money(amount: number | null, currency: string): string {
    if (amount === null) {
        return 'Custom';
    }

    return `${currency} ${amount.toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
}

/**
 * The two-pane "New subscription" create surface (SUBSCRIPTIONS_DESIGN §7),
 * as a drawer rather than a dedicated page — opened from the Subscriptions
 * list or from a customer's own page (which pre-fills and locks the
 * customer section).
 */
export default function CreateSubscriptionDrawer({
    customers,
    products,
    trialOffers,
    teamCurrency,
    open,
    onOpenChange,
    fixedCustomerId = null,
}: Props) {
    const [customerId, setCustomerId] = useState<number | null>(
        fixedCustomerId,
    );
    const [lines, setLines] = useState<Line[]>([]);
    const [collectionMode, setCollectionMode] =
        useState<CollectionMode>('automatic');
    const [paymentMethodId, setPaymentMethodId] = useState<number | null>(
        null,
    );

    type FormShape = {
        customer_id: number | null;
        collection_mode: CollectionMode;
        payment_method_id: number | null;
        items: Array<{
            kind: 'price' | 'trial';
            price_id?: number;
            trial_offer_id?: number;
            quantity: number;
        }>;
    };

    const { transform, post, processing, errors, reset, clearErrors } =
        useForm<FormShape>({
            customer_id: fixedCustomerId,
            collection_mode: 'automatic',
            payment_method_id: null,
            items: [],
        });

    // Reset the builder to a blank slate (re-seeding the fixed customer, if
    // any) every time the drawer opens, so a prior attempt never lingers.
    const handleOpenChange = (next: boolean) => {
        if (next) {
            setCustomerId(fixedCustomerId);
            setLines([]);
            setCollectionMode('automatic');
            setPaymentMethodId(null);
            reset();
            clearErrors();
        }

        onOpenChange(next);
    };

    const customer = customers.find((c) => c.id === customerId) ?? null;
    const currency = customer?.currency ?? teamCurrency;
    // A paid trial (intro price > 0) is charged now and each cycle; only a
    // wholly-free trial takes nothing today (schema.md §5).
    const hasPaidLine = lines.some(
        (line) =>
            (line.kind === 'price' || !line.isFree) && line.unitAmount !== null,
    );
    const freeTrialOnly =
        lines.length > 0 &&
        lines.every((line) => line.kind === 'trial' && line.isFree);

    // Only prices/offers matching the subscription currency are offered —
    // a subscription is single-currency for life (§7.2). The React Compiler
    // memoizes these derived values; no manual useMemo.
    const productOptions = products
        .map((product) => ({
            ...product,
            prices: product.prices.filter(
                (price) => price.currency === currency,
            ),
        }))
        .filter((product) => product.prices.length > 0);

    const trialOptions = trialOffers.filter(
        (offer) => offer.trialPrice.currency === currency,
    );

    // A product appears at most once — a plain line and a trial for the same
    // product describe the same subscription, so the newest wins (adding a
    // trial for a product replaces its plain line, and vice versa). This is
    // what prevents double-charging the base price alongside the trial.
    const upsertLine = (line: Line) => {
        const replacing = lines.some((l) => l.productId === line.productId);

        setLines((current) => [
            ...current.filter((l) => l.productId !== line.productId),
            line,
        ]);

        if (replacing) {
            toast.info(
                `${line.productName} is already on this subscription — replaced it.`,
            );
        }
    };

    const addPrice = (value: string) => {
        const [productId, priceId] = value.split(':').map(Number);
        const product = products.find((p) => p.id === productId);
        const price = product?.prices.find((p) => p.id === priceId);

        if (!product || !price) {
            return;
        }

        upsertLine({
            key: `price-${priceId}-${Date.now()}`,
            kind: 'price',
            productId: product.id,
            priceId,
            productName: product.name,
            priceLabel: price.label,
            unitAmount: price.unitAmount,
            currency: price.currency,
            quantity: 1,
        });
    };

    const addTrial = (value: string) => {
        const offer = trialOffers.find((o) => o.id === Number(value));

        if (!offer) {
            return;
        }

        upsertLine({
            key: `trial-${offer.id}-${Date.now()}`,
            kind: 'trial',
            productId: offer.product.id,
            trialOfferId: offer.id,
            productName: offer.product.name,
            trialLabel: offer.trialPrice.isFree
                ? 'Free trial'
                : offer.trialPrice.label,
            isFree: offer.trialPrice.isFree,
            unitAmount: offer.trialPrice.unitAmount,
            transitionLabel: offer.transitionPrice.label,
            quantity: 1,
        });
    };

    const removeLine = (key: string) =>
        setLines((current) => current.filter((line) => line.key !== key));

    const setQuantity = (key: string, quantity: number) =>
        setLines((current) =>
            current.map((line) =>
                line.key === key
                    ? { ...line, quantity: Math.max(1, quantity) }
                    : line,
            ),
        );

    // Due today: automatic charges every billing line now — regular prices and
    // paid-trial intro prices alike; free-trial lines add nothing. Manual bills
    // by invoice, so nothing is charged to a card today (§7.2 Preview).
    const dueToday =
        collectionMode === 'manual'
            ? 0
            : lines.reduce((sum, line) => {
                  const billsNow = line.kind === 'price' || !line.isFree;

                  if (billsNow && line.unitAmount !== null) {
                      return sum + line.unitAmount * line.quantity;
                  }

                  return sum;
              }, 0);

    const canSubmit = customerId !== null && lines.length > 0 && !processing;

    const noCardAutomatic =
        collectionMode === 'automatic' &&
        customer?.paymentMethods.length === 0 &&
        hasPaidLine;

    const ctaLabel = freeTrialOnly
        ? 'Start free trial'
        : noCardAutomatic
          ? 'Create & send payment link'
          : 'Start subscription';

    const submit = () => {
        if (!canSubmit) {
            return;
        }

        transform(() => ({
            customer_id: customerId,
            collection_mode: collectionMode,
            payment_method_id:
                collectionMode === 'automatic' ? paymentMethodId : null,
            items: lines.map((line) =>
                line.kind === 'price'
                    ? {
                          kind: 'price' as const,
                          price_id: line.priceId,
                          quantity: line.quantity,
                      }
                    : {
                          kind: 'trial' as const,
                          trial_offer_id: line.trialOfferId,
                          quantity: line.quantity,
                      },
            ),
        }));

        post(store().url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Sheet open={open} onOpenChange={handleOpenChange}>
            <SheetContent className="flex w-full flex-col gap-0 sm:max-w-4xl">
                <SheetHeader>
                    <SheetTitle>New subscription</SheetTitle>
                    <SheetDescription>
                        Attach a customer to one or more prices — add a trial
                        if you offer one.
                    </SheetDescription>
                </SheetHeader>

                <div className="grid flex-1 grid-cols-1 gap-6 overflow-y-auto px-4 pb-6 lg:grid-cols-[1fr_320px]">
                    {/* Builder */}
                    <div className="flex flex-col gap-6">
                        {/* Customer */}
                        <section className="space-y-3 rounded-lg border p-4">
                            <h2 className="font-semibold">Customer</h2>
                            {fixedCustomerId !== null && customer ? (
                                <p className="text-sm">
                                    {customer.name ?? customer.email} ·{' '}
                                    {customer.email}
                                </p>
                            ) : (
                                <Select
                                    value={
                                        customerId ? String(customerId) : undefined
                                    }
                                    onValueChange={(value) => {
                                        setCustomerId(Number(value));
                                        setLines([]);
                                        setPaymentMethodId(null);
                                    }}
                                >
                                    <SelectTrigger data-test="customer-select">
                                        <SelectValue placeholder="Search or select a customer…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {customers.map((c) => (
                                            <SelectItem
                                                key={c.id}
                                                value={String(c.id)}
                                            >
                                                {c.name ?? c.email} ·{' '}
                                                {c.email}
                                                {c.paymentMethods.length === 0
                                                    ? ' · No card'
                                                    : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
                            {errors.customer_id && (
                                <p className="text-sm text-destructive">
                                    {errors.customer_id}
                                </p>
                            )}
                        </section>

                        {/* Line items */}
                        <section className="space-y-3 rounded-lg border p-4">
                            <h2 className="font-semibold">Line items</h2>

                            {lines.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Add the product or plan you're billing
                                    for. Add a free trial if you offer one.
                                </p>
                            ) : (
                                <div className="divide-y rounded-md border">
                                    {lines.map((line) => (
                                        <div
                                            key={line.key}
                                            className="flex items-center gap-3 p-3"
                                            data-test="line-item"
                                        >
                                            {line.kind === 'trial' && (
                                                <Gift className="size-4 shrink-0 text-blue-500" />
                                            )}
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">
                                                    {line.productName}
                                                    {line.kind === 'trial' && (
                                                        <Badge
                                                            variant="secondary"
                                                            className="ml-2"
                                                        >
                                                            {line.trialLabel}
                                                        </Badge>
                                                    )}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {line.kind === 'price'
                                                        ? line.priceLabel
                                                        : `then ${line.transitionLabel}`}
                                                </p>
                                            </div>
                                            {line.kind === 'price' && (
                                                <Input
                                                    type="number"
                                                    min={1}
                                                    value={line.quantity}
                                                    onChange={(e) =>
                                                        setQuantity(
                                                            line.key,
                                                            Number(
                                                                e.target.value,
                                                            ),
                                                        )
                                                    }
                                                    className="w-16"
                                                    aria-label="Quantity"
                                                />
                                            )}
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-8"
                                                onClick={() =>
                                                    removeLine(line.key)
                                                }
                                                aria-label="Remove"
                                            >
                                                <Trash2 />
                                            </Button>
                                        </div>
                                    ))}
                                </div>
                            )}

                            {customerId !== null && (
                                <div className="flex flex-wrap gap-3">
                                    <div className="w-52">
                                        <Select
                                            value=""
                                            onValueChange={addPrice}
                                        >
                                            <SelectTrigger data-test="add-product">
                                                <span className="flex items-center gap-1.5 text-sm">
                                                    <Plus className="size-4" />{' '}
                                                    Add product
                                                </span>
                                            </SelectTrigger>
                                            <SelectContent>
                                                {productOptions.length ===
                                                0 ? (
                                                    <div className="p-2 text-xs text-muted-foreground">
                                                        No recurring prices in{' '}
                                                        {currency}.
                                                    </div>
                                                ) : (
                                                    productOptions.map(
                                                        (product) =>
                                                            product.prices.map(
                                                                (price) => (
                                                                    <SelectItem
                                                                        key={`${product.id}:${price.id}`}
                                                                        value={`${product.id}:${price.id}`}
                                                                    >
                                                                        {
                                                                            product.name
                                                                        }{' '}
                                                                        ·{' '}
                                                                        {
                                                                            price.label
                                                                        }
                                                                    </SelectItem>
                                                                ),
                                                            ),
                                                    )
                                                )}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    {trialOptions.length > 0 && (
                                        <div className="w-52">
                                            <Select
                                                value=""
                                                onValueChange={addTrial}
                                            >
                                                <SelectTrigger data-test="add-trial">
                                                    <span className="flex items-center gap-1.5 text-sm">
                                                        <Gift className="size-4" />{' '}
                                                        Add trial
                                                    </span>
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {trialOptions.map(
                                                        (offer) => (
                                                            <SelectItem
                                                                key={offer.id}
                                                                value={String(
                                                                    offer.id,
                                                                )}
                                                            >
                                                                {
                                                                    offer
                                                                        .product
                                                                        .name
                                                                }{' '}
                                                                · {offer.name}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    )}
                                </div>
                            )}

                            {errors.items && (
                                <p className="text-sm text-destructive">
                                    {errors.items}
                                </p>
                            )}
                        </section>

                        {/* Billing */}
                        <section className="space-y-4 rounded-lg border p-4">
                            <h2 className="font-semibold">Billing</h2>
                            <p className="text-sm text-muted-foreground">
                                How should we collect payment?
                            </p>

                            <div className="grid gap-2">
                                <CollectionOption
                                    active={collectionMode === 'automatic'}
                                    onClick={() =>
                                        setCollectionMode('automatic')
                                    }
                                    title="Automatically charge a saved card"
                                    description="Bouclay charges the customer's saved card each cycle."
                                />
                                <CollectionOption
                                    active={collectionMode === 'manual'}
                                    onClick={() => setCollectionMode('manual')}
                                    title="Send an invoice to pay manually"
                                    description="Bouclay sends an invoice each cycle; the customer pays a link. No saved card needed."
                                />
                            </div>

                            {collectionMode === 'automatic' && customer && (
                                <div className="space-y-2">
                                    {customer.paymentMethods.length > 0 ? (
                                        <>
                                            <Label>Card</Label>
                                            <Select
                                                value={
                                                    paymentMethodId
                                                        ? String(
                                                              paymentMethodId,
                                                          )
                                                        : undefined
                                                }
                                                onValueChange={(value) =>
                                                    setPaymentMethodId(
                                                        Number(value),
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select a card" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {customer.paymentMethods.map(
                                                        (pm) => (
                                                            <SelectItem
                                                                key={pm.id}
                                                                value={String(
                                                                    pm.id,
                                                                )}
                                                            >
                                                                {pm.label}
                                                                {pm.isDefault
                                                                    ? ' (default)'
                                                                    : ''}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </>
                                    ) : (
                                        <p className="rounded-md bg-amber-50 p-3 text-xs text-amber-700 dark:bg-amber-950/40 dark:text-amber-400">
                                            {customer.name ?? 'This customer'}{' '}
                                            doesn't have a card on file. We'll
                                            create the subscription and send a
                                            secure checkout link to collect
                                            one. Access starts once they pay.
                                        </p>
                                    )}
                                </div>
                            )}
                        </section>
                    </div>

                    {/* Preview */}
                    <div className="lg:sticky lg:top-4 lg:h-fit">
                        <div className="space-y-4 rounded-lg border p-4">
                            <h2 className="font-semibold">Preview</h2>

                            <div className="space-y-3 text-sm">
                                <PreviewRow label="Customer">
                                    {customer
                                        ? (customer.name ?? customer.email)
                                        : '—'}
                                </PreviewRow>
                                <PreviewRow label="Items">
                                    {lines.length === 0
                                        ? '—'
                                        : `${lines.length}`}
                                </PreviewRow>
                                <PreviewRow label="Billing">
                                    {collectionMode === 'automatic'
                                        ? 'Automatic'
                                        : 'Manual invoice'}
                                </PreviewRow>
                            </div>

                            <Separator />

                            <div className="flex items-center justify-between">
                                <span className="text-sm text-muted-foreground">
                                    Due today
                                </span>
                                <span className="font-semibold">
                                    {money(dueToday, currency)}
                                </span>
                            </div>

                            <Button
                                className="w-full"
                                disabled={!canSubmit}
                                onClick={submit}
                                data-test="start-subscription"
                            >
                                {processing && <Spinner />}
                                {ctaLabel}
                            </Button>
                            <p className="text-center text-xs text-muted-foreground">
                                {freeTrialOnly
                                    ? 'No payment today — the free trial starts immediately.'
                                    : collectionMode === 'manual'
                                      ? "We'll invoice the customer; no card is charged today."
                                      : noCardAutomatic
                                        ? "No card on file — we'll send a secure link to collect one. Access starts once they pay."
                                        : dueToday > 0
                                          ? `Charges ${money(dueToday, currency)} today, then renews.`
                                          : 'Creates the subscription and its first period.'}
                            </p>
                        </div>
                    </div>
                </div>
            </SheetContent>
        </Sheet>
    );
}

function CollectionOption({
    active,
    onClick,
    title,
    description,
}: {
    active: boolean;
    onClick: () => void;
    title: string;
    description: string;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`rounded-md border p-3 text-left transition-colors ${
                active ? 'border-primary bg-primary/5' : 'hover:bg-muted/50'
            }`}
        >
            <div className="flex items-center gap-2">
                <span
                    className={`flex size-4 items-center justify-center rounded-full border ${
                        active ? 'border-primary' : 'border-muted-foreground/40'
                    }`}
                >
                    {active && (
                        <span className="size-2 rounded-full bg-primary" />
                    )}
                </span>
                <span className="text-sm font-medium">{title}</span>
            </div>
            <p className="mt-1 pl-6 text-xs text-muted-foreground">
                {description}
            </p>
        </button>
    );
}

function PreviewRow({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-center justify-between gap-4">
            <span className="text-muted-foreground">{label}</span>
            <span className="truncate text-right font-medium">
                {children}
            </span>
        </div>
    );
}
