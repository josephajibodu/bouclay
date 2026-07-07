import { router } from '@inertiajs/react';
import { Minus, Plus } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { update as updateItem } from '@/routes/subscriptions/items';
import type {
    CreatePriceOption,
    CreateProductOption,
    SubscriptionDetail,
    SubscriptionItem,
} from '@/types';

type Props = {
    subscription: SubscriptionDetail;
    item: SubscriptionItem | null;
    products: CreateProductOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

function money(amount: number | null, currency: string): string {
    if (amount === null) {
        return 'Custom';
    }

    return `${currency} ${amount.toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
}

function prorationFraction(
    periodStart: string | null,
    periodEnd: string | null,
): number {
    if (!periodStart || !periodEnd) {
        return 0;
    }

    const startMs = new Date(periodStart).getTime();
    const endMs = new Date(periodEnd).getTime();
    const now = Date.now();

    if (now >= endMs) {
        return 0;
    }

    const total = Math.max(1, endMs - startMs);
    const remaining = Math.max(0, endMs - now);

    return remaining / total;
}

function estimateProrationTotal(
    item: SubscriptionItem,
    quantity: number,
    price: CreatePriceOption,
    periodStart: string | null,
    periodEnd: string | null,
): number | null {
    const fraction = prorationFraction(periodStart, periodEnd);

    if (fraction <= 0) {
        return null;
    }

    const oldAmount = (item.price.unitAmount ?? 0) * item.quantity * fraction;
    const newAmount = (price.unitAmount ?? 0) * quantity * fraction;
    const delta = newAmount - oldAmount;

    if (Math.abs(delta) < 0.005) {
        return null;
    }

    return delta;
}

export default function ManageSubscriptionItemSheet({
    subscription,
    item,
    products,
    open,
    onOpenChange,
}: Props) {
    const [quantity, setQuantity] = useState(1);
    const [priceId, setPriceId] = useState<number | null>(null);
    const [processing, setProcessing] = useState(false);

    const product = useMemo(
        () =>
            item
                ? products.find((candidate) => candidate.id === item.product.id)
                : undefined,
        [item, products],
    );

    const selectedPrice = useMemo(
        () => product?.prices.find((price) => price.id === priceId),
        [product, priceId],
    );

    useEffect(() => {
        if (!item) {
            return;
        }

        setQuantity(item.quantity);
        setPriceId(item.price.id);
    }, [item]);

    const hasChanges =
        item !== null &&
        (quantity !== item.quantity || priceId !== item.price.id);

    const prorationTotal =
        item && selectedPrice
            ? estimateProrationTotal(
                  item,
                  quantity,
                  selectedPrice,
                  subscription.currentPeriodStart,
                  subscription.currentPeriodEnd,
              )
            : null;

    const save = () => {
        if (!item || !hasChanges || priceId === null) {
            return;
        }

        setProcessing(true);

        router.post(
            updateItem({ subscription: subscription.id, item: item.id }).url,
            {
                quantity,
                price_id: priceId,
            },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onSuccess: () => onOpenChange(false),
            },
        );
    };

    if (!item) {
        return null;
    }

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="flex w-full flex-col sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>Manage item</SheetTitle>
                    <SheetDescription>
                        Change the plan or quantity for {item.product.name}.
                        Proration is calculated for the rest of the current
                        billing period.
                    </SheetDescription>
                </SheetHeader>

                <div className="flex flex-1 flex-col gap-6 overflow-y-auto px-4 pb-4">
                    <div className="space-y-2">
                        <Label htmlFor="plan">Plan</Label>
                        <Select
                            value={priceId?.toString() ?? undefined}
                            onValueChange={(value) =>
                                setPriceId(Number.parseInt(value, 10))
                            }
                        >
                            <SelectTrigger id="plan" data-test="item-plan-select">
                                <SelectValue placeholder="Choose a price" />
                            </SelectTrigger>
                            <SelectContent>
                                {product?.prices.map((price) => (
                                    <SelectItem
                                        key={price.id}
                                        value={price.id.toString()}
                                    >
                                        {price.label}
                                        {price.unitAmount !== null
                                            ? ` · ${money(price.unitAmount, price.currency)}`
                                            : ''}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="space-y-2">
                        <Label>Quantity</Label>
                        <div className="flex items-center gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                disabled={quantity <= 1 || processing}
                                onClick={() =>
                                    setQuantity((current) =>
                                        Math.max(1, current - 1),
                                    )
                                }
                                data-test="item-quantity-decrease"
                            >
                                <Minus className="size-4" />
                            </Button>
                            <span
                                className="min-w-8 text-center text-sm font-medium"
                                data-test="item-quantity-value"
                            >
                                {quantity}
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                disabled={quantity >= 1000 || processing}
                                onClick={() =>
                                    setQuantity((current) =>
                                        Math.min(1000, current + 1),
                                    )
                                }
                                data-test="item-quantity-increase"
                            >
                                <Plus className="size-4" />
                            </Button>
                        </div>
                    </div>

                    {prorationTotal !== null && hasChanges && (
                        <div
                            className="rounded-lg border bg-muted/40 p-4 text-sm"
                            data-test="proration-preview"
                        >
                            <p className="font-medium">Proration preview</p>
                            <p className="mt-1 text-muted-foreground">
                                {prorationTotal >= 0
                                    ? 'Due today for the rest of this period'
                                    : 'Credit for unused time this period'}
                            </p>
                            <p className="mt-2 text-lg font-semibold">
                                {money(
                                    Math.abs(prorationTotal),
                                    subscription.currency,
                                )}
                            </p>
                        </div>
                    )}

                    {hasChanges && prorationTotal === null && (
                        <p className="text-sm text-muted-foreground">
                            No proration applies — this change takes effect on
                            the next renewal.
                        </p>
                    )}
                </div>

                <SheetFooter className="sm:flex-row sm:justify-end">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={save}
                        disabled={!hasChanges || processing || priceId === null}
                        data-test="save-item-changes"
                    >
                        {processing && <Spinner />}
                        Save changes
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
