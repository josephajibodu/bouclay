import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { GrantableEntitlement } from '@/types';

/**
 * Pick what a plan or product grants — the grants editor from the grantor's
 * side (IMPLEMENTATION_V2 §V2-5), which is where a merchant actually asks the
 * question: "what does Premium include?".
 *
 * Saves the whole set rather than diffing; the server re-derives which ids are
 * really the team's either way.
 */
export function GrantsEditor({
    open,
    onOpenChange,
    title,
    url,
    entitlements,
    granted,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    /** The endpoint that replaces this grantor's grants. */
    url: string;
    entitlements: GrantableEntitlement[];
    granted: number[];
}) {
    const [selected, setSelected] = useState<number[]>(granted);
    const [saving, setSaving] = useState(false);

    const toggle = (id: number) =>
        setSelected((current) =>
            current.includes(id)
                ? current.filter((item) => item !== id)
                : [...current, id],
        );

    const save = () => {
        setSaving(true);

        router.put(
            url,
            { entitlementIds: selected },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSaving(false);
                    onOpenChange(false);
                },
            },
        );
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent>
                <SheetHeader>
                    <SheetTitle>{title}</SheetTitle>
                    <SheetDescription>
                        Anyone subscribed gets these capabilities. Your app
                        checks the code — it never has to look at invoices.
                    </SheetDescription>
                </SheetHeader>

                <div className="space-y-2 overflow-y-auto px-4">
                    {entitlements.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No entitlements defined yet. Create one under
                            Catalog → Entitlements first.
                        </p>
                    ) : (
                        entitlements.map((entitlement) => (
                            <label
                                key={entitlement.id}
                                className="flex items-center gap-3 rounded-md border p-2 text-sm"
                            >
                                <Checkbox
                                    checked={selected.includes(entitlement.id)}
                                    onCheckedChange={() =>
                                        toggle(entitlement.id)
                                    }
                                    data-test={`grant-entitlement-${entitlement.code}`}
                                />
                                <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs">
                                    {entitlement.code}
                                </code>
                                <span className="text-muted-foreground">
                                    {entitlement.name}
                                </span>
                            </label>
                        ))
                    )}
                </div>

                <SheetFooter>
                    <Button
                        onClick={save}
                        disabled={saving || entitlements.length === 0}
                        data-test="save-entitlement-grants"
                    >
                        Save
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}

/**
 * The codes a grantor grants, shown inline on its row.
 */
export function GrantedBadges({
    entitlements,
    granted,
}: {
    entitlements: GrantableEntitlement[];
    granted: number[];
}) {
    const codes = entitlements
        .filter((entitlement) => granted.includes(entitlement.id))
        .map((entitlement) => entitlement.code);

    if (codes.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-1">
            {codes.map((code) => (
                <Badge key={code} variant="outline" className="font-mono">
                    {code}
                </Badge>
            ))}
        </div>
    );
}
