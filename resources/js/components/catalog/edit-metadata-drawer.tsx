import { Form } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { useState } from 'react';
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
import { update } from '@/routes/catalog/products';

type Row = { key: string; value: string };

type Props = PropsWithChildren<{
    currentTeamSlug: string;
    productId: number;
    customData: Record<string, string> | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}>;

export default function EditMetadataDrawer({
    children,
    currentTeamSlug,
    productId,
    customData,
    open,
    onOpenChange,
}: Props) {
    const initialRows = (): Row[] => {
        const entries = Object.entries(customData ?? {});

        return entries.length > 0
            ? entries.map(([key, value]) => ({ key, value }))
            : [{ key: '', value: '' }];
    };

    const [rows, setRows] = useState<Row[]>(initialRows);

    const handleOpenChange = (nextOpen: boolean) => {
        onOpenChange(nextOpen);

        if (nextOpen) {
            setRows(initialRows());
        }
    };

    return (
        <Sheet open={open} onOpenChange={handleOpenChange}>
            <SheetTrigger asChild>{children}</SheetTrigger>
            <SheetContent className="h-auto w-full rounded-xl border sm:inset-y-4 sm:right-4 sm:w-3/4 sm:max-w-md">
                <Form
                    key={String(open)}
                    {...update.form([currentTeamSlug, productId])}
                    transform={(data) => ({
                        ...data,
                        custom_data: rows.reduce<Record<string, string>>(
                            (acc, row) => {
                                if (row.key.trim() !== '') {
                                    acc[row.key.trim()] = row.value;
                                }

                                return acc;
                            },
                            {},
                        ),
                    })}
                    className="flex h-full flex-col"
                    onSuccess={() => handleOpenChange(false)}
                >
                    {({ processing }) => (
                        <>
                            <SheetHeader>
                                <SheetTitle>Edit metadata</SheetTitle>
                                <SheetDescription>
                                    Attach your own key/value data to this
                                    product — visible via the Bouclay API,
                                    never shown to customers.
                                </SheetDescription>
                            </SheetHeader>

                            <div className="flex flex-col gap-3 overflow-y-auto px-4">
                                {rows.map((row, index) => (
                                    <div
                                        key={index}
                                        className="flex items-start gap-2"
                                    >
                                        <div className="grid flex-1 gap-1">
                                            {index === 0 && (
                                                <Label className="text-xs text-muted-foreground">
                                                    Key
                                                </Label>
                                            )}
                                            <Input
                                                value={row.key}
                                                placeholder="external_id"
                                                data-test={`metadata-key-${index}`}
                                                onChange={(e) =>
                                                    setRows((prev) =>
                                                        prev.map((r, i) =>
                                                            i === index
                                                                ? {
                                                                      ...r,
                                                                      key: e
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : r,
                                                        ),
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="grid flex-1 gap-1">
                                            {index === 0 && (
                                                <Label className="text-xs text-muted-foreground">
                                                    Value
                                                </Label>
                                            )}
                                            <Input
                                                value={row.value}
                                                placeholder="acme-123"
                                                data-test={`metadata-value-${index}`}
                                                onChange={(e) =>
                                                    setRows((prev) =>
                                                        prev.map((r, i) =>
                                                            i === index
                                                                ? {
                                                                      ...r,
                                                                      value: e
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : r,
                                                        ),
                                                    )
                                                }
                                            />
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className={
                                                index === 0 ? 'mt-5' : ''
                                            }
                                            onClick={() =>
                                                setRows((prev) =>
                                                    prev.filter(
                                                        (_, i) => i !== index,
                                                    ),
                                                )
                                            }
                                            disabled={rows.length <= 1}
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                ))}

                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        setRows((prev) => [
                                            ...prev,
                                            { key: '', value: '' },
                                        ])
                                    }
                                >
                                    <Plus /> Add field
                                </Button>
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
                                    data-test="edit-metadata-submit"
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
