import { Form } from '@inertiajs/react';
import { ChevronRight, Lock, Plus, Trash2 } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatPriceInterval, formatTierSummary } from '@/lib/utils';
import { update } from '@/routes/catalog/prices';
import type { BillingInterval, Price, PriceType, PricingModel } from '@/types';

type Tier = { upTo: string; unitAmount: string; flatAmount: string };

type Props = PropsWithChildren<{
    productId: number;
    price: Price;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}>;

function tiersFromPrice(price: Price): Tier[] {
    if (price.tiers.length === 0) {
        return [
            { upTo: '', unitAmount: '', flatAmount: '' },
            { upTo: '', unitAmount: '', flatAmount: '' },
        ];
    }

    return price.tiers.map((tier) => ({
        upTo: tier.upTo !== null ? String(tier.upTo) : '',
        unitAmount: String(tier.unitAmount),
        flatAmount: tier.flatAmount !== null ? String(tier.flatAmount) : '',
    }));
}

export default function EditPriceDrawer({
    children,
    productId,
    price,
    open,
    onOpenChange,
}: Props) {
    const locked = price.hasBeenUsed;

    const [name, setName] = useState(price.name ?? '');
    const [type, setType] = useState<PriceType>(price.type);
    const [pricingModel, setPricingModel] = useState<PricingModel>(
        price.pricingModel,
    );
    const [advancedOpen, setAdvancedOpen] = useState(
        price.pricingModel === 'graduated',
    );
    const [unitAmount, setUnitAmount] = useState(
        price.unitAmount !== null ? String(price.unitAmount) : '',
    );
    const [currency] = useState(price.currency);
    const [billingInterval, setBillingInterval] = useState<BillingInterval>(
        price.billingInterval ?? 'month',
    );
    const [billingFrequency, setBillingFrequency] = useState(
        String(price.billingFrequency),
    );
    const [tiers, setTiers] = useState<Tier[]>(tiersFromPrice(price));

    const handleOpenChange = (nextOpen: boolean) => {
        onOpenChange(nextOpen);

        if (nextOpen) {
            setName(price.name ?? '');
            setType(price.type);
            setPricingModel(price.pricingModel);
            setAdvancedOpen(price.pricingModel === 'graduated');
            setUnitAmount(
                price.unitAmount !== null ? String(price.unitAmount) : '',
            );
            setBillingInterval(price.billingInterval ?? 'month');
            setBillingFrequency(String(price.billingFrequency));
            setTiers(tiersFromPrice(price));
        }
    };

    const previewAmount = parseFloat(unitAmount);
    const canPreview =
        pricingModel === 'standard' &&
        unitAmount.trim() !== '' &&
        !Number.isNaN(previewAmount);

    return (
        <Sheet open={open} onOpenChange={handleOpenChange}>
            {children && <SheetTrigger asChild>{children}</SheetTrigger>}
            <SheetContent className="h-auto w-full overflow-y-auto rounded-xl border sm:inset-y-4 sm:right-4 sm:w-3/4 sm:max-w-xl">
                <Form
                    key={`${price.id}-${String(open)}`}
                    {...update.form([productId, price.id])}
                    transform={(data) =>
                        locked
                            ? { name: data.name }
                            : {
                                  ...data,
                                  type,
                                  pricing_model: pricingModel,
                                  unit_amount:
                                      pricingModel === 'standard'
                                          ? Number(unitAmount)
                                          : undefined,
                                  billing_interval:
                                      type === 'recurring'
                                          ? billingInterval
                                          : undefined,
                                  billing_frequency:
                                      Number(billingFrequency) || 1,
                                  tiers:
                                      pricingModel === 'graduated'
                                          ? tiers
                                                .filter(
                                                    (t) =>
                                                        t.unitAmount.trim() !==
                                                        '',
                                                )
                                                .map((t) => ({
                                                    up_to: t.upTo
                                                        ? Number(t.upTo)
                                                        : null,
                                                    unit_amount:
                                                        Number(t.unitAmount),
                                                    flat_amount: t.flatAmount
                                                        ? Number(t.flatAmount)
                                                        : null,
                                                }))
                                          : undefined,
                              }
                    }
                    className="flex h-full flex-col"
                    onSuccess={() => handleOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <SheetHeader>
                                <SheetTitle>Edit price</SheetTitle>
                                <SheetDescription>
                                    {locked
                                        ? 'This price has been used and can no longer change its shape — only the display name can still be edited.'
                                        : 'Amount, interval, and pricing model are still editable — no customers have subscribed to this price yet.'}
                                </SheetDescription>
                            </SheetHeader>

                            <div className="flex flex-col gap-6 overflow-y-auto px-4">
                                {locked && (
                                    <div className="flex items-start gap-2 rounded-lg border bg-muted/30 p-3 text-sm text-muted-foreground">
                                        <Lock className="mt-0.5 size-4 shrink-0" />
                                        <span>
                                            Changing the amount, currency,
                                            interval, or pricing model
                                            requires creating a new price and
                                            archiving this one — existing
                                            subscribers keep their current
                                            rate.
                                        </span>
                                    </div>
                                )}

                                <div className="grid gap-2">
                                    <Label htmlFor="edit-price-name">
                                        Display name
                                    </Label>
                                    <Input
                                        id="edit-price-name"
                                        name="name"
                                        value={name}
                                        onChange={(e) =>
                                            setName(e.target.value)
                                        }
                                        placeholder="Monthly"
                                        data-test="edit-price-name"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label>Billing interval</Label>
                                    <ToggleGroup
                                        type="single"
                                        variant="outline"
                                        value={type}
                                        onValueChange={(value) =>
                                            value &&
                                            setType(value as PriceType)
                                        }
                                        disabled={locked}
                                        className="w-full"
                                    >
                                        <ToggleGroupItem
                                            value="one_time"
                                            className="flex-1 data-[state=on]:border-primary data-[state=on]:bg-primary data-[state=on]:text-primary-foreground"
                                        >
                                            One time
                                        </ToggleGroupItem>
                                        <ToggleGroupItem
                                            value="recurring"
                                            className="flex-1 data-[state=on]:border-primary data-[state=on]:bg-primary data-[state=on]:text-primary-foreground"
                                        >
                                            Recurring
                                        </ToggleGroupItem>
                                    </ToggleGroup>
                                </div>

                                {type === 'recurring' && (
                                    <div className="grid gap-2">
                                        <Label>Bills every</Label>
                                        <div className="flex items-center gap-2">
                                            <Input
                                                type="number"
                                                min={1}
                                                className="w-20"
                                                value={billingFrequency}
                                                disabled={locked}
                                                onChange={(e) =>
                                                    setBillingFrequency(
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            <Select
                                                value={billingInterval}
                                                disabled={locked}
                                                onValueChange={(value) =>
                                                    setBillingInterval(
                                                        value as BillingInterval,
                                                    )
                                                }
                                            >
                                                <SelectTrigger className="w-40">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="day">
                                                        Day(s)
                                                    </SelectItem>
                                                    <SelectItem value="week">
                                                        Week(s)
                                                    </SelectItem>
                                                    <SelectItem value="month">
                                                        Month(s)
                                                    </SelectItem>
                                                    <SelectItem value="year">
                                                        Year(s)
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </div>
                                )}

                                {pricingModel === 'standard' && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="edit-price-amount">
                                            Amount
                                        </Label>
                                        <div className="flex items-center gap-2">
                                            <Badge
                                                variant="secondary"
                                                className="h-9 px-3"
                                            >
                                                {currency}
                                            </Badge>
                                            <Input
                                                id="edit-price-amount"
                                                type="number"
                                                min={0}
                                                step="0.01"
                                                value={unitAmount}
                                                disabled={locked}
                                                onChange={(e) =>
                                                    setUnitAmount(
                                                        e.target.value,
                                                    )
                                                }
                                                data-test="edit-price-amount"
                                            />
                                        </div>
                                        <InputError
                                            message={
                                                errors['unit_amount' as never]
                                            }
                                        />
                                    </div>
                                )}

                                <Collapsible
                                    open={advancedOpen}
                                    onOpenChange={setAdvancedOpen}
                                >
                                    <CollapsibleTrigger asChild>
                                        <button
                                            type="button"
                                            className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                                        >
                                            <ChevronRight
                                                className={`size-4 transition-transform ${advancedOpen ? 'rotate-90' : ''}`}
                                            />
                                            Advanced pricing (graduated
                                            tiers)
                                        </button>
                                    </CollapsibleTrigger>
                                    <CollapsibleContent className="mt-3 space-y-3">
                                        <div className="grid gap-2">
                                            <Label>Pricing model</Label>
                                            <ToggleGroup
                                                type="single"
                                                variant="outline"
                                                value={pricingModel}
                                                disabled={locked}
                                                onValueChange={(value) =>
                                                    value &&
                                                    setPricingModel(
                                                        value as PricingModel,
                                                    )
                                                }
                                                className="w-full"
                                            >
                                                <ToggleGroupItem
                                                    value="standard"
                                                    className="flex-1 data-[state=on]:border-primary data-[state=on]:bg-primary data-[state=on]:text-primary-foreground"
                                                >
                                                    Standard
                                                </ToggleGroupItem>
                                                <ToggleGroupItem
                                                    value="graduated"
                                                    className="flex-1 data-[state=on]:border-primary data-[state=on]:bg-primary data-[state=on]:text-primary-foreground"
                                                >
                                                    Graduated by volume
                                                </ToggleGroupItem>
                                            </ToggleGroup>
                                        </div>

                                        {pricingModel === 'graduated' && (
                                            <div className="space-y-3">
                                                {tiers.map((tier, index) => (
                                                    <div
                                                        key={index}
                                                        className="flex items-center gap-2"
                                                    >
                                                        <Input
                                                            placeholder={
                                                                index ===
                                                                tiers.length -
                                                                    1
                                                                    ? 'and up'
                                                                    : 'Up to (qty)'
                                                            }
                                                            type="number"
                                                            disabled={
                                                                locked ||
                                                                index ===
                                                                    tiers.length -
                                                                        1
                                                            }
                                                            value={tier.upTo}
                                                            onChange={(e) =>
                                                                setTiers(
                                                                    (prev) =>
                                                                        prev.map(
                                                                            (
                                                                                t,
                                                                                i,
                                                                            ) =>
                                                                                i ===
                                                                                index
                                                                                    ? {
                                                                                          ...t,
                                                                                          upTo: e
                                                                                              .target
                                                                                              .value,
                                                                                      }
                                                                                    : t,
                                                                        ),
                                                                )
                                                            }
                                                        />
                                                        <Input
                                                            placeholder="Price per unit"
                                                            type="number"
                                                            disabled={locked}
                                                            value={
                                                                tier.unitAmount
                                                            }
                                                            onChange={(e) =>
                                                                setTiers(
                                                                    (prev) =>
                                                                        prev.map(
                                                                            (
                                                                                t,
                                                                                i,
                                                                            ) =>
                                                                                i ===
                                                                                index
                                                                                    ? {
                                                                                          ...t,
                                                                                          unitAmount:
                                                                                              e
                                                                                                  .target
                                                                                                  .value,
                                                                                      }
                                                                                    : t,
                                                                        ),
                                                                )
                                                            }
                                                        />
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            disabled={locked}
                                                            onClick={() =>
                                                                setTiers(
                                                                    (prev) =>
                                                                        prev.filter(
                                                                            (
                                                                                _,
                                                                                i,
                                                                            ) =>
                                                                                i !==
                                                                                index,
                                                                        ),
                                                                )
                                                            }
                                                        >
                                                            <Trash2 className="size-4" />
                                                        </Button>
                                                    </div>
                                                ))}
                                                {!locked && (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            setTiers(
                                                                (prev) => [
                                                                    ...prev,
                                                                    {
                                                                        upTo: '',
                                                                        unitAmount:
                                                                            '',
                                                                        flatAmount:
                                                                            '',
                                                                    },
                                                                ],
                                                            )
                                                        }
                                                    >
                                                        <Plus /> Add tier
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                    </CollapsibleContent>
                                </Collapsible>

                                {pricingModel === 'standard' && canPreview && (
                                    <div className="rounded-lg border bg-muted/30 p-3 text-sm">
                                        <span className="text-muted-foreground">
                                            Customers will see{' '}
                                        </span>
                                        <span className="font-medium">
                                            {formatPriceInterval(
                                                previewAmount,
                                                currency,
                                                billingInterval,
                                                Number(billingFrequency) || 1,
                                            )}
                                        </span>
                                    </div>
                                )}

                                {pricingModel === 'graduated' && (
                                    <div className="rounded-lg border bg-muted/30 p-3 text-sm">
                                        <span className="text-muted-foreground">
                                            Customers will see{' '}
                                        </span>
                                        <span className="font-medium">
                                            {formatTierSummary(
                                                tiers
                                                    .filter(
                                                        (t) =>
                                                            t.unitAmount.trim() !==
                                                            '',
                                                    )
                                                    .map((t) => ({
                                                        upTo: t.upTo
                                                            ? Number(t.upTo)
                                                            : null,
                                                        unitAmount: Number(
                                                            t.unitAmount,
                                                        ),
                                                    })),
                                                currency,
                                            )}
                                        </span>
                                    </div>
                                )}
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
                                    data-test="edit-price-submit"
                                >
                                    {processing && <Spinner />}
                                    Save changes
                                </Button>
                            </SheetFooter>
                        </>
                    )}
                </Form>
            </SheetContent>
        </Sheet>
    );
}
