import { Form } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
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
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { store, update } from '@/routes/catalog/trials';
import type { OtherProduct, PriceRef, TrialOffer } from '@/types';

/**
 * `children`, when provided, becomes the inline trigger. Omit it to control
 * the drawer purely via `open`/`onOpenChange` — e.g. an external "Edit"
 * button that isn't rendered inside this component.
 */
type Props = PropsWithChildren<{
    currentTeamSlug: string;
    productId: number;
    productName: string;
    /** This product's own prices — the trial price and same-product transition price are picked from here. */
    prices: PriceRef[];
    /** Other active products (with their active prices) for the "transition to a different product" picker. */
    otherProducts: OtherProduct[];
    /** Pre-select a transition price, e.g. when triggered from that price's row. */
    defaultTransitionPriceId?: number;
    trial?: TrialOffer | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}>;

export default function TrialDrawer({
    children,
    currentTeamSlug,
    productId,
    productName,
    prices,
    otherProducts,
    defaultTransitionPriceId,
    trial,
    open,
    onOpenChange,
}: Props) {
    const [name, setName] = useState(trial?.name ?? '');
    const [trialPriceId, setTrialPriceId] = useState<string>(
        trial ? String(trial.trialPrice.id) : '',
    );
    const [transitionToDifferentProduct, setTransitionToDifferentProduct] =
        useState(trial?.transitionToDifferentProduct ?? false);
    const [transitionProductId, setTransitionProductId] = useState<string>(
        trial?.transitionProduct ? String(trial.transitionProduct.id) : '',
    );
    const [transitionPriceId, setTransitionPriceId] = useState<string>(
        trial
            ? String(trial.transitionPrice.id)
            : defaultTransitionPriceId
              ? String(defaultTransitionPriceId)
              : '',
    );
    const [repeat, setRepeat] = useState((trial?.durationIterations ?? 1) > 1);
    const [iterations, setIterations] = useState(
        String(trial?.durationIterations ?? 1),
    );

    const formProps = trial
        ? update.form([currentTeamSlug, productId, trial.id])
        : store.form([currentTeamSlug, productId]);

    const transitionPricesForOtherProduct =
        otherProducts.find((p) => String(p.id) === transitionProductId)
            ?.prices ?? [];

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            {children && <SheetTrigger asChild>{children}</SheetTrigger>}
            <SheetContent className="h-auto w-full overflow-y-auto rounded-xl border sm:inset-y-4 sm:right-4 sm:w-3/4 sm:max-w-md">
                <Form
                    key={String(open)}
                    {...formProps}
                    transform={(data) => ({
                        ...data,
                        name,
                        trial_price_id: Number(trialPriceId) || undefined,
                        transition_to_different_product:
                            transitionToDifferentProduct,
                        transition_product_id: transitionToDifferentProduct
                            ? Number(transitionProductId) || undefined
                            : undefined,
                        transition_price_id:
                            Number(transitionPriceId) || undefined,
                        duration_iterations: repeat
                            ? Number(iterations) || 1
                            : 1,
                    })}
                    className="flex h-full flex-col"
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <SheetHeader>
                                <SheetTitle>
                                    {trial ? 'Edit trial' : 'Create trial'}
                                </SheetTitle>
                                <SheetDescription>
                                    Automatically attached to {productName}.
                                </SheetDescription>
                            </SheetHeader>

                            <div className="flex flex-col gap-6 overflow-y-auto px-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="trial-name">Name</Label>
                                    <p className="text-sm text-muted-foreground">
                                        This will appear on customers'
                                        receipts and invoices.
                                    </p>
                                    <Input
                                        id="trial-name"
                                        value={name}
                                        onChange={(e) =>
                                            setName(e.target.value)
                                        }
                                        placeholder="Free trial offer"
                                        data-test="trial-name"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label>Trial price</Label>
                                    <Select
                                        value={trialPriceId}
                                        onValueChange={setTrialPriceId}
                                    >
                                        <SelectTrigger
                                            data-test="trial-price-select"
                                        >
                                            <SelectValue placeholder="Choose a price" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {prices.map((price) => (
                                                <SelectItem
                                                    key={price.id}
                                                    value={String(price.id)}
                                                >
                                                    {price.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <p className="text-sm text-muted-foreground">
                                        What customers pay during the trial —
                                        a normal price, free or paid.
                                    </p>
                                    <InputError
                                        message={errors.trial_price_id}
                                    />
                                </div>

                                <label className="flex items-start gap-2 text-sm">
                                    <Checkbox
                                        checked={transitionToDifferentProduct}
                                        onCheckedChange={(checked) => {
                                            setTransitionToDifferentProduct(
                                                checked === true,
                                            );
                                            setTransitionPriceId('');
                                        }}
                                        data-test="transition-product-toggle"
                                    />
                                    Transition to a different product when
                                    trial ends
                                </label>

                                {transitionToDifferentProduct && (
                                    <div className="grid gap-2 pl-6">
                                        <Label>Product when trial ends</Label>
                                        <Select
                                            value={transitionProductId}
                                            onValueChange={(value) => {
                                                setTransitionProductId(value);
                                                setTransitionPriceId('');
                                            }}
                                        >
                                            <SelectTrigger
                                                data-test="transition-product-select"
                                            >
                                                <SelectValue placeholder="Choose a product" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {otherProducts.map(
                                                    (product) => (
                                                        <SelectItem
                                                            key={product.id}
                                                            value={String(
                                                                product.id,
                                                            )}
                                                        >
                                                            {product.name}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={
                                                errors.transition_product_id
                                            }
                                        />
                                    </div>
                                )}

                                <div
                                    className={
                                        transitionToDifferentProduct
                                            ? 'grid gap-2 pl-6'
                                            : 'grid gap-2'
                                    }
                                >
                                    <Label>Price when trial ends</Label>
                                    <Select
                                        value={transitionPriceId}
                                        onValueChange={setTransitionPriceId}
                                        disabled={
                                            transitionToDifferentProduct &&
                                            !transitionProductId
                                        }
                                    >
                                        <SelectTrigger
                                            data-test="transition-price-select"
                                        >
                                            <SelectValue placeholder="Choose a price" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(transitionToDifferentProduct
                                                ? transitionPricesForOtherProduct
                                                : prices
                                            ).map((price) => (
                                                <SelectItem
                                                    key={price.id}
                                                    value={String(price.id)}
                                                >
                                                    {price.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={errors.transition_price_id}
                                    />
                                </div>

                                <div className="space-y-2 border-t pt-4">
                                    <label className="flex items-start gap-2 text-sm">
                                        <Checkbox
                                            checked={repeat}
                                            onCheckedChange={(checked) =>
                                                setRepeat(checked === true)
                                            }
                                            data-test="repeat-toggle"
                                        />
                                        Repeat
                                    </label>
                                    {repeat && (
                                        <div className="flex items-center gap-2 pl-6 text-sm">
                                            <span>Repeat</span>
                                            <Input
                                                type="number"
                                                min={1}
                                                className="w-20"
                                                value={iterations}
                                                onChange={(e) =>
                                                    setIterations(
                                                        e.target.value,
                                                    )
                                                }
                                                data-test="repeat-iterations"
                                            />
                                            <span>times.</span>
                                        </div>
                                    )}
                                    {!repeat && (
                                        <p className="pl-6 text-sm text-muted-foreground">
                                            Number of times the trial price
                                            will repeat.
                                        </p>
                                    )}
                                </div>
                            </div>

                            <SheetFooter className="flex-row justify-end gap-2">
                                <SheetClose asChild>
                                    <Button variant="secondary">
                                        Cancel
                                    </Button>
                                </SheetClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="trial-drawer-submit"
                                >
                                    {processing && <Spinner />}
                                    {trial ? 'Save changes' : 'Create'}
                                </Button>
                            </SheetFooter>
                        </>
                    )}
                </Form>
            </SheetContent>
        </Sheet>
    );
}
