import { Head, router } from '@inertiajs/react';
import { Plus, Tag, Trash2 } from 'lucide-react';
import { useState } from 'react';
import DiscountDrawer from '@/components/discounts/discount-drawer';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { destroy, index as discountsIndex } from '@/routes/discounts';
import type {
    DiscountEligibilityPlan,
    DiscountEligibilityPrice,
    DiscountSummary,
} from '@/types';

type Props = {
    discounts: DiscountSummary[];
    canManage: boolean;
    defaultCurrency: string;
    plans: DiscountEligibilityPlan[];
    prices: DiscountEligibilityPrice[];
};

function eligibilityLabel(discount: DiscountSummary): string {
    if (discount.eligiblePriceIds?.length) {
        return `${discount.eligiblePriceIds.length} price${discount.eligiblePriceIds.length === 1 ? '' : 's'}`;
    }

    if (discount.eligiblePlanIds?.length) {
        return `${discount.eligiblePlanIds.length} plan${discount.eligiblePlanIds.length === 1 ? '' : 's'}`;
    }

    return 'All plans';
}

export default function Discounts({
    discounts,
    canManage,
    defaultCurrency,
    plans,
    prices,
}: Props) {
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [editing, setEditing] = useState<DiscountSummary | null>(null);

    const openCreate = () => {
        setEditing(null);
        setDrawerOpen(true);
    };

    const openEdit = (discount: DiscountSummary) => {
        setEditing(discount);
        setDrawerOpen(true);
    };

    const remove = (discount: DiscountSummary) => {
        if (!confirm('Delete this discount? Redeemed discounts are deactivated instead.')) {
            return;
        }

        router.delete(destroy(discount.id).url, { preserveScroll: true });
    };

    return (
        <div className="flex max-w-5xl flex-col gap-6 p-4">
            <Head title="Discounts" />

            <div className="flex items-start justify-between gap-4">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold">Discounts</h1>
                    <p className="text-sm text-muted-foreground">
                        Percentage or flat reductions applied to eligible
                        subscriptions at redemption and renewal.
                    </p>
                </div>

                {canManage && discounts.length > 0 && (
                    <Button onClick={openCreate} data-test="create-discount-trigger">
                        <Plus /> New discount
                    </Button>
                )}
            </div>

            {discounts.length === 0 ? (
                <div className="flex flex-col items-center gap-4 rounded-lg border border-dashed p-10 text-center">
                    <Tag className="size-8 text-muted-foreground" />
                    <div className="space-y-1">
                        <p className="font-medium">No discounts yet</p>
                        <p className="text-sm text-muted-foreground">
                            Create a promo like 20% off for the first 3 months.
                        </p>
                    </div>
                    {canManage && (
                        <Button onClick={openCreate} data-test="create-discount-empty">
                            <Plus /> New discount
                        </Button>
                    )}
                </div>
            ) : (
                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead>Code</TableHead>
                                <TableHead>Discount</TableHead>
                                <TableHead>Applies to</TableHead>
                                <TableHead>Redemptions</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {discounts.map((discount) => (
                                <TableRow
                                    key={discount.id}
                                    className={canManage ? 'cursor-pointer' : ''}
                                    onClick={
                                        canManage
                                            ? () => openEdit(discount)
                                            : undefined
                                    }
                                    data-test="discount-row"
                                >
                                    <TableCell className="font-medium">
                                        {discount.code ?? '—'}
                                    </TableCell>
                                    <TableCell>{discount.summary}</TableCell>
                                    <TableCell>
                                        {eligibilityLabel(discount)}
                                    </TableCell>
                                    <TableCell>
                                        {discount.timesRedeemed}
                                        {discount.maxRedemptions
                                            ? ` / ${discount.maxRedemptions}`
                                            : ''}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                discount.active
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {discount.active
                                                ? 'Active'
                                                : 'Inactive'}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {canManage && (
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-8"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    remove(discount);
                                                }}
                                                aria-label="Delete discount"
                                            >
                                                <Trash2 />
                                            </Button>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            {canManage && (
                <DiscountDrawer
                    key={`${drawerOpen}-${editing?.id ?? 'new'}`}
                    open={drawerOpen}
                    onOpenChange={setDrawerOpen}
                    discount={editing}
                    plans={plans}
                    prices={prices}
                    defaultCurrency={defaultCurrency}
                />
            )}
        </div>
    );
}

Discounts.layout = () => ({
    breadcrumbs: [{ title: 'Discounts', href: discountsIndex() }],
});
