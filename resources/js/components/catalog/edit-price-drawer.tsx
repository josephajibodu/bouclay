import { Form } from '@inertiajs/react';
import { ChevronRight, Layers, Plus, Trash2 } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import type {
    BillingInterval,
    Plan,
    Price,
    PriceType,
    PricingModel,
    TrialUnit,
} from '@/types';

type Tier = { upTo: string; unitAmount: string; flatAmount: string };

type Props = PropsWithChildren<{
    productId: number;
    price: Price;
    plans: Plan[];
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
    plans,
    open,
    onOpenChange,
}: Props) {
    // Reads as "edit" everywhere; once the price has subscribers the save
    // executes as a replace on the server — a new version, old one archived,
    // existing subscribers grandfathered. Fields stay editable either way.
    const willReplace = price.hasBeenUsed;
    const selectablePlans = plans.filter((p) => p.status !== 'archived');

    const [name, setName] = useState(price.name ?? '');
    const [type, setType] = useState<PriceType>(price.type);
    const [planId, setPlanId] = useState(
        price.planId !== null ? String(price.planId) : '',
    );
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
    const [trialEnabled, setTrialEnabled] = useState(
        price.trialLength !== null,
    );
    const [trialLength, setTrialLength] = useState(
        price.trialLength !== null ? String(price.trialLength) : '7',
    );
    const [trialUnit, setTrialUnit] = useState<TrialUnit>(
        price.trialUnit ?? 'day',
    );
    const [trialRequiresPaymentInfo, setTrialRequiresPaymentInfo] = useState(
        price.trialRequiresPaymentInfo,
    );
    const [trialOncePerCustomer, setTrialOncePerCustomer] = useState(
        price.trialOncePerCustomer,
    );

    const handleOpenChange = (nextOpen: boolean) => {
        onOpenChange(nextOpen);

        if (nextOpen) {
            setName(price.name ?? '');
            setType(price.type);
            setPlanId(price.planId !== null ? String(price.planId) : '');
            setPricingModel(price.pricingModel);
            setAdvancedOpen(price.pricingModel === 'graduated');
            setUnitAmount(
                price.unitAmount !== null ? String(price.unitAmount) : '',
            );
            setBillingInterval(price.billingInterval ?? 'month');
            setBillingFrequency(String(price.billingFrequency));
            setTiers(tiersFromPrice(price));
            setTrialEnabled(price.trialLength !== null);
            setTrialLength(
                price.trialLength !== null ? String(price.trialLength) : '7',
            );
            setTrialUnit(price.trialUnit ?? 'day');
            setTrialRequiresPaymentInfo(price.trialRequiresPaymentInfo);
            setTrialOncePerCustomer(price.trialOncePerCustomer);
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
                    transform={(data) => ({
                        ...data,
                        type,
                        plan_id:
                            type === 'recurring' && planId !== ''
                                ? Number(planId)
                                : null,
                        pricing_model: pricingModel,
                        unit_amount:
                            pricingModel === 'standard'
                                ? Number(unitAmount)
                                : undefined,
                        billing_interval:
                            type === 'recurring' ? billingInterval : undefined,
                        billing_frequency: Number(billingFrequency) || 1,
                        tiers:
                            pricingModel === 'graduated'
                                ? tiers
                                      .filter((t) => t.unitAmount.trim() !== '')
                                      .map((t) => ({
                                          up_to: t.upTo ? Number(t.upTo) : null,
                                          unit_amount: Number(t.unitAmount),
                                          flat_amount: t.flatAmount
                                              ? Number(t.flatAmount)
                                              : null,
                                      }))
                                : undefined,
                        trial_length:
                            type === 'recurring' && trialEnabled
                                ? Number(trialLength)
                                : null,
                        trial_unit:
                            type === 'recurring' && trialEnabled
                                ? trialUnit
                                : null,
                        trial_requires_payment_info:
                            type === 'recurring' && trialEnabled
                                ? trialRequiresPaymentInfo
                                : false,
                        trial_once_per_customer:
                            type === 'recurring' && trialEnabled
                                ? trialOncePerCustomer
                                : true,
                    })}
                    className="flex h-full flex-col"
                    onSuccess={() => handleOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <SheetHeader>
                                <SheetTitle>Edit price</SheetTitle>
                                <SheetDescription>
                                    {willReplace
                                        ? 'This price has subscribers — saving a change creates a new version and archives this one. Existing subscribers keep their current rate.'
                                        : 'Amount, plan, interval, and trial are all editable — no customers have subscribed to this price yet.'}
                                </SheetDescription>
                            </SheetHeader>

                            <div className="flex flex-col gap-6 overflow-y-auto px-4">
                                {willReplace && (
                                    <div className="flex items-start gap-2 rounded-lg border bg-muted/30 p-3 text-sm text-muted-foreground">
                                        <Layers className="mt-0.5 size-4 shrink-0" />
                                        <span>
                                            Saving issues a new version of this
                                            price. New signups get the updated
                                            rate; current subscribers are
                                            grandfathered on the original.
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
                                            value && setType(value as PriceType)
                                        }
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

                                {type === 'recurring' &&
                                    selectablePlans.length > 0 && (
                                        <div className="grid gap-2">
                                            <Label>Plan</Label>
                                            <Select
                                                value={planId}
                                                onValueChange={setPlanId}
                                            >
                                                <SelectTrigger data-test="edit-price-plan">
                                                    <SelectValue placeholder="Choose a plan" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {selectablePlans.map(
                                                        (plan) => (
                                                            <SelectItem
                                                                key={plan.id}
                                                                value={String(
                                                                    plan.id,
                                                                )}
                                                            >
                                                                {plan.name}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={
                                                    errors['plan_id' as never]
                                                }
                                            />
                                        </div>
                                    )}

                                {type === 'recurring' && (
                                    <div className="grid gap-2">
                                        <Label>Bills every</Label>
                                        <div className="flex items-center gap-2">
                                            <Input
                                                type="number"
                                                min={1}
                                                className="w-20"
                                                value={billingFrequency}
                                                onChange={(e) =>
                                                    setBillingFrequency(
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            <Select
                                                value={billingInterval}
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

                                {type === 'recurring' && (
                                    <div className="grid gap-3 rounded-lg border p-3">
                                        <label className="flex items-center gap-2">
                                            <Checkbox
                                                checked={trialEnabled}
                                                onCheckedChange={(checked) =>
                                                    setTrialEnabled(
                                                        checked === true,
                                                    )
                                                }
                                                data-test="edit-price-trial-enabled"
                                            />
                                            <span className="text-sm font-medium">
                                                Offer a free trial
                                            </span>
                                        </label>

                                        {trialEnabled && (
                                            <div className="grid gap-3">
                                                <div className="flex items-center gap-2">
                                                    <Input
                                                        type="number"
                                                        min={1}
                                                        className="w-20"
                                                        value={trialLength}
                                                        onChange={(e) =>
                                                            setTrialLength(
                                                                e.target.value,
                                                            )
                                                        }
                                                        data-test="edit-price-trial-length"
                                                    />
                                                    <Select
                                                        value={trialUnit}
                                                        onValueChange={(v) =>
                                                            setTrialUnit(
                                                                v as TrialUnit,
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
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                                <label className="flex items-center gap-2 text-sm text-muted-foreground">
                                                    <Checkbox
                                                        checked={
                                                            trialRequiresPaymentInfo
                                                        }
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            setTrialRequiresPaymentInfo(
                                                                checked ===
                                                                    true,
                                                            )
                                                        }
                                                    />
                                                    Require a card at signup
                                                </label>
                                                <label className="flex items-center gap-2 text-sm text-muted-foreground">
                                                    <Checkbox
                                                        checked={
                                                            trialOncePerCustomer
                                                        }
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            setTrialOncePerCustomer(
                                                                checked ===
                                                                    true,
                                                            )
                                                        }
                                                    />
                                                    Limit to one trial per
                                                    customer
                                                </label>
                                            </div>
                                        )}
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
                                            Advanced pricing (graduated tiers)
                                        </button>
                                    </CollapsibleTrigger>
                                    <CollapsibleContent className="mt-3 space-y-3">
                                        <div className="grid gap-2">
                                            <Label>Pricing model</Label>
                                            <ToggleGroup
                                                type="single"
                                                variant="outline"
                                                value={pricingModel}
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
                                                                tiers.length - 1
                                                                    ? 'and up'
                                                                    : 'Up to (qty)'
                                                            }
                                                            type="number"
                                                            disabled={
                                                                index ===
                                                                tiers.length - 1
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
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        setTiers((prev) => [
                                                            ...prev,
                                                            {
                                                                upTo: '',
                                                                unitAmount: '',
                                                                flatAmount: '',
                                                            },
                                                        ])
                                                    }
                                                >
                                                    <Plus /> Add tier
                                                </Button>
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
                                    <Button variant="secondary">Cancel</Button>
                                </SheetClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="edit-price-submit"
                                >
                                    {processing && <Spinner />}
                                    {willReplace
                                        ? 'Save as new version'
                                        : 'Save changes'}
                                </Button>
                            </SheetFooter>
                        </>
                    )}
                </Form>
            </SheetContent>
        </Sheet>
    );
}
