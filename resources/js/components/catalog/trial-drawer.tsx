import { Form } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
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
import type { TrialDurationUnit } from '@/types';

type Props = PropsWithChildren<{
    currentTeamSlug: string;
    productId: number;
    productName: string;
    /** Fixed target price — used when triggered from a specific price row. */
    priceId?: number;
    /** Prices without a trial yet — shown as a picker when `priceId` isn't fixed (the section-level "Create trial" trigger). */
    eligiblePrices?: { id: number; label: string }[];
    trial?: { id: number; durationAmount: number; durationUnit: TrialDurationUnit } | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}>;

export default function TrialDrawer({
    children,
    currentTeamSlug,
    productId,
    productName,
    priceId,
    eligiblePrices,
    trial,
    open,
    onOpenChange,
}: Props) {
    const [amount, setAmount] = useState(String(trial?.durationAmount ?? 14));
    const [unit, setUnit] = useState<TrialDurationUnit>(
        trial?.durationUnit ?? 'day',
    );
    const [selectedPriceId, setSelectedPriceId] = useState<string>(
        priceId ? String(priceId) : (eligiblePrices?.[0]?.id.toString() ?? ''),
    );

    const needsPricePicker = !trial && !priceId;
    const targetPriceId = priceId ?? Number(selectedPriceId);

    const formProps = trial
        ? update.form([currentTeamSlug, productId, trial.id])
        : store.form([currentTeamSlug, productId, targetPriceId || 0]);

    const endDay =
        unit === 'day'
            ? Number(amount) || 0
            : unit === 'week'
              ? (Number(amount) || 0) * 7
              : (Number(amount) || 0) * 30;

    const canSubmit = trial || Boolean(targetPriceId);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetTrigger asChild>{children}</SheetTrigger>
            <SheetContent className="h-auto w-full rounded-xl border sm:inset-y-4 sm:right-4 sm:w-3/4 sm:max-w-md">
                <Form
                    key={String(open)}
                    {...formProps}
                    transform={(data) => ({
                        ...data,
                        duration_amount: Number(amount) || 1,
                        duration_unit: unit,
                    })}
                    className="flex h-full flex-col"
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ processing }) => (
                        <>
                            <SheetHeader>
                                <SheetTitle>
                                    {trial ? 'Edit free trial' : 'Add a free trial'}
                                </SheetTitle>
                                <SheetDescription>
                                    Give new customers time to try{' '}
                                    {productName} before they're charged.
                                </SheetDescription>
                            </SheetHeader>

                            <div className="flex flex-col gap-6 overflow-y-auto px-4">
                                {needsPricePicker && (
                                    <div className="grid gap-2">
                                        <Label>Apply to price</Label>
                                        {eligiblePrices &&
                                        eligiblePrices.length > 0 ? (
                                            <Select
                                                value={selectedPriceId}
                                                onValueChange={
                                                    setSelectedPriceId
                                                }
                                            >
                                                <SelectTrigger
                                                    data-test="trial-drawer-price"
                                                >
                                                    <SelectValue placeholder="Choose a price" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {eligiblePrices.map(
                                                        (p) => (
                                                            <SelectItem
                                                                key={p.id}
                                                                value={String(
                                                                    p.id,
                                                                )}
                                                            >
                                                                {p.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        ) : (
                                            <p className="text-sm text-muted-foreground">
                                                Every price already has a
                                                trial.
                                            </p>
                                        )}
                                    </div>
                                )}

                                <div className="flex items-center gap-2">
                                    <span className="text-sm">
                                        Trial customers for
                                    </span>
                                    <Input
                                        type="number"
                                        min={1}
                                        className="w-20"
                                        value={amount}
                                        onChange={(e) =>
                                            setAmount(e.target.value)
                                        }
                                        data-test="trial-drawer-amount"
                                    />
                                    <Select
                                        value={unit}
                                        onValueChange={(value) =>
                                            setUnit(value as TrialDurationUnit)
                                        }
                                    >
                                        <SelectTrigger className="w-32">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="day">
                                                days
                                            </SelectItem>
                                            <SelectItem value="week">
                                                weeks
                                            </SelectItem>
                                            <SelectItem value="month">
                                                months
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="rounded-lg border bg-muted/30 p-3 text-sm">
                                    <div className="flex items-center justify-between text-muted-foreground">
                                        <span>Day 0</span>
                                        <span>Day {endDay}</span>
                                        <span>Ongoing</span>
                                    </div>
                                    <div className="mt-1 flex items-center justify-between font-medium">
                                        <span>Trial starts</span>
                                        <span>First charge</span>
                                        <span>Continues</span>
                                    </div>
                                </div>

                                <p className="text-sm text-muted-foreground">
                                    Customers redeem this trial once — reusing
                                    an account won't grant a second free
                                    period.
                                </p>
                            </div>

                            <SheetFooter className="flex-row justify-end gap-2">
                                <SheetClose asChild>
                                    <Button variant="secondary">
                                        Cancel
                                    </Button>
                                </SheetClose>
                                <Button
                                    type="submit"
                                    disabled={processing || !canSubmit}
                                    data-test="trial-drawer-submit"
                                >
                                    {processing && <Spinner />}
                                    {trial ? 'Save changes' : 'Add trial'}
                                </Button>
                            </SheetFooter>
                        </>
                    )}
                </Form>
            </SheetContent>
        </Sheet>
    );
}
