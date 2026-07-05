import { Form } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import {  useState } from 'react';
import type {PropsWithChildren} from 'react';
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
import { cn } from '@/lib/utils';
import { store } from '@/routes/customers';

type Props = PropsWithChildren<{
    open: boolean;
    onOpenChange: (open: boolean) => void;
    teamCurrency: string;
}>;

export default function CreateCustomerDrawer({
    children,
    open,
    onOpenChange,
    teamCurrency,
}: Props) {
    const [showMore, setShowMore] = useState(false);

    return (
        <Sheet
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    setShowMore(false);
                }

                onOpenChange(next);
            }}
        >
            <SheetTrigger asChild>{children}</SheetTrigger>
            <SheetContent className="h-auto w-full rounded-xl border sm:inset-y-4 sm:right-4 sm:w-3/4 sm:max-w-md">
                <Form
                    {...store.form()}
                    className="flex h-full flex-col"
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <SheetHeader>
                                <SheetTitle>Create customer</SheetTitle>
                                <SheetDescription>
                                    Add someone you want to bill. You can add
                                    payment details and more after.
                                </SheetDescription>
                            </SheetHeader>

                            <div className="flex flex-col gap-6 overflow-y-auto px-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="create-customer-email">
                                        Email
                                    </Label>
                                    <Input
                                        id="create-customer-email"
                                        name="email"
                                        type="email"
                                        required
                                        autoFocus
                                        placeholder="customer@example.com"
                                        data-test="create-customer-email"
                                    />
                                    <InputError message={errors.email} />
                                    <p className="text-xs text-muted-foreground">
                                        Where receipts and billing emails go.
                                    </p>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="create-customer-name">
                                        Name
                                    </Label>
                                    <Input
                                        id="create-customer-name"
                                        name="name"
                                        data-test="create-customer-name"
                                    />
                                    <InputError message={errors.name} />
                                    <p className="text-xs text-muted-foreground">
                                        Optional — helps you recognise them.
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    onClick={() => setShowMore((v) => !v)}
                                    className="flex w-fit items-center gap-1 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                                >
                                    <ChevronDown
                                        className={cn(
                                            'size-4 transition-transform',
                                            showMore && 'rotate-180',
                                        )}
                                    />
                                    More details (optional)
                                </button>

                                {showMore && (
                                    <div className="grid gap-6 border-l pl-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="create-customer-phone">
                                                Phone
                                            </Label>
                                            <Input
                                                id="create-customer-phone"
                                                name="phone"
                                                type="tel"
                                            />
                                            <InputError message={errors.phone} />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="create-customer-currency">
                                                Currency
                                            </Label>
                                            <Input
                                                id="create-customer-currency"
                                                name="currency"
                                                maxLength={3}
                                                placeholder={`Team default: ${teamCurrency}`}
                                                className="uppercase"
                                            />
                                            <InputError
                                                message={errors.currency}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="create-customer-external-ref">
                                                Your reference
                                            </Label>
                                            <Input
                                                id="create-customer-external-ref"
                                                name="external_ref"
                                            />
                                            <InputError
                                                message={errors.external_ref}
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Your own ID for this customer,
                                                if you have one. Must be unique.
                                            </p>
                                        </div>
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
                                    disabled={processing}
                                    data-test="create-customer-submit"
                                >
                                    {processing && <Spinner />}
                                    Create customer
                                </Button>
                            </SheetFooter>
                        </>
                    )}
                </Form>
            </SheetContent>
        </Sheet>
    );
}
