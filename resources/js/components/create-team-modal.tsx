import { Form, usePage } from '@inertiajs/react';
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
import { countries } from '@/lib/countries';
import { store } from '@/routes/teams';
import type { BusinessType } from '@/types';

export default function CreateTeamModal({ children }: PropsWithChildren) {
    const { businessTypes } = usePage().props;
    const [open, setOpen] = useState(false);
    const [businessType, setBusinessType] =
        useState<BusinessType>('individual');
    const [country, setCountry] = useState(countries[0].code);

    return (
        <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger asChild>{children}</SheetTrigger>
            <SheetContent className="h-auto overflow-y-auto rounded-xl border sm:inset-y-4 sm:right-4 w-full sm:w-3/4 sm:max-w-md">
                <Form
                    key={String(open)}
                    {...store.form()}
                    className="flex h-full flex-col"
                    onSuccess={() => setOpen(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <SheetHeader>
                                <SheetTitle>Create a new business</SheetTitle>
                                <SheetDescription>
                                    Create a new business to collaborate with
                                    others.
                                </SheetDescription>
                            </SheetHeader>

                            <div className="flex flex-col gap-6 px-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">
                                        Business name{' '}
                                        <span className="text-destructive">*</span>
                                    </Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        data-test="create-team-name"
                                        placeholder="My business"
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="business_type">
                                        Business type{' '}
                                        <span className="text-destructive">*</span>
                                    </Label>
                                    <Select
                                        name="business_type"
                                        value={businessType}
                                        onValueChange={(value) =>
                                            setBusinessType(
                                                value as BusinessType,
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            id="business_type"
                                            className="w-full"
                                        >
                                            <SelectValue placeholder="Select a business type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {businessTypes.map((type) => (
                                                <SelectItem
                                                    key={type.value}
                                                    value={type.value}
                                                >
                                                    {type.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={errors.business_type}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="website">Website</Label>
                                    <Input
                                        id="website"
                                        type="url"
                                        name="website"
                                        placeholder="https://example.com"
                                    />
                                    <InputError message={errors.website} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="country">
                                        Country{' '}
                                        <span className="text-destructive">*</span>
                                    </Label>
                                    <Select
                                        name="country"
                                        value={country}
                                        onValueChange={setCountry}
                                    >
                                        <SelectTrigger
                                            id="country"
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

                                <div className="grid gap-2">
                                    <Label htmlFor="line1">
                                        Street address line 1{' '}
                                        <span className="text-destructive">*</span>
                                    </Label>
                                    <Input
                                        id="line1"
                                        name="line1"
                                        autoComplete="address-line1"
                                        placeholder="Street address"
                                        required
                                    />
                                    <InputError message={errors.line1} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="line2">
                                        Street address line 2
                                    </Label>
                                    <Input
                                        id="line2"
                                        name="line2"
                                        autoComplete="address-line2"
                                        placeholder="Apartment, suite, etc."
                                    />
                                    <InputError message={errors.line2} />
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="city">
                                            City / Town{' '}
                                            <span className="text-destructive">
                                                *
                                            </span>
                                        </Label>
                                        <Input
                                            id="city"
                                            name="city"
                                            autoComplete="address-level2"
                                            placeholder="City"
                                            required
                                        />
                                        <InputError message={errors.city} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="postal_code">
                                            Postal / Zip code
                                        </Label>
                                        <Input
                                            id="postal_code"
                                            name="postal_code"
                                            autoComplete="postal-code"
                                            placeholder="Postal code"
                                        />
                                        <InputError
                                            message={errors.postal_code}
                                        />
                                    </div>
                                </div>
                            </div>

                            <SheetFooter className="flex-row justify-end gap-2">
                                <SheetClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </SheetClose>

                                <Button
                                    type="submit"
                                    data-test="create-team-submit"
                                    disabled={processing}
                                >
                                    Create business
                                </Button>
                            </SheetFooter>
                        </>
                    )}
                </Form>
            </SheetContent>
        </Sheet>
    );
}
