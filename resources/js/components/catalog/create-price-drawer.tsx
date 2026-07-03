import { Form } from '@inertiajs/react';
import { ChevronRight, Plus, Trash2 } from 'lucide-react';
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
import { store } from '@/routes/catalog/prices';
import type { BillingInterval, PriceType, PricingModel } from '@/types';

type Tier = { upTo: string; unitAmount: string; flatAmount: string };

type Props = PropsWithChildren<{
    currentTeamSlug: string;
    productId: number;
    productName: string;
    defaultCurrency: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}>;

export default function CreatePriceDrawer({
    children,
    currentTeamSlug,
    productId,
    productName,
    defaultCurrency,
    open,
    onOpenChange,
}: Props) {
    const [type, setType] = useState<PriceType>('recurring');
    const [pricingModel, setPricingModel] = useState<PricingModel>('standard');
    const [advancedOpen, setAdvancedOpen] = useState(false);
    const [unitAmount, setUnitAmount] = useState('');
    const [billingInterval, setBillingInterval] =
        useState<BillingInterval>('month');
    const [billingFrequency, setBillingFrequency] = useState('1');
    const [tiers, setTiers] = useState<Tier[]>([
        { upTo: '', unitAmount: '', flatAmount: '' },
        { upTo: '', unitAmount: '', flatAmount: '' },
    ]);

    const reset = () => {
        setType('recurring');
        setPricingModel('standard');
        setAdvancedOpen(false);
        setUnitAmount('');
        setBillingInterval('month');
        setBillingFrequency('1');
        setTiers([
            { upTo: '', unitAmount: '', flatAmount: '' },
            { upTo: '', unitAmount: '', flatAmount: '' },
        ]);
    };

    const handleOpenChange = (nextOpen: boolean) => {
        onOpenChange(nextOpen);

        if (!nextOpen) {
            reset();
        }
    };

    const previewAmount = parseFloat(unitAmount);
    const canPreview =
        pricingModel === 'standard' &&
        unitAmount.trim() !== '' &&
        !Number.isNaN(previewAmount);

    const parsedTiers = tiers
        .filter((t) => t.unitAmount.trim() !== '' && !Number.isNaN(parseFloat(t.unitAmount)))
        .map((t) => ({
            upTo: t.upTo.trim() !== '' ? parseInt(t.upTo, 10) : null,
            unitAmount: parseFloat(t.unitAmount),
        }));
    const tierSummary =
        pricingModel === 'graduated' && parsedTiers.length > 0
            ? formatTierSummary(parsedTiers, defaultCurrency)
            : null;

    return (
        <Sheet open={open} onOpenChange={handleOpenChange}>
            <SheetTrigger asChild>{children}</SheetTrigger>
            <SheetContent className="h-auto w-full overflow-y-auto rounded-xl border sm:inset-y-4 sm:right-4 sm:w-3/4 sm:max-w-md">
                <Form
                    key={String(open)}
                    {...store.form([currentTeamSlug, productId])}
                    transform={(data) => ({
                        ...data,
                        type,
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
                    })}
                    className="flex h-full flex-col"
                    onSuccess={() => handleOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <SheetHeader>
                                <SheetTitle>Add a price</SheetTitle>
                                <SheetDescription>
                                    Prices define how customers pay for{' '}
                                    {productName}.
                                </SheetDescription>
                            </SheetHeader>

                            <div className="flex flex-col gap-6 overflow-y-auto px-4">
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
                                            data-test="price-type-recurring"
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
                                        <Label htmlFor="drawer-unit-amount">
                                            Amount
                                        </Label>
                                        <div className="flex items-center gap-2">
                                            <Badge
                                                variant="secondary"
                                                className="h-9 px-3"
                                            >
                                                {defaultCurrency}
                                            </Badge>
                                            <Input
                                                id="drawer-unit-amount"
                                                type="number"
                                                min={0}
                                                step="0.01"
                                                placeholder="15000"
                                                value={unitAmount}
                                                onChange={(e) =>
                                                    setUnitAmount(
                                                        e.target.value,
                                                    )
                                                }
                                                data-test="drawer-unit-amount"
                                            />
                                        </div>
                                        <InputError
                                            message={
                                                errors[
                                                    'unit_amount' as never
                                                ]
                                            }
                                        />
                                        <p className="text-sm text-muted-foreground">
                                            To sell in another currency, add
                                            a separate price.
                                        </p>
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
                                                    data-test="pricing-model-standard"
                                                >
                                                    Standard
                                                </ToggleGroupItem>
                                                <ToggleGroupItem
                                                    value="graduated"
                                                    className="flex-1 data-[state=on]:border-primary data-[state=on]:bg-primary data-[state=on]:text-primary-foreground"
                                                    data-test="pricing-model-graduated"
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
                                                                index ===
                                                                tiers.length -
                                                                    1
                                                            }
                                                            value={tier.upTo}
                                                            data-test={`tier-up-to-${index}`}
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
                                                            data-test={`tier-unit-amount-${index}`}
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
                                                            disabled={
                                                                tiers.length <=
                                                                2
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

                                                {tierSummary && (
                                                    <div
                                                        className="rounded-lg border bg-muted/30 p-3 text-sm"
                                                        data-test="tier-preview"
                                                    >
                                                        <span className="text-muted-foreground">
                                                            Customers will see{' '}
                                                        </span>
                                                        <span className="font-medium">
                                                            {tierSummary}
                                                        </span>
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </CollapsibleContent>
                                </Collapsible>

                                {pricingModel === 'standard' && (
                                    <div
                                        className="rounded-lg border bg-muted/30 p-3 text-sm"
                                        data-test="price-preview"
                                    >
                                        {canPreview ? (
                                            <>
                                                <span className="text-muted-foreground">
                                                    Customers will see{' '}
                                                </span>
                                                <span className="font-medium">
                                                    {formatPriceInterval(
                                                        previewAmount,
                                                        defaultCurrency,
                                                        billingInterval,
                                                        Number(
                                                            billingFrequency,
                                                        ) || 1,
                                                    )}
                                                </span>
                                            </>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                Fill in the amount to preview
                                            </span>
                                        )}
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
                                    data-test="create-price-submit"
                                >
                                    {processing && <Spinner />}
                                    Add price
                                </Button>
                            </SheetFooter>
                        </>
                    )}
                </Form>
            </SheetContent>
        </Sheet>
    );
}
