import { Form } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
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
import { Textarea } from '@/components/ui/textarea';
import { update } from '@/routes/catalog/products';
import type { ProductDetail } from '@/types';

type Props = PropsWithChildren<{
    product: ProductDetail;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}>;

export default function EditProductDrawer({
    children,
    product,
    open,
    onOpenChange,
}: Props) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetTrigger asChild>{children}</SheetTrigger>
            <SheetContent className="h-auto w-full rounded-xl border sm:inset-y-4 sm:right-4 sm:w-3/4 sm:max-w-md">
                <Form
                    key={product.id}
                    {...update.form(product.id)}
                    className="flex h-full flex-col"
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <SheetHeader>
                                <SheetTitle>Edit product</SheetTitle>
                                <SheetDescription>
                                    Update the name, description, or category
                                    shown to your team and on invoices.
                                </SheetDescription>
                            </SheetHeader>

                            <div className="flex flex-col gap-6 overflow-y-auto px-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="edit-product-name">
                                        Name
                                    </Label>
                                    <Input
                                        id="edit-product-name"
                                        name="name"
                                        defaultValue={product.name}
                                        required
                                        data-test="edit-product-name"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="edit-product-description">
                                        Description
                                    </Label>
                                    <Textarea
                                        id="edit-product-description"
                                        name="description"
                                        defaultValue={
                                            product.description ?? ''
                                        }
                                        rows={3}
                                    />
                                    <InputError
                                        message={errors.description}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="edit-product-category">
                                        Category
                                    </Label>
                                    <Input
                                        id="edit-product-category"
                                        name="category"
                                        defaultValue={product.category ?? ''}
                                    />
                                    <InputError message={errors.category} />
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
                                    data-test="edit-product-submit"
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
