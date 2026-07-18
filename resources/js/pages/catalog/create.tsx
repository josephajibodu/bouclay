import { Form, Head } from '@inertiajs/react';
import { ChevronRight, Layers, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ProductMonogram } from '@/components/catalog/product-monogram';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn, formatPriceInterval, formatTierSummary } from '@/lib/utils';
import { index as productsIndex, store } from '@/routes/catalog/products';
import type { BillingInterval, PriceType, PricingModel } from '@/types';

type Props = {
    defaultCurrency: string;
};

type Tier = { upTo: string; unitAmount: string; flatAmount: string };

const TOGGLE_ITEM_CLASS =
    'flex-1 data-[state=on]:border-primary data-[state=on]:bg-primary data-[state=on]:text-primary-foreground';

export default function CreateProduct({ defaultCurrency }: Props) {
    const [name, setName] = useState('');
    const [planName, setPlanName] = useState('');
    const [type, setType] = useState<PriceType>('recurring');
    const [pricingModel, setPricingModel] = useState<PricingModel>('standard');
    const [advancedOpen, setAdvancedOpen] = useState(false);
    const [unitAmount, setUnitAmount] = useState('');
    const [billingInterval, setBillingInterval] =
        useState<BillingInterval>('month');
    const [billingFrequency, setBillingFrequency] = useState('1');
    const [tiers, setTiers] = useState<Tier[]>([
        { upTo: '', unitAmount: '', flatAmount: '' },
        { upTo: '', unitAmount: '', flatAmount: '' },
    ]);

    const productLabel = name.trim() || 'Product';
    const planLabel = planName.trim() || productLabel;
    const willCreatePlan = type === 'recurring';

    const hasPrice =
        pricingModel === 'graduated'
            ? tiers.some((t) => t.unitAmount.trim() !== '')
            : unitAmount.trim() !== '';

    const previewAmount = parseFloat(unitAmount);
    const canPreview =
        pricingModel === 'standard' &&
        unitAmount.trim() !== '' &&
        !Number.isNaN(previewAmount);

    const parsedTiers = tiers
        .filter((t) => t.unitAmount.trim() !== '' && !Number.isNaN(parseFloat(t.unitAmount)))
        .map((t) => ({
            upTo: t.upTo.trim() !== '' ? parseInt(t.upTo, 10) : null,
            unitAmount: parseFloat(t.unitAmount),
        }));
    const tierSummary =
        pricingModel === 'graduated' && parsedTiers.length > 0
            ? formatTierSummary(parsedTiers, defaultCurrency)
            : null;

    return (
        <div className="grid max-w-5xl grid-cols-1 gap-8 p-4 lg:grid-cols-[1fr_300px]">
            <Head title="Create product" />

            <Form
                {...store.form()}
                transform={(data) => ({
                    ...data,
                    price: hasPrice
                        ? {
                              type,
                              plan_name:
                                  type === 'recurring'
                                      ? planName.trim() || undefined
                                      : undefined,
                              pricing_model: pricingModel,
                              unit_amount:
                                  pricingModel === 'standard'
                                      ? Number(unitAmount)
                                      : undefined,
                              currency: defaultCurrency,
                              billing_interval:
                                  type === 'recurring'
                                      ? billingInterval
                                      : undefined,
                              billing_frequency: Number(billingFrequency) || 1,
                              tiers:
                                  pricingModel === 'graduated'
                                      ? tiers
                                            .filter(
                                                (t) =>
                                                    t.unitAmount.trim() !== '',
                                            )
                                            .map((t) => ({
                                                up_to: t.upTo
                                                    ? Number(t.upTo)
                                                    : null,
                                                unit_amount: Number(
                                                    t.unitAmount,
                                                ),
                                                flat_amount: t.flatAmount
                                                    ? Number(t.flatAmount)
                                                    : null,
                                            }))
                                      : undefined,
                          }
                        : null,
                })}
                className="flex flex-col gap-6"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="space-y-1">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Create a product
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                Products are what you sell. Give a recurring
                                price a plan — like{' '}
                                <span className="font-medium text-foreground">
                                    Cursor Pro
                                </span>{' '}
                                under{' '}
                                <span className="font-medium text-foreground">
                                    Cursor
                                </span>{' '}
                                — so you can add more tiers later.
                            </p>
                        </div>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-xs font-medium tracking-widest text-muted-foreground uppercase">
                                    Product
                                </CardTitle>
                                <CardDescription>
                                    The thing you sell. Customers see its name
                                    and description on checkout and invoices.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="product-name">
                                        Name{' '}
                                        <span className="text-destructive">
                                            *
                                        </span>
                                    </Label>
                                    <Input
                                        id="product-name"
                                        name="name"
                                        data-test="product-name"
                                        placeholder="Cursor"
                                        required
                                        value={name}
                                        onChange={(e) =>
                                            setName(e.target.value)
                                        }
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="product-description">
                                        Description
                                    </Label>
                                    <Textarea
                                        id="product-description"
                                        name="description"
                                        data-test="product-description"
                                        placeholder="AI-first code editor"
                                        rows={3}
                                    />
                                    <InputError
                                        message={errors.description}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="product-category">
                                        Category (optional)
                                    </Label>
                                    <Input
                                        id="product-category"
                                        name="category"
                                        data-test="product-category"
                                        placeholder="SaaS"
                                        className="max-w-xs"
                                    />
                                    <InputError message={errors.category} />
                                </div>
                            </CardContent>
                        </Card>

                        <Card
                            className={cn(
                                'transition-opacity',
                                !name.trim() && 'opacity-60',
                            )}
                        >
                            <CardHeader>
                                <div className="flex items-center justify-between gap-2">
                                    <CardTitle className="text-xs font-medium tracking-widest text-muted-foreground uppercase">
                                        Pricing
                                    </CardTitle>
                                    <div
                                        className="flex items-center gap-1.5 text-xs text-muted-foreground"
                                        data-test="hierarchy-breadcrumb"
                                    >
                                        <span className="max-w-24 truncate font-medium text-foreground">
                                            {productLabel}
                                        </span>
                                        {willCreatePlan && (
                                            <>
                                                <ChevronRight className="size-3" />
                                                <span className="max-w-24 truncate font-medium text-foreground">
                                                    {planLabel}
                                                </span>
                                            </>
                                        )}
                                    </div>
                                </div>
                                <CardDescription>
                                    {willCreatePlan
                                        ? 'A recurring price belongs to a plan, so you can add more plans — like Pro and Max — under this same product later.'
                                        : 'A one-time price is attached directly to the product.'}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid gap-2">
                                    <Label>How is this billed?</Label>
                                    <ToggleGroup
                                        type="single"
                                        variant="outline"
                                        value={type}
                                        onValueChange={(value) =>
                                            value &&
                                            setType(value as PriceType)
                                        }
                                        className="w-full max-w-xs"
                                    >
                                        <ToggleGroupItem
                                            value="one_time"
                                            className={TOGGLE_ITEM_CLASS}
                                        >
                                            One-time
                                        </ToggleGroupItem>
                                        <ToggleGroupItem
                                            value="recurring"
                                            className={TOGGLE_ITEM_CLASS}
                                            data-test="price-type-recurring"
                                        >
                                            Recurring
                                        </ToggleGroupItem>
                                    </ToggleGroup>
                                </div>

                                {willCreatePlan && (
                                    <div className="grid gap-2 rounded-lg border border-dashed p-3">
                                        <Label
                                            htmlFor="plan-name"
                                            className="flex items-center gap-1.5"
                                        >
                                            <Layers className="size-3.5 text-muted-foreground" />
                                            Plan name
                                        </Label>
                                        <Input
                                            id="plan-name"
                                            data-test="plan-name"
                                            placeholder={`${productLabel} Pro`}
                                            value={planName}
                                            onChange={(e) =>
                                                setPlanName(e.target.value)
                                            }
                                            className="max-w-xs"
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            This is the tier customers
                                            subscribe to. Leave blank to reuse
                                            the product name — you can add
                                            more plans (Max, Enterprise…)
                                            afterwards.
                                        </p>
                                        <InputError
                                            message={
                                                errors[
                                                    'price.plan_name' as never
                                                ]
                                            }
                                        />
                                    </div>
                                )}

                                {type === 'recurring' && (
                                    <div className="grid gap-2">
                                        <Label>Bills every</Label>
                                        <div className="flex items-center gap-2">
                                            <Input
                                                type="number"
                                                min={1}
                                                className="w-20"
                                                value={billingFrequency}
                                                onChange={(e) =>
                                                    setBillingFrequency(
                                                        e.target.value,
                                                    )
                                                }
                                                data-test="billing-frequency"
                                            />
                                            <Select
                                                value={billingInterval}
                                                onValueChange={(value) =>
                                                    setBillingInterval(
                                                        value as BillingInterval,
                                                    )
                                                }
                                            >
                                                <SelectTrigger
                                                    className="w-40"
                                                    data-test="billing-interval"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="day">
                                                        Day(s)
                                                    </SelectItem>
                                                    <SelectItem value="week">
                                                        Week(s)
                                                    </SelectItem>
                                                    <SelectItem value="month">
                                                        Month(s)
                                                    </SelectItem>
                                                    <SelectItem value="year">
                                                        Year(s)
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </div>
                                )}

                                {pricingModel === 'standard' && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="unit-amount">
                                            Amount
                                        </Label>
                                        <div className="flex max-w-xs items-center gap-2">
                                            <Badge
                                                variant="secondary"
                                                className="h-9 px-3 font-mono"
                                            >
                                                {defaultCurrency}
                                            </Badge>
                                            <Input
                                                id="unit-amount"
                                                type="number"
                                                min={0}
                                                step="0.01"
                                                placeholder="15000"
                                                value={unitAmount}
                                                onChange={(e) =>
                                                    setUnitAmount(
                                                        e.target.value,
                                                    )
                                                }
                                                data-test="unit-amount"
                                                className="font-mono tabular-nums"
                                            />
                                        </div>
                                        <InputError
                                            message={
                                                errors[
                                                    'price.unit_amount' as never
                                                ]
                                            }
                                        />
                                    </div>
                                )}

                                <Collapsible
                                    open={advancedOpen}
                                    onOpenChange={setAdvancedOpen}
                                >
                                    <CollapsibleTrigger asChild>
                                        <button
                                            type="button"
                                            className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                                            data-test="advanced-pricing-trigger"
                                        >
                                            <ChevronRight
                                                className={`size-4 transition-transform ${advancedOpen ? 'rotate-90' : ''}`}
                                            />
                                            Advanced pricing (graduated tiers)
                                        </button>
                                    </CollapsibleTrigger>
                                    <CollapsibleContent className="mt-3 space-y-3">
                                        <div className="grid gap-2">
                                            <Label>Pricing model</Label>
                                            <ToggleGroup
                                                type="single"
                                                variant="outline"
                                                value={pricingModel}
                                                onValueChange={(value) =>
                                                    value &&
                                                    setPricingModel(
                                                        value as PricingModel,
                                                    )
                                                }
                                                className="w-full max-w-sm"
                                            >
                                                <ToggleGroupItem
                                                    value="standard"
                                                    className={
                                                        TOGGLE_ITEM_CLASS
                                                    }
                                                    data-test="pricing-model-standard"
                                                >
                                                    Standard
                                                </ToggleGroupItem>
                                                <ToggleGroupItem
                                                    value="graduated"
                                                    className={
                                                        TOGGLE_ITEM_CLASS
                                                    }
                                                    data-test="pricing-model-graduated"
                                                >
                                                    Graduated by volume
                                                </ToggleGroupItem>
                                            </ToggleGroup>
                                        </div>

                                        {pricingModel === 'graduated' && (
                                            <div className="space-y-3">
                                                <p className="text-sm text-muted-foreground">
                                                    Different rates by volume
                                                    — the first units cost one
                                                    rate, the rest another.
                                                </p>
                                                {tiers.map((tier, index) => (
                                                    <div
                                                        key={index}
                                                        className="flex items-center gap-2"
                                                    >
                                                        <Input
                                                            placeholder={
                                                                index ===
                                                                tiers.length -
                                                                    1
                                                                    ? 'and up'
                                                                    : 'Up to (qty)'
                                                            }
                                                            type="number"
                                                            disabled={
                                                                index ===
                                                                tiers.length -
                                                                    1
                                                            }
                                                            value={tier.upTo}
                                                            data-test={`tier-up-to-${index}`}
                                                            onChange={(e) =>
                                                                setTiers(
                                                                    (prev) =>
                                                                        prev.map(
                                                                            (
                                                                                t,
                                                                                i,
                                                                            ) =>
                                                                                i ===
                                                                                index
                                                                                    ? {
                                                                                          ...t,
                                                                                          upTo: e
                                                                                              .target
                                                                                              .value,
                                                                                      }
                                                                                    : t,
                                                                        ),
                                                                )
                                                            }
                                                        />
                                                        <Input
                                                            placeholder="Price per unit"
                                                            type="number"
                                                            value={
                                                                tier.unitAmount
                                                            }
                                                            data-test={`tier-unit-amount-${index}`}
                                                            onChange={(e) =>
                                                                setTiers(
                                                                    (prev) =>
                                                                        prev.map(
                                                                            (
                                                                                t,
                                                                                i,
                                                                            ) =>
                                                                                i ===
                                                                                index
                                                                                    ? {
                                                                                          ...t,
                                                                                          unitAmount:
                                                                                              e
                                                                                                  .target
                                                                                                  .value,
                                                                                      }
                                                                                    : t,
                                                                        ),
                                                                )
                                                            }
                                                        />
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() =>
                                                                setTiers(
                                                                    (prev) =>
                                                                        prev.filter(
                                                                            (
                                                                                _,
                                                                                i,
                                                                            ) =>
                                                                                i !==
                                                                                index,
                                                                        ),
                                                                )
                                                            }
                                                            disabled={
                                                                tiers.length <=
                                                                2
                                                            }
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
                                                        setTiers((prev) => [
                                                            ...prev,
                                                            {
                                                                upTo: '',
                                                                unitAmount: '',
                                                                flatAmount: '',
                                                            },
                                                        ])
                                                    }
                                                >
                                                    <Plus /> Add tier
                                                </Button>

                                                {tierSummary && (
                                                    <div
                                                        className="rounded-lg border bg-muted/30 p-3 text-sm"
                                                        data-test="tier-preview"
                                                    >
                                                        <span className="text-muted-foreground">
                                                            Customers will see{' '}
                                                        </span>
                                                        <span className="font-medium">
                                                            {tierSummary}
                                                        </span>
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </CollapsibleContent>
                                </Collapsible>

                                {canPreview && (
                                    <div
                                        className="rounded-lg border bg-muted/30 p-3 text-sm"
                                        data-test="price-preview"
                                    >
                                        <span className="text-muted-foreground">
                                            Customers will see{' '}
                                        </span>
                                        <span className="font-medium">
                                            {formatPriceInterval(
                                                previewAmount,
                                                defaultCurrency,
                                                billingInterval,
                                                Number(billingFrequency) || 1,
                                            )}
                                        </span>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                disabled={processing}
                                data-test="create-product-submit"
                            >
                                {processing && <Spinner />}
                                Create product
                            </Button>
                        </div>
                    </>
                )}
            </Form>

            <aside className="hidden lg:block">
                <div className="sticky top-4 space-y-5 rounded-xl border p-4">
                    <div className="flex items-center gap-3">
                        <ProductMonogram
                            id={0}
                            name={name || 'New product'}
                        />
                        <p className="min-w-0 truncate font-medium">
                            {name || 'New product'}
                        </p>
                    </div>

                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-widest text-muted-foreground uppercase">
                            Structure
                        </p>
                        <div className="space-y-1.5 text-sm">
                            <div className="flex items-center gap-2">
                                <span className="flex size-5 shrink-0 items-center justify-center rounded-md border bg-muted/40 text-[10px] font-medium">
                                    P
                                </span>
                                <span className="truncate">
                                    {productLabel}
                                </span>
                            </div>
                            {willCreatePlan && (
                                <div className="ml-2.5 flex items-center gap-2 border-l pl-3.5">
                                    <span className="flex size-5 shrink-0 items-center justify-center rounded-md border bg-muted/40 text-[10px] font-medium">
                                        <Layers className="size-3" />
                                    </span>
                                    <span className="truncate text-muted-foreground">
                                        {planLabel}
                                    </span>
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="space-y-1 border-t pt-4 text-sm">
                        <p className="text-xs font-medium tracking-widest text-muted-foreground uppercase">
                            Pricing
                        </p>
                        {canPreview ? (
                            <p className="font-mono text-sm tabular-nums">
                                {formatPriceInterval(
                                    previewAmount,
                                    defaultCurrency,
                                    billingInterval,
                                    Number(billingFrequency) || 1,
                                )}
                            </p>
                        ) : hasPrice ? (
                            <p>{tierSummary ?? 'Graduated pricing'}</p>
                        ) : (
                            <p className="text-muted-foreground">
                                No price yet
                            </p>
                        )}
                    </div>

                    <p className="border-t pt-3 text-xs text-muted-foreground">
                        Not created yet
                    </p>
                </div>
            </aside>
        </div>
    );
}

CreateProduct.layout = () => ({
    breadcrumbs: [
        { title: 'Products', href: productsIndex() },
        { title: 'Create', href: '#' },
    ],
});
