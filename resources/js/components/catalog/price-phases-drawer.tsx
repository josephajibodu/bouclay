import { Form } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
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
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { phases as phasesRoute } from '@/routes/catalog/prices';
import type { BillingInterval, Price } from '@/types';

type PhaseDraft = {
    /** 'amount' auto-creates a hidden charge price; 'existing' reuses one. */
    mode: 'amount' | 'existing';
    unitAmount: string;
    chargePriceId: string;
    durationCount: string;
    durationInterval: BillingInterval;
};

type Props = {
    productId: number;
    price: Price;
    /** Other prices on the product that a phase could charge instead. */
    candidatePrices: Price[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

function draftsFromPrice(price: Price): PhaseDraft[] {
    if (price.phases.length === 0) {
        return [
            {
                mode: 'amount',
                unitAmount: '',
                chargePriceId: '',
                durationCount: '1',
                durationInterval: price.billingInterval ?? 'month',
            },
        ];
    }

    return price.phases.map((phase) => ({
        mode: 'existing' as const,
        unitAmount:
            phase.chargePriceUnitAmount !== null
                ? String(phase.chargePriceUnitAmount)
                : '',
        chargePriceId: String(phase.chargePriceId),
        durationCount: String(phase.durationCount),
        durationInterval: phase.durationInterval,
    }));
}

/**
 * Author a price's phase schedule (schema.md §3): a paid intro period, a
 * transition to another plan's price, or a multi-step ramp. Each phase
 * either charges an inline amount (auto-created as a hidden charge price on
 * this plan) or reuses an existing recurring price of the same currency.
 * Only editable while the price has no subscribers.
 */
export default function PricePhasesDrawer({
    productId,
    price,
    candidatePrices,
    open,
    onOpenChange,
}: Props) {
    const [drafts, setDrafts] = useState<PhaseDraft[]>(draftsFromPrice(price));

    const reusable = candidatePrices.filter(
        (p) =>
            p.id !== price.id &&
            p.type === 'recurring' &&
            p.currency === price.currency,
    );

    const handleOpenChange = (nextOpen: boolean) => {
        onOpenChange(nextOpen);

        if (nextOpen) {
            setDrafts(draftsFromPrice(price));
        }
    };

    const updateDraft = (index: number, patch: Partial<PhaseDraft>) =>
        setDrafts((prev) =>
            prev.map((d, i) => (i === index ? { ...d, ...patch } : d)),
        );

    const addPhase = () =>
        setDrafts((prev) => [
            ...prev,
            {
                mode: 'amount',
                unitAmount: '',
                chargePriceId: '',
                durationCount: '1',
                durationInterval: price.billingInterval ?? 'month',
            },
        ]);

    return (
        <Sheet open={open} onOpenChange={handleOpenChange}>
            <SheetContent className="h-auto w-full overflow-y-auto rounded-xl border sm:inset-y-4 sm:right-4 sm:w-3/4 sm:max-w-xl">
                <Form
                    key={`${price.id}-${String(open)}`}
                    {...phasesRoute.form([productId, price.id])}
                    transform={() => ({
                        phases: drafts.map((d) => ({
                            charge_price_id:
                                d.mode === 'existing' && d.chargePriceId !== ''
                                    ? Number(d.chargePriceId)
                                    : null,
                            charge_price:
                                d.mode === 'amount'
                                    ? { unit_amount: Number(d.unitAmount) }
                                    : null,
                            duration_interval: d.durationInterval,
                            duration_count: Number(d.durationCount) || 1,
                        })),
                    })}
                    className="flex h-full flex-col"
                    onSuccess={() => handleOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <SheetHeader>
                                <SheetTitle>Phase schedule</SheetTitle>
                                <SheetDescription>
                                    Each phase charges for a fixed duration,
                                    then the next takes over. The final phase's
                                    price becomes the ongoing rate.
                                </SheetDescription>
                            </SheetHeader>

                            <div className="flex flex-col gap-4 overflow-y-auto px-4">
                                <InputError
                                    message={errors['phases' as never]}
                                />

                                {drafts.map((draft, index) => (
                                    <div
                                        key={index}
                                        className="space-y-3 rounded-lg border p-3"
                                        data-test="phase-row"
                                    >
                                        <div className="flex items-center justify-between">
                                            <Badge variant="secondary">
                                                Phase {index + 1}
                                            </Badge>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                disabled={drafts.length <= 1}
                                                onClick={() =>
                                                    setDrafts((prev) =>
                                                        prev.filter(
                                                            (_, i) =>
                                                                i !== index,
                                                        ),
                                                    )
                                                }
                                                data-test="remove-phase"
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </div>

                                        <div className="grid gap-2">
                                            <Label>Charge</Label>
                                            <Select
                                                value={draft.mode}
                                                onValueChange={(v) =>
                                                    updateDraft(index, {
                                                        mode: v as PhaseDraft['mode'],
                                                    })
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="amount">
                                                        A new amount
                                                    </SelectItem>
                                                    {reusable.length > 0 && (
                                                        <SelectItem value="existing">
                                                            An existing price
                                                        </SelectItem>
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        {draft.mode === 'amount' ? (
                                            <div className="flex items-center gap-2">
                                                <Badge
                                                    variant="secondary"
                                                    className="h-9 px-3"
                                                >
                                                    {price.currency}
                                                </Badge>
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    step="0.01"
                                                    placeholder="Amount per interval"
                                                    value={draft.unitAmount}
                                                    onChange={(e) =>
                                                        updateDraft(index, {
                                                            unitAmount:
                                                                e.target.value,
                                                        })
                                                    }
                                                    data-test="phase-amount"
                                                />
                                            </div>
                                        ) : (
                                            <Select
                                                value={draft.chargePriceId}
                                                onValueChange={(v) =>
                                                    updateDraft(index, {
                                                        chargePriceId: v,
                                                    })
                                                }
                                            >
                                                <SelectTrigger data-test="phase-existing-price">
                                                    <SelectValue placeholder="Choose a price" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {reusable.map((p) => (
                                                        <SelectItem
                                                            key={p.id}
                                                            value={String(p.id)}
                                                        >
                                                            {p.name ?? 'Price'}{' '}
                                                            ({p.unitAmount ?? 0}{' '}
                                                            {p.currency})
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        )}

                                        <div className="grid gap-2">
                                            <Label>Duration</Label>
                                            <div className="flex items-center gap-2">
                                                <Input
                                                    type="number"
                                                    min={1}
                                                    className="w-20"
                                                    value={draft.durationCount}
                                                    onChange={(e) =>
                                                        updateDraft(index, {
                                                            durationCount:
                                                                e.target.value,
                                                        })
                                                    }
                                                    data-test="phase-duration-count"
                                                />
                                                <Select
                                                    value={
                                                        draft.durationInterval
                                                    }
                                                    onValueChange={(v) =>
                                                        updateDraft(index, {
                                                            durationInterval:
                                                                v as BillingInterval,
                                                        })
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
                                    </div>
                                ))}

                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={addPhase}
                                    data-test="add-phase"
                                >
                                    <Plus /> Add phase
                                </Button>
                            </div>

                            <SheetFooter className="flex-row justify-end gap-2">
                                <SheetClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </SheetClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="save-phases-submit"
                                >
                                    {processing && <Spinner />}
                                    Save schedule
                                </Button>
                            </SheetFooter>
                        </>
                    )}
                </Form>
            </SheetContent>
        </Sheet>
    );
}
