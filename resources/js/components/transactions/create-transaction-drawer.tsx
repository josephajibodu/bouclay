import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
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
import { store } from '@/routes/transactions';
import type {
    CollectionMode,
    CreateCustomerOption,
    CreateProductOption,
} from '@/types';

type Props = {
    customers: CreateCustomerOption[];
    products: CreateProductOption[];
    teamCurrency: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Launched from a customer's own page — pre-fill and lock it. */
    fixedCustomerId?: number | null;
};

type PriceLine = {
    key: string;
    kind: 'price';
    priceId: number;
    productName: string;
    priceLabel: string;
    unitAmount: number | null;
    quantity: number;
};

type CustomLine = {
    key: string;
    kind: 'custom';
    description: string;
    unitAmount: number | null;
    quantity: number;
};

type Line = PriceLine | CustomLine;

function money(amount: number | null, currency: string): string {
    if (amount === null) {
        return 'Custom';
    }

    return `${currency} ${amount.toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
}

/**
 * "New transaction" — a one-off invoice: a customer, one or more line items
 * (a catalog price, or a custom amount), and how to collect. Mirrors
 * CreateSubscriptionDrawer's shape minus trials (IMPLEMENTATION.md Phase 6).
 */
export default function CreateTransactionDrawer({
    customers,
    products,
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
            price_id?: number;
            description?: string;
            unit_amount?: number;
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

    const addPrice = (value: string) => {
        const [productId, priceId] = value.split(':').map(Number);
        const product = products.find((p) => p.id === productId);
        const price = product?.prices.find((p) => p.id === priceId);

        if (!product || !price) {
            return;
        }

        setLines((current) => [
            ...current,
            {
                key: `price-${priceId}-${Date.now()}`,
                kind: 'price',
                priceId,
                productName: product.name,
                priceLabel: price.label,
                unitAmount: price.unitAmount,
                quantity: 1,
            },
        ]);
    };

    const addCustomLine = () => {
        setLines((current) => [
            ...current,
            {
                key: `custom-${Date.now()}`,
                kind: 'custom',
                description: '',
                unitAmount: null,
                quantity: 1,
            },
        ]);
    };

    const updateCustomLine = (
        key: string,
        patch: Partial<Pick<CustomLine, 'description' | 'unitAmount'>>,
    ) =>
        setLines((current) =>
            current.map((line) =>
                line.key === key && line.kind === 'custom'
                    ? { ...line, ...patch }
                    : line,
            ),
        );

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

    const total = lines.reduce(
        (sum, line) =>
            line.unitAmount !== null
                ? sum + line.unitAmount * line.quantity
                : sum,
        0,
    );

    const dueToday = collectionMode === 'manual' ? 0 : total;

    const canSubmit =
        customerId !== null &&
        lines.length > 0 &&
        lines.every((line) => line.unitAmount !== null && line.unitAmount > 0) &&
        !processing;

    const noCardAutomatic =
        collectionMode === 'automatic' && customer?.paymentMethods.length === 0;

    const ctaLabel = noCardAutomatic
        ? 'Create & send payment link'
        : collectionMode === 'automatic'
          ? 'Create & charge now'
          : 'Create transaction';

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
                    ? { price_id: line.priceId, quantity: line.quantity }
                    : {
                          description: line.description,
                          unit_amount: line.unitAmount ?? 0,
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
                    <SheetTitle>New transaction</SheetTitle>
                    <SheetDescription>
                        Bill a customer once — a catalog price, a custom
                        amount, or both.
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
                                        setPaymentMethodId(null);
                                    }}
                                >
                                    <SelectTrigger data-test="transaction-customer-select">
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
                                    Add a catalog price or a custom amount to
                                    bill.
                                </p>
                            ) : (
                                <div className="divide-y rounded-md border">
                                    {lines.map((line) => (
                                        <div
                                            key={line.key}
                                            className="flex items-center gap-3 p-3"
                                            data-test="transaction-line-item"
                                        >
                                            {line.kind === 'price' ? (
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm font-medium">
                                                        {line.productName}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {line.priceLabel}
                                                    </p>
                                                </div>
                                            ) : (
                                                <div className="flex min-w-0 flex-1 items-center gap-2">
                                                    <Input
                                                        value={
                                                            line.description
                                                        }
                                                        onChange={(e) =>
                                                            updateCustomLine(
                                                                line.key,
                                                                {
                                                                    description:
                                                                        e.target
                                                                            .value,
                                                                },
                                                            )
                                                        }
                                                        placeholder="Description"
                                                        className="flex-1"
                                                    />
                                                    <Input
                                                        type="number"
                                                        min={0.01}
                                                        step="0.01"
                                                        value={
                                                            line.unitAmount ??
                                                            ''
                                                        }
                                                        onChange={(e) =>
                                                            updateCustomLine(
                                                                line.key,
                                                                {
                                                                    unitAmount:
                                                                        e.target
                                                                            .value ===
                                                                        ''
                                                                            ? null
                                                                            : Number(
                                                                                  e
                                                                                      .target
                                                                                      .value,
                                                                              ),
                                                                },
                                                            )
                                                        }
                                                        placeholder={`0.00 ${currency}`}
                                                        className="w-32"
                                                    />
                                                </div>
                                            )}
                                            <Input
                                                type="number"
                                                min={1}
                                                value={line.quantity}
                                                onChange={(e) =>
                                                    setQuantity(
                                                        line.key,
                                                        Number(e.target.value),
                                                    )
                                                }
                                                className="w-16"
                                                aria-label="Quantity"
                                            />
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
                                            <SelectTrigger data-test="transaction-add-product">
                                                <span className="flex items-center gap-1.5 text-sm">
                                                    <Plus className="size-4" />{' '}
                                                    Add product
                                                </span>
                                            </SelectTrigger>
                                            <SelectContent>
                                                {products
                                                    .filter((product) =>
                                                        product.prices.some(
                                                            (price) =>
                                                                price.currency ===
                                                                currency,
                                                        ),
                                                    )
                                                    .map((product) =>
                                                        product.prices
                                                            .filter(
                                                                (price) =>
                                                                    price.currency ===
                                                                    currency,
                                                            )
                                                            .map((price) => (
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
                                                            )),
                                                    )}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={addCustomLine}
                                        data-test="transaction-add-custom"
                                    >
                                        <Plus /> Add custom line
                                    </Button>
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
                                    description="Bouclay charges the customer's saved card right away."
                                />
                                <CollectionOption
                                    active={collectionMode === 'manual'}
                                    onClick={() => setCollectionMode('manual')}
                                    title="Send an invoice to pay manually"
                                    description="Bouclay sends an invoice the customer pays a link for. No saved card needed."
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
                                            doesn't have a card on file — this
                                            transaction will be created open,
                                            awaiting payment.
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
                                data-test="create-transaction-submit"
                            >
                                {processing && <Spinner />}
                                {ctaLabel}
                            </Button>
                            <p className="text-center text-xs text-muted-foreground">
                                {collectionMode === 'manual'
                                    ? "We'll invoice the customer; no card is charged today."
                                    : noCardAutomatic
                                      ? 'Created open — no card on file yet to charge.'
                                      : `Charges ${money(dueToday, currency)} now.`}
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
