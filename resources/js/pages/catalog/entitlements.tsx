import { Form, Head, router } from '@inertiajs/react';
import { KeyRound, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import {
    destroy,
    grants as grantsRoute,
    index as entitlementsIndex,
    store,
} from '@/routes/catalog/entitlements';
import type { CatalogEntitlement, EntitlementGrantors } from '@/types';

type Props = {
    entitlements: CatalogEntitlement[];
    grantors: EntitlementGrantors;
    canManage: boolean;
};

export default function Entitlements({
    entitlements,
    grantors,
    canManage,
}: Props) {
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<CatalogEntitlement | null>(null);

    return (
        <div className="flex max-w-4xl flex-col gap-6 p-4">
            <Head title="Entitlements" />

            <div className="flex items-start justify-between gap-4">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold">Entitlements</h1>
                    <p className="text-sm text-muted-foreground">
                        Named capabilities your app checks against. Grant them
                        from a plan or product — your code asks whether a
                        customer has the code, and never has to look at
                        invoices or payments.
                    </p>
                </div>

                {canManage && (
                    <Button
                        onClick={() => setCreating(true)}
                        data-test="new-entitlement"
                    >
                        <Plus className="size-4" />
                        New entitlement
                    </Button>
                )}
            </div>

            {entitlements.length === 0 ? (
                <EmptyState />
            ) : (
                <div className="space-y-3">
                    {entitlements.map((entitlement) => (
                        <EntitlementRow
                            key={entitlement.id}
                            entitlement={entitlement}
                            canManage={canManage}
                            onEdit={() => setEditing(entitlement)}
                        />
                    ))}
                </div>
            )}

            <CreateSheet open={creating} onOpenChange={setCreating} />

            {/* Keyed so the checkbox state is rebuilt from scratch each time
                a different entitlement is opened. */}
            <GrantsSheet
                key={editing?.id ?? 'none'}
                entitlement={editing}
                grantors={grantors}
                onClose={() => setEditing(null)}
            />
        </div>
    );
}

function EntitlementRow({
    entitlement,
    canManage,
    onEdit,
}: {
    entitlement: CatalogEntitlement;
    canManage: boolean;
    onEdit: () => void;
}) {
    return (
        <div
            className="flex items-start justify-between gap-4 rounded-lg border p-4"
            data-test={`entitlement-${entitlement.code}`}
        >
            <div className="space-y-2">
                <div className="flex items-center gap-2">
                    <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-sm">
                        {entitlement.code}
                    </code>
                    <span className="text-sm text-muted-foreground">
                        {entitlement.name}
                    </span>
                </div>

                {entitlement.description && (
                    <p className="text-sm text-muted-foreground">
                        {entitlement.description}
                    </p>
                )}

                <div className="flex flex-wrap items-center gap-1.5">
                    {entitlement.grants.length === 0 ? (
                        <span className="text-xs text-muted-foreground">
                            Granted by nothing yet — no customer has this.
                        </span>
                    ) : (
                        entitlement.grants.map((grant) => (
                            <Badge key={grant.id} variant="secondary">
                                {grant.grantorType}: {grant.grantorName}
                            </Badge>
                        ))
                    )}
                </div>
            </div>

            {canManage && (
                <div className="flex shrink-0 items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={onEdit}
                        data-test={`edit-${entitlement.code}`}
                    >
                        Edit grants
                    </Button>

                    <Form
                        {...destroy.form(entitlement.id)}
                        options={{ preserveScroll: true }}
                        onBefore={() =>
                            confirm(
                                `Delete ${entitlement.code}? Anything gating on it loses access immediately.`,
                            )
                        }
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="ghost"
                                size="sm"
                                disabled={processing}
                                data-test={`delete-${entitlement.code}`}
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        )}
                    </Form>
                </div>
            )}
        </div>
    );
}

function CreateSheet({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent>
                <SheetHeader>
                    <SheetTitle>New entitlement</SheetTitle>
                    <SheetDescription>
                        The code is what your application checks against, so it
                        can’t be changed later.
                    </SheetDescription>
                </SheetHeader>

                <Form
                    {...store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4 px-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="code">Code</Label>
                                <Input
                                    id="code"
                                    name="code"
                                    placeholder="hd_streaming"
                                    required
                                    data-test="entitlement-code"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Lowercase snake_case. Permanent once saved.
                                </p>
                                {errors.code && (
                                    <p className="text-sm text-destructive">
                                        {errors.code}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    placeholder="HD Streaming"
                                    required
                                    data-test="entitlement-name"
                                />
                                {errors.name && (
                                    <p className="text-sm text-destructive">
                                        {errors.name}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="description">
                                    Description
                                </Label>
                                <Textarea
                                    id="description"
                                    name="description"
                                    rows={3}
                                />
                            </div>

                            <SheetFooter className="px-0">
                                <Button type="submit" disabled={processing}>
                                    Create
                                </Button>
                            </SheetFooter>
                        </>
                    )}
                </Form>
            </SheetContent>
        </Sheet>
    );
}

/**
 * The grants editor saves the whole set rather than diffing client-side —
 * simpler to reason about, and the server revalidates ownership anyway.
 */
function GrantsSheet({
    entitlement,
    grantors,
    onClose,
}: {
    entitlement: CatalogEntitlement | null;
    grantors: EntitlementGrantors;
    onClose: () => void;
}) {
    const [selected, setSelected] = useState<string[]>(
        () =>
            entitlement?.grants.map(
                (grant) => `${grant.grantorType}:${grant.grantorId}`,
            ) ?? [],
    );
    const [saving, setSaving] = useState(false);

    const toggle = (value: string) =>
        setSelected((current) =>
            current.includes(value)
                ? current.filter((item) => item !== value)
                : [...current, value],
        );

    const save = () => {
        if (!entitlement) {
            return;
        }

        setSaving(true);

        router.put(
            grantsRoute.url(entitlement.id),
            {
                grants: selected.map((value) => {
                    const [grantorType, grantorId] = value.split(':');

                    return { grantorType, grantorId: Number(grantorId) };
                }),
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSaving(false);
                    onClose();
                },
            },
        );
    };

    return (
        <Sheet
            open={entitlement !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <SheetContent>
                <SheetHeader>
                    <SheetTitle>
                        Grants for {entitlement?.code ?? ''}
                    </SheetTitle>
                    <SheetDescription>
                        Any customer subscribed to one of these gets this
                        entitlement.
                    </SheetDescription>
                </SheetHeader>

                <div className="space-y-6 overflow-y-auto px-4">
                    <GrantorGroup
                        label="Plans"
                        type="plan"
                        options={grantors.plans}
                        selected={selected}
                        onToggle={toggle}
                    />
                    <GrantorGroup
                        label="Products"
                        type="product"
                        options={grantors.products}
                        selected={selected}
                        onToggle={toggle}
                    />
                </div>

                <SheetFooter>
                    <Button
                        onClick={save}
                        disabled={saving}
                        data-test="save-grants"
                    >
                        Save grants
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}

function GrantorGroup({
    label,
    type,
    options,
    selected,
    onToggle,
}: {
    label: string;
    type: string;
    options: { id: number; name: string }[];
    selected: string[];
    onToggle: (value: string) => void;
}) {
    if (options.length === 0) {
        return null;
    }

    return (
        <div className="space-y-2">
            <Label>{label}</Label>
            {options.map((option) => {
                const value = `${type}:${option.id}`;

                return (
                    <label
                        key={value}
                        className="flex items-center gap-2 rounded-md border p-2 text-sm"
                    >
                        <Checkbox
                            checked={selected.includes(value)}
                            onCheckedChange={() => onToggle(value)}
                            data-test={`grant-${value}`}
                        />
                        {option.name}
                    </label>
                );
            })}
        </div>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed p-10 text-center">
            <KeyRound className="size-6 text-muted-foreground" />
            <p className="font-medium">No entitlements yet</p>
            <p className="max-w-md text-sm text-muted-foreground">
                Entitlements let your app ask “can this customer stream in
                HD?” instead of “is invoice 41 paid?”. Create one, grant it
                from a plan, and check the code in your code.
            </p>
        </div>
    );
}

Entitlements.layout = () => ({
    breadcrumbs: [{ title: 'Entitlements', href: entitlementsIndex() }],
});
