import { Form } from '@inertiajs/react';
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
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/customers';
import type { CustomerDetail } from '@/types';

type Props = {
    customer: CustomerDetail;
    teamCurrency: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

function GroupLabel({ children }: { children: string }) {
    return (
        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
            {children}
        </p>
    );
}

export default function EditCustomerDrawer({
    customer,
    teamCurrency,
    open,
    onOpenChange,
}: Props) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="h-auto w-full rounded-xl border sm:inset-y-4 sm:right-4 sm:w-3/4 sm:max-w-md">
                <Form
                    key={customer.id}
                    {...update.form(customer.id)}
                    className="flex h-full flex-col"
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <SheetHeader>
                                <SheetTitle>Edit customer</SheetTitle>
                                <SheetDescription>
                                    Update billing details for this customer.
                                </SheetDescription>
                            </SheetHeader>

                            <div className="flex flex-col gap-6 overflow-y-auto px-4">
                                <div className="grid gap-4">
                                    <GroupLabel>Identity</GroupLabel>
                                    <div className="grid gap-2">
                                        <Label htmlFor="edit-customer-name">
                                            Name
                                        </Label>
                                        <Input
                                            id="edit-customer-name"
                                            name="name"
                                            defaultValue={customer.name ?? ''}
                                            data-test="edit-customer-name"
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="edit-customer-email">
                                            Email
                                        </Label>
                                        <Input
                                            id="edit-customer-email"
                                            name="email"
                                            type="email"
                                            defaultValue={customer.email}
                                            required
                                            data-test="edit-customer-email"
                                        />
                                        <InputError message={errors.email} />
                                        <p className="text-xs text-muted-foreground">
                                            Receipts and billing emails go here.
                                        </p>
                                    </div>
                                </div>

                                <div className="grid gap-4">
                                    <GroupLabel>Locale &amp; billing</GroupLabel>
                                    <div className="grid gap-2">
                                        <Label htmlFor="edit-customer-phone">
                                            Phone
                                        </Label>
                                        <Input
                                            id="edit-customer-phone"
                                            name="phone"
                                            type="tel"
                                            defaultValue={customer.phone ?? ''}
                                        />
                                        <InputError message={errors.phone} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="edit-customer-currency">
                                            Currency
                                        </Label>
                                        <Input
                                            id="edit-customer-currency"
                                            name="currency"
                                            maxLength={3}
                                            defaultValue={
                                                customer.currency ?? ''
                                            }
                                            placeholder={`Team default: ${teamCurrency}`}
                                            className="uppercase"
                                        />
                                        <InputError message={errors.currency} />
                                        <p className="text-xs text-muted-foreground">
                                            Used for this customer's invoices and
                                            subscriptions.
                                        </p>
                                    </div>
                                </div>

                                <div className="grid gap-4">
                                    <GroupLabel>Developer</GroupLabel>
                                    <div className="grid gap-2">
                                        <Label htmlFor="edit-customer-external-ref">
                                            Your reference
                                        </Label>
                                        <Input
                                            id="edit-customer-external-ref"
                                            name="external_ref"
                                            defaultValue={
                                                customer.externalRef ?? ''
                                            }
                                        />
                                        <InputError
                                            message={errors.external_ref}
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Your own ID for reconciling this
                                            customer with your system.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <SheetFooter className="flex-row justify-end gap-2">
                                <SheetClose asChild>
                                    <Button variant="secondary" type="button">
                                        Cancel
                                    </Button>
                                </SheetClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="edit-customer-submit"
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
