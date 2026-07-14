import { useForm } from '@inertiajs/react';
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
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { store, update } from '@/routes/discounts';
import type {
    DiscountDuration,
    DiscountEligibilityPlan,
    DiscountEligibilityPrice,
    DiscountSummary,
    DiscountType,
} from '@/types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The discount being edited, or null to create a new one. */
    discount: DiscountSummary | null;
    plans: DiscountEligibilityPlan[];
    prices: DiscountEligibilityPrice[];
    defaultCurrency: string;
};

type EligibilityMode = 'all' | 'plans' | 'prices';

type FormShape = {
    code: string;
    type: DiscountType;
    percentage: string;
    amount: string;
    currency: string;
    duration: DiscountDuration;
    duration_in_intervals: string;
    max_redemptions: string;
    eligible_plan_ids: number[];
    eligible_price_ids: number[];
    active: boolean;
};

/**
 * Create / edit a discount (schema.md §7). Eligibility follows the
 * eligible_price_ids-wins rule, so the form scopes to Plans OR Prices OR all —
 * never both at once.
 */
export default function DiscountDrawer({
    open,
    onOpenChange,
    discount,
    plans,
    prices,
    defaultCurrency,
}: Props) {
    const isEdit = discount !== null;

    const initialMode: EligibilityMode = discount?.eligiblePriceIds?.length
        ? 'prices'
        : discount?.eligiblePlanIds?.length
          ? 'plans'
          : 'all';

    const [mode, setMode] = useState<EligibilityMode>(initialMode);

    // The parent remounts this component (via `key`) each time the drawer
    // opens or targets a different discount, so useState seeds fresh — no
    // effect needed to re-sync from props.
    const { data, setData, post, patch, processing, errors } =
        useForm<FormShape>({
            code: discount?.code ?? '',
            type: discount?.type ?? 'percentage',
            percentage: discount?.percentage?.toString() ?? '',
            amount: discount?.amount?.toString() ?? '',
            currency: discount?.currency ?? defaultCurrency,
            duration: discount?.duration ?? 'once',
            duration_in_intervals:
                discount?.durationInIntervals?.toString() ?? '3',
            max_redemptions: discount?.maxRedemptions?.toString() ?? '',
            eligible_plan_ids: discount?.eligiblePlanIds ?? [],
            eligible_price_ids: discount?.eligiblePriceIds ?? [],
            active: discount?.active ?? true,
        });

    const toggleId = (field: 'eligible_plan_ids' | 'eligible_price_ids', id: number) => {
        const current = data[field];
        setData(
            field,
            current.includes(id)
                ? current.filter((x) => x !== id)
                : [...current, id],
        );
    };

    const submit = () => {
        // The chosen eligibility mode decides which list is sent — never both.
        if (mode !== 'plans') {
setData('eligible_plan_ids', []);
}

        if (mode !== 'prices') {
setData('eligible_price_ids', []);
}

        const onSuccess = () => onOpenChange(false);

        if (isEdit) {
            patch(update(discount.id).url, { preserveScroll: true, onSuccess });
        } else {
            post(store().url, { preserveScroll: true, onSuccess });
        }
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="flex w-full flex-col gap-0 sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle>
                        {isEdit ? 'Edit discount' : 'New discount'}
                    </SheetTitle>
                    <SheetDescription>
                        A percentage or flat reduction applied to eligible
                        subscriptions.
                    </SheetDescription>
                </SheetHeader>

                <div className="flex flex-1 flex-col gap-5 overflow-y-auto px-4 pb-6">
                    {/* Code */}
                    <div className="space-y-2">
                        <Label htmlFor="code">Code (optional)</Label>
                        <Input
                            id="code"
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value)}
                            placeholder="WELCOME20"
                        />
                        {errors.code && (
                            <p className="text-sm text-destructive">
                                {errors.code}
                            </p>
                        )}
                    </div>

                    {/* Type + magnitude */}
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-2">
                            <Label>Type</Label>
                            <Select
                                value={data.type}
                                onValueChange={(v) =>
                                    setData('type', v as DiscountType)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="percentage">
                                        Percentage
                                    </SelectItem>
                                    <SelectItem value="flat">
                                        Flat amount
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {data.type === 'percentage' ? (
                            <div className="space-y-2">
                                <Label htmlFor="percentage">Percent off</Label>
                                <Input
                                    id="percentage"
                                    type="number"
                                    min={0}
                                    max={100}
                                    value={data.percentage}
                                    onChange={(e) =>
                                        setData('percentage', e.target.value)
                                    }
                                    placeholder="20"
                                />
                                {errors.percentage && (
                                    <p className="text-sm text-destructive">
                                        {errors.percentage}
                                    </p>
                                )}
                            </div>
                        ) : (
                            <div className="space-y-2">
                                <Label htmlFor="amount">
                                    Amount off ({data.currency})
                                </Label>
                                <Input
                                    id="amount"
                                    type="number"
                                    min={0}
                                    value={data.amount}
                                    onChange={(e) =>
                                        setData('amount', e.target.value)
                                    }
                                    placeholder="1000"
                                />
                                {errors.amount && (
                                    <p className="text-sm text-destructive">
                                        {errors.amount}
                                    </p>
                                )}
                            </div>
                        )}
                    </div>

                    {/* Duration */}
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-2">
                            <Label>Duration</Label>
                            <Select
                                value={data.duration}
                                onValueChange={(v) =>
                                    setData('duration', v as DiscountDuration)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="once">Once</SelectItem>
                                    <SelectItem value="repeating">
                                        Repeating
                                    </SelectItem>
                                    <SelectItem value="forever">
                                        Forever
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {data.duration === 'repeating' && (
                            <div className="space-y-2">
                                <Label htmlFor="intervals">
                                    For how many intervals
                                </Label>
                                <Input
                                    id="intervals"
                                    type="number"
                                    min={1}
                                    value={data.duration_in_intervals}
                                    onChange={(e) =>
                                        setData(
                                            'duration_in_intervals',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                        )}
                    </div>

                    {/* Eligibility */}
                    <div className="space-y-2">
                        <Label>Applies to</Label>
                        <Select
                            value={mode}
                            onValueChange={(v) => setMode(v as EligibilityMode)}
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All plans</SelectItem>
                                <SelectItem value="plans">
                                    Specific plans
                                </SelectItem>
                                <SelectItem value="prices">
                                    Specific prices
                                </SelectItem>
                            </SelectContent>
                        </Select>

                        {mode === 'plans' && (
                            <div className="max-h-40 space-y-1 overflow-y-auto rounded-md border p-2">
                                {plans.map((plan) => (
                                    <label
                                        key={plan.id}
                                        className="flex items-center gap-2 rounded p-1.5 text-sm hover:bg-muted/50"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={data.eligible_plan_ids.includes(
                                                plan.id,
                                            )}
                                            onChange={() =>
                                                toggleId(
                                                    'eligible_plan_ids',
                                                    plan.id,
                                                )
                                            }
                                        />
                                        {plan.productName} · {plan.name}
                                    </label>
                                ))}
                            </div>
                        )}

                        {mode === 'prices' && (
                            <div className="max-h-40 space-y-1 overflow-y-auto rounded-md border p-2">
                                {prices.map((price) => (
                                    <label
                                        key={price.id}
                                        className="flex items-center gap-2 rounded p-1.5 text-sm hover:bg-muted/50"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={data.eligible_price_ids.includes(
                                                price.id,
                                            )}
                                            onChange={() =>
                                                toggleId(
                                                    'eligible_price_ids',
                                                    price.id,
                                                )
                                            }
                                        />
                                        {price.planName
                                            ? `${price.planName} · `
                                            : ''}
                                        {price.label}
                                    </label>
                                ))}
                            </div>
                        )}
                        <p className="text-xs text-muted-foreground">
                            Price-level eligibility wins outright when set — a
                            promo scoped to a monthly price never touches the
                            yearly one (each cadence is its own subscription).
                        </p>
                    </div>

                    {/* Limits + active */}
                    <div className="space-y-2">
                        <Label htmlFor="max">Max redemptions (optional)</Label>
                        <Input
                            id="max"
                            type="number"
                            min={1}
                            value={data.max_redemptions}
                            onChange={(e) =>
                                setData('max_redemptions', e.target.value)
                            }
                            placeholder="Unlimited"
                        />
                    </div>

                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={data.active}
                            onChange={(e) => setData('active', e.target.checked)}
                        />
                        Active — available to redeem
                    </label>
                </div>

                <div className="border-t p-4">
                    <Button
                        className="w-full"
                        disabled={processing}
                        onClick={submit}
                        data-test="save-discount"
                    >
                        {processing && <Spinner />}
                        {isEdit ? 'Save changes' : 'Create discount'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
