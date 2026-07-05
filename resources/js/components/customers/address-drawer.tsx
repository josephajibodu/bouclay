import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { countries } from '@/lib/countries';
import { store, update } from '@/routes/customers/addresses';
import type { CustomerAddress, CustomerDetail } from '@/types';

type Props = {
    customer: CustomerDetail;
    address: CustomerAddress | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function AddressDrawer({
    customer,
    address,
    open,
    onOpenChange,
}: Props) {
    const isEdit = address !== null;
    const [type, setType] = useState(address?.type ?? 'billing');
    const [country, setCountry] = useState(address?.country ?? '');
    const [showLine2, setShowLine2] = useState(Boolean(address?.line2));
    const [isDefault, setIsDefault] = useState(address?.isDefault ?? false);

    const revealed = country !== '';

    const formProps = isEdit
        ? update.form({ customer: customer.id, address: address.id })
        : store.form(customer.id);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="h-auto w-full rounded-xl border sm:inset-y-4 sm:right-4 sm:w-3/4 sm:max-w-md">
                <Form
                    key={address?.id ?? 'new'}
                    {...formProps}
                    className="flex h-full flex-col"
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <SheetHeader>
                                <SheetTitle>
                                    {isEdit ? 'Edit address' : 'Add address'}
                                </SheetTitle>
                                <SheetDescription>
                                    Shown on this customer's invoices and
                                    receipts.
                                </SheetDescription>
                            </SheetHeader>

                            <div className="flex flex-col gap-6 overflow-y-auto px-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="address-type">Type</Label>
                                    <Select
                                        name="type"
                                        value={type}
                                        onValueChange={(value) =>
                                            setType(
                                                value as 'billing' | 'shipping',
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            id="address-type"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="billing">
                                                Billing
                                            </SelectItem>
                                            <SelectItem value="shipping">
                                                Shipping
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="address-country">
                                        Country
                                    </Label>
                                    <Select
                                        name="country"
                                        value={country}
                                        onValueChange={setCountry}
                                    >
                                        <SelectTrigger
                                            id="address-country"
                                            className="w-full"
                                        >
                                            <SelectValue placeholder="Select a country" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {countries.map((c) => (
                                                <SelectItem
                                                    key={c.code}
                                                    value={c.code}
                                                >
                                                    {c.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.country} />
                                </div>

                                {revealed && (
                                    <div className="grid gap-6 border-l pl-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="address-line1">
                                                Address
                                            </Label>
                                            <Input
                                                id="address-line1"
                                                name="line1"
                                                defaultValue={
                                                    address?.line1 ?? ''
                                                }
                                                placeholder="Street address"
                                                data-test="address-line1"
                                            />
                                            <InputError message={errors.line1} />
                                            {showLine2 ? (
                                                <Input
                                                    name="line2"
                                                    defaultValue={
                                                        address?.line2 ?? ''
                                                    }
                                                    placeholder="Apartment, suite, etc."
                                                    className="mt-2"
                                                />
                                            ) : (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setShowLine2(true)
                                                    }
                                                    className="w-fit text-xs font-medium text-muted-foreground hover:text-foreground"
                                                >
                                                    + Add apartment, suite, etc.
                                                </button>
                                            )}
                                        </div>

                                        <div className="grid grid-cols-2 gap-4">
                                            <div className="grid gap-2">
                                                <Label htmlFor="address-city">
                                                    City
                                                </Label>
                                                <Input
                                                    id="address-city"
                                                    name="city"
                                                    defaultValue={
                                                        address?.city ?? ''
                                                    }
                                                />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="address-region">
                                                    Region
                                                </Label>
                                                <Input
                                                    id="address-region"
                                                    name="region"
                                                    defaultValue={
                                                        address?.region ?? ''
                                                    }
                                                />
                                            </div>
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="address-postal">
                                                Postal code
                                            </Label>
                                            <Input
                                                id="address-postal"
                                                name="postal_code"
                                                defaultValue={
                                                    address?.postalCode ?? ''
                                                }
                                            />
                                        </div>

                                        <label className="flex items-center gap-2 text-sm">
                                            <Checkbox
                                                checked={isDefault}
                                                onCheckedChange={(value) =>
                                                    setIsDefault(value === true)
                                                }
                                            />
                                            Set as default {type} address
                                        </label>
                                        <input
                                            type="hidden"
                                            name="is_default"
                                            value={isDefault ? '1' : '0'}
                                        />
                                    </div>
                                )}
                            </div>

                            <SheetFooter className="flex-row justify-end gap-2">
                                <SheetClose asChild>
                                    <Button variant="secondary" type="button">
                                        Cancel
                                    </Button>
                                </SheetClose>
                                <Button
                                    type="submit"
                                    disabled={processing || !revealed}
                                    data-test="address-submit"
                                >
                                    {processing && <Spinner />}
                                    Save address
                                </Button>
                            </SheetFooter>
                        </>
                    )}
                </Form>
            </SheetContent>
        </Sheet>
    );
}
