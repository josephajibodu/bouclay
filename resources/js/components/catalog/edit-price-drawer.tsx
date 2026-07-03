import { Form } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { update } from '@/routes/catalog/prices';
import type { Price } from '@/types';

type Props = PropsWithChildren<{
    currentTeamSlug: string;
    productId: number;
    price: Price;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}>;

export default function EditPriceDrawer({
    children,
    currentTeamSlug,
    productId,
    price,
    open,
    onOpenChange,
}: Props) {
    const [name, setName] = useState(price.name ?? '');

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetTrigger asChild>{children}</SheetTrigger>
            <SheetContent className="h-auto w-full rounded-xl border sm:inset-y-4 sm:right-4 sm:w-3/4 sm:max-w-md">
                <Form
                    key={price.id}
                    {...update.form([currentTeamSlug, productId, price.id])}
                    className="flex h-full flex-col"
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <SheetHeader>
                                <SheetTitle>Edit price</SheetTitle>
                                <SheetDescription>
                                    Amount and interval are still editable —
                                    no customers have subscribed to this
                                    price yet.
                                </SheetDescription>
                            </SheetHeader>

                            <div className="flex flex-col gap-6 overflow-y-auto px-4">
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
                                    <Label htmlFor="edit-price-amount">
                                        Amount ({price.currency})
                                    </Label>
                                    <Input
                                        id="edit-price-amount"
                                        name="unit_amount"
                                        type="number"
                                        min={0}
                                        step="0.01"
                                        defaultValue={price.unitAmount ?? ''}
                                        data-test="edit-price-amount"
                                    />
                                    <InputError
                                        message={errors.unit_amount}
                                    />
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
