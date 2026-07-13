import { Form } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
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
import { store, update } from '@/routes/catalog/plans';
import type { Plan, PlanStatus } from '@/types';

type Props = PropsWithChildren<{
    productId: number;
    /** Present when editing; omit to create a new plan. */
    plan?: Plan;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}>;

/**
 * Create or edit a plan — the named tier ("Premium") whose billable
 * variants are its prices. A draft or archived plan's prices stop being
 * offered to new subscriptions (enforced server-side).
 */
export default function PlanDrawer({
    children,
    productId,
    plan,
    open,
    onOpenChange,
}: Props) {
    const editing = plan !== undefined;

    const [name, setName] = useState(plan?.name ?? '');
    const [code, setCode] = useState(plan?.code ?? '');
    const [status, setStatus] = useState<PlanStatus>(plan?.status ?? 'active');

    const handleOpenChange = (nextOpen: boolean) => {
        onOpenChange(nextOpen);

        if (nextOpen) {
            setName(plan?.name ?? '');
            setCode(plan?.code ?? '');
            setStatus(plan?.status ?? 'active');
        }
    };

    const formProps = editing
        ? update.form([productId, plan.id])
        : store.form(productId);

    return (
        <Sheet open={open} onOpenChange={handleOpenChange}>
            {children && <SheetTrigger asChild>{children}</SheetTrigger>}
            <SheetContent className="h-auto w-full overflow-y-auto rounded-xl border sm:inset-y-4 sm:right-4 sm:w-3/4 sm:max-w-md">
                <Form
                    key={`${plan?.id ?? 'new'}-${String(open)}`}
                    {...formProps}
                    transform={(data) => ({
                        ...data,
                        name,
                        code: code.trim() !== '' ? code : null,
                        status,
                    })}
                    className="flex h-full flex-col"
                    onSuccess={() => handleOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <SheetHeader>
                                <SheetTitle>
                                    {editing ? 'Edit plan' : 'Add a plan'}
                                </SheetTitle>
                                <SheetDescription>
                                    Plans are the named tiers a customer picks.
                                    Their prices are the billable variants —
                                    monthly, yearly, per-seat.
                                </SheetDescription>
                            </SheetHeader>

                            <div className="flex flex-col gap-6 overflow-y-auto px-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="plan-name">Name</Label>
                                    <Input
                                        id="plan-name"
                                        value={name}
                                        onChange={(e) =>
                                            setName(e.target.value)
                                        }
                                        placeholder="Premium"
                                        data-test="plan-name"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="plan-code">
                                        Code{' '}
                                        <span className="text-muted-foreground">
                                            (optional)
                                        </span>
                                    </Label>
                                    <Input
                                        id="plan-code"
                                        value={code}
                                        onChange={(e) =>
                                            setCode(e.target.value)
                                        }
                                        placeholder="premium"
                                        data-test="plan-code"
                                    />
                                    <InputError message={errors.code} />
                                </div>

                                <div className="grid gap-2">
                                    <Label>Status</Label>
                                    <Select
                                        value={status}
                                        onValueChange={(value) =>
                                            setStatus(value as PlanStatus)
                                        }
                                    >
                                        <SelectTrigger data-test="plan-status">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="draft">
                                                Draft — not yet purchasable
                                            </SelectItem>
                                            <SelectItem value="active">
                                                Active
                                            </SelectItem>
                                            <SelectItem value="archived">
                                                Archived — no new subscribers
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.status} />
                                    <p className="text-sm text-muted-foreground">
                                        Draft and archived plans keep their
                                        prices, but those prices can't be
                                        subscribed to by new customers.
                                    </p>
                                </div>
                            </div>

                            <SheetFooter className="flex-row justify-end gap-2">
                                <SheetClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </SheetClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="plan-submit"
                                >
                                    {processing && <Spinner />}
                                    {editing ? 'Save plan' : 'Add plan'}
                                </Button>
                            </SheetFooter>
                        </>
                    )}
                </Form>
            </SheetContent>
        </Sheet>
    );
}
