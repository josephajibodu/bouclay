import { Form } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { PropsWithChildren } from 'react';
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
    SheetTrigger,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { store, update } from '@/routes/catalog/pricing-journeys';
import type { BillingInterval, Price, PricingJourney } from '@/types';

type StepDraft = {
    priceId: string;
    durationInterval: BillingInterval;
    durationCount: string;
};

type Props = PropsWithChildren<{
    productId: number;
    /** Present when editing; omit to create a new journey. */
    journey?: PricingJourney;
    /** Recurring prices under this product — a journey's steps are
     * Product-scoped, Plan-agnostic. */
    candidatePrices: Price[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}>;

function draftsFromJourney(journey: PricingJourney | undefined): StepDraft[] {
    if (!journey || journey.steps.length === 0) {
        return [
            { priceId: '', durationInterval: 'month', durationCount: '1' },
            { priceId: '', durationInterval: 'month', durationCount: '1' },
        ];
    }

    return journey.steps.map((step) => ({
        priceId: String(step.priceId),
        durationInterval: step.durationInterval ?? 'month',
        durationCount: step.durationCount !== null ? String(step.durationCount) : '1',
    }));
}

/**
 * Create or edit a Pricing Journey — a reusable, Product-scoped commercial
 * offer template (e.g. "$1/mo for 3 months, then $10/mo forever"). Every
 * step but the last needs a duration; the last step always runs forever.
 * Freely editable for life — editing a journey never affects a schedule
 * already copied from it.
 */
export default function PricingJourneyDrawer({
    children,
    productId,
    journey,
    candidatePrices,
    open,
    onOpenChange,
}: Props) {
    const editing = journey !== undefined;

    const [name, setName] = useState(journey?.name ?? '');
    const [description, setDescription] = useState(journey?.description ?? '');
    const [steps, setSteps] = useState<StepDraft[]>(draftsFromJourney(journey));

    const handleOpenChange = (nextOpen: boolean) => {
        onOpenChange(nextOpen);

        if (nextOpen) {
            setName(journey?.name ?? '');
            setDescription(journey?.description ?? '');
            setSteps(draftsFromJourney(journey));
        }
    };

    const updateStep = (index: number, patch: Partial<StepDraft>) => {
        setSteps((prev) =>
            prev.map((step, i) => (i === index ? { ...step, ...patch } : step)),
        );
    };

    const addStep = () => {
        setSteps((prev) => [
            ...prev,
            { priceId: '', durationInterval: 'month', durationCount: '1' },
        ]);
    };

    const removeStep = (index: number) => {
        setSteps((prev) => prev.filter((_, i) => i !== index));
    };

    const formProps = editing
        ? update.form([productId, journey.id])
        : store.form(productId);

    const priceLabel = (price: Price) =>
        price.name ??
        `${price.currency} ${((price.unitAmount ?? 0) / 1).toFixed(2)}/${price.billingInterval ?? ''}`;

    return (
        <Sheet open={open} onOpenChange={handleOpenChange}>
            {children && <SheetTrigger asChild>{children}</SheetTrigger>}
            <SheetContent className="h-auto w-full overflow-y-auto rounded-xl border sm:inset-y-4 sm:right-4 sm:w-3/4 sm:max-w-xl">
                <Form
                    key={`${journey?.id ?? 'new'}-${String(open)}`}
                    {...formProps}
                    transform={(data) => ({
                        ...data,
                        name,
                        description: description.trim() !== '' ? description : null,
                        steps: steps.map((step, index) => ({
                            price_id: step.priceId ? Number(step.priceId) : null,
                            duration_interval:
                                index === steps.length - 1
                                    ? null
                                    : step.durationInterval,
                            duration_count:
                                index === steps.length - 1
                                    ? null
                                    : Number(step.durationCount || 1),
                        })),
                    })}
                    className="flex h-full flex-col"
                    onSuccess={() => handleOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <SheetHeader>
                                <SheetTitle>
                                    {editing
                                        ? 'Edit pricing journey'
                                        : 'New pricing journey'}
                                </SheetTitle>
                                <SheetDescription>
                                    A reusable commercial offer — e.g. "$1/mo
                                    for 3 months, then $10/mo forever." Steps
                                    can span any plan under this product; the
                                    last step always runs forever.
                                </SheetDescription>
                            </SheetHeader>

                            <div className="flex flex-col gap-6 overflow-y-auto px-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="journey-name">Name</Label>
                                    <Input
                                        id="journey-name"
                                        value={name}
                                        onChange={(e) => setName(e.target.value)}
                                        placeholder="Starter Offer"
                                        data-test="journey-name"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="journey-description">
                                        Description{' '}
                                        <span className="text-muted-foreground">
                                            (optional)
                                        </span>
                                    </Label>
                                    <Input
                                        id="journey-description"
                                        value={description}
                                        onChange={(e) =>
                                            setDescription(e.target.value)
                                        }
                                        placeholder="Intro pricing for new customers"
                                        data-test="journey-description"
                                    />
                                </div>

                                <div className="grid gap-3">
                                    <Label>Steps</Label>
                                    {steps.map((step, index) => {
                                        const isLast = index === steps.length - 1;

                                        return (
                                            <div
                                                key={index}
                                                className="space-y-3 rounded-lg border p-3"
                                                data-test={`journey-step-${index}`}
                                            >
                                                <div className="flex items-center justify-between">
                                                    <Badge variant="outline">
                                                        Step {index + 1}
                                                        {isLast && ' · forever'}
                                                    </Badge>
                                                    {steps.length > 1 && (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() =>
                                                                removeStep(index)
                                                            }
                                                        >
                                                            <Trash2 className="size-4" />
                                                        </Button>
                                                    )}
                                                </div>

                                                <Select
                                                    value={step.priceId}
                                                    onValueChange={(value) =>
                                                        updateStep(index, {
                                                            priceId: value,
                                                        })
                                                    }
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="Choose a price" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {candidatePrices.map(
                                                            (price) => (
                                                                <SelectItem
                                                                    key={price.id}
                                                                    value={String(
                                                                        price.id,
                                                                    )}
                                                                >
                                                                    {priceLabel(
                                                                        price,
                                                                    )}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>

                                                {!isLast && (
                                                    <div className="flex items-center gap-2">
                                                        <Input
                                                            type="number"
                                                            min={1}
                                                            className="w-20"
                                                            value={
                                                                step.durationCount
                                                            }
                                                            onChange={(e) =>
                                                                updateStep(
                                                                    index,
                                                                    {
                                                                        durationCount:
                                                                            e
                                                                                .target
                                                                                .value,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                        <Select
                                                            value={
                                                                step.durationInterval
                                                            }
                                                            onValueChange={(
                                                                value,
                                                            ) =>
                                                                updateStep(
                                                                    index,
                                                                    {
                                                                        durationInterval:
                                                                            value as BillingInterval,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger>
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="day">
                                                                    day(s)
                                                                </SelectItem>
                                                                <SelectItem value="week">
                                                                    week(s)
                                                                </SelectItem>
                                                                <SelectItem value="month">
                                                                    month(s)
                                                                </SelectItem>
                                                                <SelectItem value="year">
                                                                    year(s)
                                                                </SelectItem>
                                                            </SelectContent>
                                                        </Select>
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })}

                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={addStep}
                                    >
                                        <Plus /> Add step
                                    </Button>
                                    <InputError message={errors.steps} />
                                </div>
                            </div>

                            <SheetFooter className="flex-row justify-end gap-2">
                                <SheetClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </SheetClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="journey-submit"
                                >
                                    {processing && <Spinner />}
                                    {editing ? 'Save journey' : 'Create journey'}
                                </Button>
                            </SheetFooter>
                        </>
                    )}
                </Form>
            </SheetContent>
        </Sheet>
    );
}
