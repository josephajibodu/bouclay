import { Check, Copy, Tag } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useClipboard } from '@/hooks/use-clipboard';
import {
    formatMoney,
    formatPriceInterval,
    formatTierSummary,
} from '@/lib/utils';
import type { Price } from '@/types';

type Props = PropsWithChildren<{
    price: Price;
    productName: string;
    canManagePrices: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onEdit: () => void;
    onArchive: () => void;
}>;

const taxModeLabel: Record<Price['taxMode'], string> = {
    inclusive: 'Inclusive',
    exclusive: 'Exclusive',
    account: 'Account default',
};

export default function PriceDetailDrawer({
    price,
    productName,
    canManagePrices,
    open,
    onOpenChange,
    onEdit,
    onArchive,
}: Props) {
    const [copiedText, copy] = useClipboard();

    // Trials are a property of the price itself in the V2 catalog.
    const trialSummary =
        price.trialLength !== null
            ? `${price.trialLength}-${price.trialUnit} trial${price.trialRequiresPaymentInfo ? ' (card required)' : ''}`
            : 'No trial';

    const unitPriceSummary =
        price.pricingModel === 'graduated'
            ? 'Graduated by volume'
            : price.unitAmount !== null && price.billingInterval
              ? formatPriceInterval(
                    price.unitAmount,
                    price.currency,
                    price.billingInterval,
                    price.billingFrequency,
                )
              : price.unitAmount !== null
                ? formatMoney(price.unitAmount, price.currency)
                : '—';

    const metadataEntries = Object.entries(price.customData ?? {});

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="h-auto w-full overflow-y-auto rounded-xl border sm:inset-y-4 sm:right-4 sm:w-3/4 sm:max-w-xl">
                <SheetHeader className="gap-1">
                    <div className="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        <Tag className="size-3.5" /> Price
                    </div>
                    <div className="flex items-center justify-between gap-2">
                        <SheetTitle className="text-xl">
                            Price for {productName}
                        </SheetTitle>
                        <Badge
                            variant={
                                price.status === 'active'
                                    ? 'secondary'
                                    : 'outline'
                            }
                            className="capitalize"
                        >
                            {price.status}
                        </Badge>
                    </div>
                    <button
                        type="button"
                        onClick={() => copy(price.publicId)}
                        className="flex w-fit items-center gap-1.5 font-mono text-sm text-muted-foreground hover:text-foreground"
                        data-test="copy-price-id"
                    >
                        {price.publicId}
                        {copiedText === price.publicId ? (
                            <Check className="size-3.5" />
                        ) : (
                            <Copy className="size-3.5" />
                        )}
                    </button>
                </SheetHeader>

                <div className="flex flex-col gap-6 px-4">
                    <div className="grid grid-cols-2 gap-4 rounded-lg border p-4 sm:grid-cols-4">
                        <div>
                            <p className="text-xs text-muted-foreground">
                                Product
                            </p>
                            <p className="text-sm font-medium">{productName}</p>
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground">
                                Unit price
                            </p>
                            <p className="text-sm font-medium">
                                {unitPriceSummary}
                            </p>
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground">
                                Trial
                            </p>
                            <p className="text-sm font-medium">
                                {trialSummary}
                            </p>
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground">
                                Subscriptions
                            </p>
                            <p className="text-sm font-medium">
                                0 active{' '}
                                <span className="text-xs font-normal text-muted-foreground">
                                    (coming soon)
                                </span>
                            </p>
                        </div>
                    </div>

                    <section className="space-y-3">
                        <h3 className="text-sm font-semibold">Pricing</h3>
                        <div className="divide-y rounded-lg border text-sm">
                            <Row
                                label="Type"
                                value={
                                    price.pricingModel === 'graduated'
                                        ? 'Graduated by volume'
                                        : 'Flat rate'
                                }
                            />
                            <Row label="Currency" value={price.currency} />
                            <Row
                                label="Interval"
                                value={
                                    price.billingInterval
                                        ? `Every ${price.billingFrequency > 1 ? `${price.billingFrequency} ` : ''}${price.billingInterval}${price.billingFrequency > 1 ? 's' : ''}`
                                        : 'One time'
                                }
                            />
                            {price.pricingModel === 'graduated' ? (
                                <Row
                                    label="Tiers"
                                    value={formatTierSummary(
                                        price.tiers.map((t) => ({
                                            upTo: t.upTo,
                                            unitAmount: t.unitAmount,
                                        })),
                                        price.currency,
                                    )}
                                />
                            ) : (
                                <Row
                                    label="Price per unit"
                                    value={
                                        price.unitAmount !== null
                                            ? formatMoney(
                                                  price.unitAmount,
                                                  price.currency,
                                              )
                                            : '—'
                                    }
                                />
                            )}
                            <Row
                                label="Tax behavior"
                                value={taxModeLabel[price.taxMode]}
                            />
                        </div>
                    </section>

                    <section className="space-y-3">
                        <h3 className="text-sm font-semibold">Metadata</h3>
                        {metadataEntries.length === 0 ? (
                            <div className="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">
                                No metadata on this price.
                            </div>
                        ) : (
                            <div className="divide-y rounded-lg border text-sm">
                                {metadataEntries.map(([key, value]) => (
                                    <div
                                        key={key}
                                        className="flex items-center justify-between gap-4 p-3"
                                    >
                                        <span className="font-mono text-muted-foreground">
                                            {key}
                                        </span>
                                        <span className="truncate font-medium">
                                            {value}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </section>

                    {canManagePrices && (
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={onEdit}
                            >
                                Edit price
                            </Button>
                            {price.status === 'active' && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="text-destructive hover:text-destructive"
                                    onClick={onArchive}
                                >
                                    Archive price
                                </Button>
                            )}
                        </div>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-center justify-between gap-4 p-3">
            <span className="text-muted-foreground">{label}</span>
            <span className="font-medium">{value}</span>
        </div>
    );
}
