import { Head, Link, router } from '@inertiajs/react';
import {
    Check,
    ChevronLeft,
    Copy,
    CreditCard,
    MapPin,
    MoreHorizontal,
    Pencil,
    Plus,
    Receipt,
    RefreshCw,
    Trash2,
    ExternalLink,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import AddressDrawer from '@/components/customers/address-drawer';
import ChargeCustomerModal from '@/components/customers/charge-customer-modal';
import { CustomerMonogram } from '@/components/customers/customer-monogram';
import EditCustomerDrawer from '@/components/customers/edit-customer-drawer';
import { StagedSection } from '@/components/customers/staged-section';
import CreateSubscriptionDrawer from '@/components/subscriptions/create-subscription-drawer';
import { SubscriptionStatusBadge } from '@/components/subscriptions/subscription-status-badge';
import {
    INVOICE_STATUS_COLOR,
    INVOICE_STATUS_LABEL,
} from '@/components/invoices/invoice-status';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    archive,
    index as customersIndex,
    restore,
} from '@/routes/customers';
import {
    destroy as destroyAddress,
    defaultMethod as makeAddressDefault,
} from '@/routes/customers/addresses';
import {
    defaultMethod as makePmDefault,
    destroy as destroyPm,
} from '@/routes/customers/payment-methods';
import { show as invoiceShow } from '@/routes/invoices';
import { show as subscriptionShow } from '@/routes/subscriptions';
import type {
    CreateCustomerOption,
    CreateProductOption,
    CreateTrialOfferOption,
    CustomerAddress,
    CustomerActivityEvent,
    CustomerDetail,
    CustomerPaymentMethod,
    SubscriptionStatus,
    InvoiceSummary,
    PaymentListItem,
} from '@/types';

type CustomerSubscription = {
    id: number;
    publicId: string;
    status: SubscriptionStatus;
    planLabel: string;
    trialEndsAt: string | null;
    currentPeriodEnd: string | null;
    endsAt: string | null;
};

type Props = {
    customer: CustomerDetail;
    addresses: CustomerAddress[];
    paymentMethods: CustomerPaymentMethod[];
    defaultAddress: CustomerAddress | null;
    subscriptions: CustomerSubscription[];
    activeSubscriptionCount: number;
    invoices: (InvoiceSummary & { productsLabel: string })[];
    totalSpend: number;
    activity: CustomerActivityEvent[];
    teamCurrency: string;
    customerOption: CreateCustomerOption;
    products: CreateProductOption[];
    trialOffers: CreateTrialOfferOption[];
    permissions: { canManage: boolean; canManageSubscriptions: boolean };
    portalUrl: string;
};

function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function formatDateTime(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function expiryLabel(pm: CustomerPaymentMethod): string {
    if (!pm.expMonth || !pm.expYear) {
        return 'Expiry unknown';
    }

    const mm = String(pm.expMonth).padStart(2, '0');
    const yy = String(pm.expYear).slice(-2);

    return `${pm.isExpired ? 'Expired' : 'Expires'} ${mm}/${yy}`;
}

/** Invoice amounts and spend come from the server in minor units (kobo). */
function formatMinor(amountMinor: number, currency: string): string {
    return `${currency} ${(amountMinor / 100).toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
}

export default function CustomerShow({
    customer,
    addresses,
    paymentMethods,
    defaultAddress,
    subscriptions,
    activeSubscriptionCount,
    invoices,
    totalSpend,
    activity,
    teamCurrency,
    customerOption,
    products,
    trialOffers,
    permissions,
    portalUrl,
}: Props) {
    const { canManage, canManageSubscriptions } = permissions;
    const isArchived = customer.status === 'archived';

    const [copied, setCopied] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [chargeOpen, setChargeOpen] = useState(false);
    const [subscribeOpen, setSubscribeOpen] = useState(false);
    const [addressOpen, setAddressOpen] = useState(false);
    const [addressTarget, setAddressTarget] = useState<CustomerAddress | null>(
        null,
    );

    const defaultPm = paymentMethods.find((pm) => pm.isDefault) ?? null;
    const metadata = Object.entries(customer.customData ?? {});
    const currency = customer.currency ?? teamCurrency;

    const copyId = async () => {
        await navigator.clipboard.writeText(customer.publicId);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 2000);
    };

    const copyValue = async (value: string, label: string) => {
        await navigator.clipboard.writeText(value);
        toast.success(`${label} copied`);
    };

    const openAddAddress = () => {
        setAddressTarget(null);
        setAddressOpen(true);
    };

    const openEditAddress = (address: CustomerAddress) => {
        setAddressTarget(address);
        setAddressOpen(true);
    };

    const confirmArchive = () => {
        if (
            window.confirm(
                "Archive this customer? They'll stop appearing in your active list and can't be subscribed to new plans. You can restore them anytime.",
            )
        ) {
            router.delete(archive(customer.id).url);
        }
    };

    const removeAddress = (address: CustomerAddress) => {
        if (window.confirm('Remove this address?')) {
            router.delete(
                destroyAddress({ customer: customer.id, address: address.id })
                    .url,
                { preserveScroll: true },
            );
        }
    };

    const removePaymentMethod = (pm: CustomerPaymentMethod) => {
        const last =
            paymentMethods.length === 1
                ? 'This is the only way to charge this customer. Remove it anyway?'
                : 'Remove this payment method?';

        if (window.confirm(last)) {
            router.delete(
                destroyPm({ customer: customer.id, payment_method: pm.id }).url,
                { preserveScroll: true },
            );
        }
    };

    return (
        <div className="flex max-w-4xl flex-col gap-8 p-4 pb-24">
            <Head title={customer.name ?? customer.email} />

            <Link
                href={customersIndex()}
                className="flex w-fit items-center gap-1 text-sm text-muted-foreground transition-colors hover:text-foreground"
            >
                <ChevronLeft className="size-4" /> Customers
            </Link>

            {/* Header */}
            <div className="flex flex-col gap-4">
                <div className="flex items-start justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <CustomerMonogram
                            id={customer.id}
                            name={customer.name}
                            email={customer.email}
                            className="size-12 text-base"
                        />
                        <div className="space-y-1">
                            <div className="flex items-center gap-2">
                                <h1 className="text-2xl font-semibold">
                                    {customer.name ?? customer.email}
                                </h1>
                                <StatusBadge status={customer.status} />
                            </div>
                            <button
                                type="button"
                                onClick={() =>
                                    copyValue(customer.email, 'Email')
                                }
                                className="text-sm text-muted-foreground transition-colors hover:text-foreground"
                            >
                                {customer.email}
                            </button>
                        </div>
                    </div>

                    <ActionsMenu
                        canManage={canManage}
                        canManageSubscriptions={canManageSubscriptions}
                        isArchived={isArchived}
                        portalUrl={portalUrl}
                        onEdit={() => setEditOpen(true)}
                        onCopyId={copyId}
                        onAddAddress={openAddAddress}
                        onCharge={() => setChargeOpen(true)}
                        onCreateSubscription={() => setSubscribeOpen(true)}
                        onArchive={confirmArchive}
                        onRestore={() =>
                            router.post(restore(customer.id).url)
                        }
                    />
                </div>

                <button
                    type="button"
                    onClick={copyId}
                    className="flex w-fit items-center gap-1.5 text-xs text-muted-foreground transition-colors hover:text-foreground"
                >
                    Customer since {formatDate(customer.createdAt)} ·{' '}
                    {customer.publicId}
                    {copied ? (
                        <Check className="size-3.5 text-emerald-500" />
                    ) : (
                        <Copy className="size-3.5" />
                    )}
                </button>
            </div>

            {isArchived && (
                <div className="flex items-center justify-between gap-4 rounded-lg border bg-muted/40 px-4 py-3">
                    <p className="text-sm text-muted-foreground">
                        This customer is archived. Restore them to add payment
                        methods or subscribe them to plans.
                    </p>
                    {canManage && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(restore(customer.id).url)
                            }
                        >
                            Restore
                        </Button>
                    )}
                </div>
            )}

            {/* Overview */}
            <section className="space-y-3">
                <h2 className="text-lg font-semibold">Overview</h2>
                <div className="grid grid-cols-2 gap-x-6 gap-y-4 rounded-lg border p-4 sm:grid-cols-3">
                    <Fact label="Email" value={customer.email} />
                    <Fact
                        label="Default payment method"
                        value={
                            defaultPm
                                ? `${defaultPm.brand ?? 'Card'} ···· ${defaultPm.last4 ?? ''}`
                                : 'None yet'
                        }
                    />
                    <Fact
                        label="Billing address"
                        value={
                            defaultAddress
                                ? [defaultAddress.city, defaultAddress.country]
                                      .filter(Boolean)
                                      .join(', ')
                                : 'No address'
                        }
                    />
                    <Fact label="Currency" value={currency} />
                    <Fact
                        label="Total spend"
                        value={
                            totalSpend > 0
                                ? formatMinor(totalSpend, currency)
                                : 'Nothing yet'
                        }
                    />
                    <Fact
                        label="Active subscriptions"
                        value={
                            activeSubscriptionCount > 0
                                ? String(activeSubscriptionCount)
                                : 'None'
                        }
                    />
                    <Fact
                        label="Customer since"
                        value={formatDate(customer.createdAt)}
                    />
                </div>
            </section>

            {/* Payment methods — read-only */}
            <section className="space-y-3">
                <h2 className="text-lg font-semibold">Payment methods</h2>
                {paymentMethods.length === 0 ? (
                    <div className="space-y-2 rounded-lg border border-dashed p-6 text-center">
                        <div className="mx-auto flex size-10 items-center justify-center rounded-full bg-muted">
                            <CreditCard className="size-5 text-muted-foreground" />
                        </div>
                        <p className="font-medium">No card on file yet</p>
                        <p className="mx-auto max-w-md text-sm text-muted-foreground">
                            A card is saved automatically the first time this
                            customer pays a checkout — you never enter card
                            details here. Bouclay stores only a secure token, the
                            brand, and the last four digits.
                        </p>
                        <p className="text-xs text-muted-foreground">
                            🔒 Secured &amp; tokenized by Nomba
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="divide-y rounded-lg border">
                            {paymentMethods.map((pm) => (
                                <div
                                    key={pm.id}
                                    className="flex items-center justify-between gap-3 p-4"
                                >
                                    <div className="flex items-center gap-3">
                                        <CreditCard
                                            className={
                                                pm.isExpired
                                                    ? 'size-5 text-destructive'
                                                    : 'size-5 text-muted-foreground'
                                            }
                                        />
                                        <div>
                                            <p className="font-medium">
                                                {pm.brand ?? 'Card'} ····{' '}
                                                {pm.last4 ?? '••••'}
                                            </p>
                                            <p
                                                className={
                                                    pm.isExpired
                                                        ? 'text-xs text-destructive'
                                                        : 'text-xs text-muted-foreground'
                                                }
                                            >
                                                {expiryLabel(pm)}
                                            </p>
                                        </div>
                                        {pm.isDefault && (
                                            <Badge variant="secondary">
                                                Default
                                            </Badge>
                                        )}
                                    </div>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-8"
                                            >
                                                <MoreHorizontal />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            {canManage &&
                                                !pm.isDefault &&
                                                !pm.isExpired && (
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            router.post(
                                                                makePmDefault({
                                                                    customer:
                                                                        customer.id,
                                                                    payment_method:
                                                                        pm.id,
                                                                }).url,
                                                                {
                                                                    preserveScroll:
                                                                        true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <Check /> Set as default
                                                    </DropdownMenuItem>
                                                )}
                                            <DropdownMenuItem
                                                onClick={() =>
                                                    copyValue(
                                                        pm.publicId,
                                                        'Payment method ID',
                                                    )
                                                }
                                            >
                                                <Copy /> Copy ID
                                            </DropdownMenuItem>
                                            {canManage && (
                                                <>
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        onClick={() =>
                                                            removePaymentMethod(
                                                                pm,
                                                            )
                                                        }
                                                    >
                                                        <Trash2 /> Remove
                                                    </DropdownMenuItem>
                                                </>
                                            )}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            ))}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Cards are saved when the customer pays a checkout. To
                            add another, charge them again.
                        </p>
                    </>
                )}
            </section>

            {/* Subscriptions */}
            {subscriptions.length === 0 ? (
                <StagedSection
                    title="Subscriptions"
                    icon={RefreshCw}
                    heading="Subscriptions will live here"
                    body="When you subscribe this customer to a plan, their active and past subscriptions — status, renewal date, and plan — will show up here."
                    action={
                        canManageSubscriptions && !isArchived ? (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setSubscribeOpen(true)}
                            >
                                <Plus /> New subscription
                            </Button>
                        ) : undefined
                    }
                />
            ) : (
                <section className="space-y-3">
                    <div className="flex items-center justify-between">
                        <h2 className="text-lg font-semibold">Subscriptions</h2>
                        {canManageSubscriptions && !isArchived && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setSubscribeOpen(true)}
                            >
                                <Plus /> New subscription
                            </Button>
                        )}
                    </div>
                    <div className="divide-y rounded-lg border">
                        {subscriptions.map((sub) => (
                            <Link
                                key={sub.id}
                                href={subscriptionShow(sub.id)}
                                className="flex items-center justify-between gap-3 p-4 transition-colors hover:bg-muted/50"
                            >
                                <div className="flex items-center gap-3">
                                    <RefreshCw className="size-5 text-muted-foreground" />
                                    <span className="font-medium">
                                        {sub.planLabel}
                                    </span>
                                    <SubscriptionStatusBadge
                                        status={sub.status}
                                    />
                                </div>
                                <span className="text-sm text-muted-foreground">
                                    {sub.status === 'trialing' && sub.trialEndsAt
                                        ? `Trial ends ${formatDate(sub.trialEndsAt)}`
                                        : sub.endsAt
                                          ? `Ends ${formatDate(sub.endsAt)}`
                                          : sub.status === 'canceled'
                                            ? 'Ended'
                                            : formatDate(sub.currentPeriodEnd)}
                                </span>
                            </Link>
                        ))}
                    </div>
                </section>
            )}

            {/* Invoices */}
            <section className="space-y-3">
                <h2 className="text-lg font-semibold">Invoices</h2>
                {invoices.length === 0 ? (
                    <div className="space-y-2 rounded-lg border border-dashed p-6 text-center">
                        <div className="mx-auto flex size-10 items-center justify-center rounded-full bg-muted">
                            <Receipt className="size-5 text-muted-foreground" />
                        </div>
                        <p className="font-medium">No invoices yet</p>
                        <p className="mx-auto max-w-md text-sm text-muted-foreground">
                            Every invoice billed to this customer — paid or
                            still awaiting payment — will be listed here.
                        </p>
                    </div>
                ) : (
                    <div className="divide-y rounded-lg border">
                        {invoices.map((invoice) => (
                            <div
                                key={invoice.id}
                                role="link"
                                tabIndex={0}
                                className="flex cursor-pointer items-center justify-between gap-3 p-4 transition-colors hover:bg-muted/40"
                                onClick={() =>
                                    router.visit(invoiceShow(invoice.id))
                                }
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' || e.key === ' ') {
                                        router.visit(invoiceShow(invoice.id));
                                    }
                                }}
                                data-test="customer-invoice-row"
                            >
                                <div className="flex items-center gap-3">
                                    <Receipt className="size-5 text-muted-foreground" />
                                    <div>
                                        <p className="font-medium">
                                            {invoice.number ?? invoice.publicId}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {invoice.productsLabel} ·{' '}
                                            {formatDate(invoice.createdAt)}
                                        </p>
                                    </div>
                                </div>
                                <div className="flex items-center gap-3">
                                    <span className="text-sm font-medium">
                                        {formatMinor(
                                            invoice.total,
                                            invoice.currency,
                                        )}
                                    </span>
                                    <Badge variant="secondary" className="gap-1">
                                        <span
                                            className={`size-1.5 rounded-full ${INVOICE_STATUS_COLOR[invoice.status]}`}
                                        />
                                        {INVOICE_STATUS_LABEL[invoice.status]}
                                    </Badge>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </section>

            {/* Addresses */}
            <section className="space-y-3">
                <div className="flex items-center justify-between">
                    <h2 className="text-lg font-semibold">Addresses</h2>
                    {canManage && !isArchived && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={openAddAddress}
                            data-test="add-address"
                        >
                            <Plus /> Add address
                        </Button>
                    )}
                </div>

                {addresses.length === 0 ? (
                    <div className="space-y-2 rounded-lg border border-dashed p-6 text-center">
                        <div className="mx-auto flex size-10 items-center justify-center rounded-full bg-muted">
                            <MapPin className="size-5 text-muted-foreground" />
                        </div>
                        <p className="font-medium">No address on file</p>
                        <p className="mx-auto max-w-md text-sm text-muted-foreground">
                            Add a billing address to appear on this customer's
                            invoices and receipts. It's optional — you can
                            subscribe them without one.
                        </p>
                    </div>
                ) : (
                    <div className="divide-y rounded-lg border">
                        {addresses.map((address) => (
                            <div
                                key={address.id}
                                className="flex items-center justify-between gap-3 p-4"
                            >
                                <div className="flex items-center gap-3">
                                    <MapPin className="size-5 text-muted-foreground" />
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <Badge
                                                variant="outline"
                                                className="capitalize"
                                            >
                                                {address.type}
                                            </Badge>
                                            {address.isDefault && (
                                                <Badge variant="secondary">
                                                    Default
                                                </Badge>
                                            )}
                                        </div>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {address.singleLine}
                                        </p>
                                    </div>
                                </div>
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-8"
                                        >
                                            <MoreHorizontal />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        {canManage && (
                                            <DropdownMenuItem
                                                onClick={() =>
                                                    openEditAddress(address)
                                                }
                                            >
                                                <Pencil /> Edit
                                            </DropdownMenuItem>
                                        )}
                                        {canManage && !address.isDefault && (
                                            <DropdownMenuItem
                                                onClick={() =>
                                                    router.post(
                                                        makeAddressDefault({
                                                            customer:
                                                                customer.id,
                                                            address: address.id,
                                                        }).url,
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                <Check /> Set as default
                                            </DropdownMenuItem>
                                        )}
                                        <DropdownMenuItem
                                            onClick={() =>
                                                copyValue(
                                                    address.singleLine,
                                                    'Address',
                                                )
                                            }
                                        >
                                            <Copy /> Copy
                                        </DropdownMenuItem>
                                        {canManage && (
                                            <>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    onClick={() =>
                                                        removeAddress(address)
                                                    }
                                                >
                                                    <Trash2 /> Remove
                                                </DropdownMenuItem>
                                            </>
                                        )}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        ))}
                    </div>
                )}
            </section>

            {/* Activity */}
            <section className="space-y-3">
                <h2 className="text-lg font-semibold">Activity</h2>
                <div className="rounded-lg border p-4">
                    <ol className="space-y-4">
                        {activity.map((event, i) => (
                            <li
                                key={`${event.type}-${i}`}
                                className="flex items-center gap-3 text-sm"
                            >
                                <span className="size-2 shrink-0 rounded-full bg-muted-foreground/40" />
                                <span className="flex-1">{event.label}</span>
                                <span
                                    className="text-xs text-muted-foreground"
                                    title={event.at ?? ''}
                                >
                                    {formatDateTime(event.at)}
                                </span>
                            </li>
                        ))}
                    </ol>
                </div>
            </section>

            {/* Metadata */}
            {metadata.length > 0 && (
                <section className="space-y-3">
                    <h2 className="text-lg font-semibold">Metadata</h2>
                    <div className="divide-y rounded-lg border">
                        {metadata.map(([key, value]) => (
                            <div
                                key={key}
                                className="flex items-center justify-between gap-4 px-4 py-2 text-sm"
                            >
                                <span className="font-mono text-muted-foreground">
                                    {key}
                                </span>
                                <span className="font-mono">{value}</span>
                            </div>
                        ))}
                    </div>
                </section>
            )}

            {/* Developer */}
            <section className="space-y-3">
                <h2 className="text-lg font-semibold">Developer</h2>
                <div className="space-y-2 rounded-lg border p-4 text-sm">
                    <DevRow
                        label="Customer ID"
                        value={customer.publicId}
                        onCopy={() => copyValue(customer.publicId, 'Customer ID')}
                    />
                    {customer.externalRef && (
                        <DevRow
                            label="Your reference"
                            value={customer.externalRef}
                            onCopy={() =>
                                copyValue(customer.externalRef!, 'Reference')
                            }
                        />
                    )}
                    <div className="flex items-center justify-between gap-4">
                        <span className="text-muted-foreground">Created</span>
                        <span>{formatDateTime(customer.createdAt)}</span>
                    </div>
                </div>
            </section>

            <EditCustomerDrawer
                customer={customer}
                teamCurrency={teamCurrency}
                open={editOpen}
                onOpenChange={setEditOpen}
            />

            <AddressDrawer
                customer={customer}
                address={addressTarget}
                open={addressOpen}
                onOpenChange={setAddressOpen}
            />

            <ChargeCustomerModal
                customer={customer}
                currency={currency}
                open={chargeOpen}
                onOpenChange={setChargeOpen}
            />

            <CreateSubscriptionDrawer
                customers={[customerOption]}
                products={products}
                trialOffers={trialOffers}
                teamCurrency={teamCurrency}
                open={subscribeOpen}
                onOpenChange={setSubscribeOpen}
                fixedCustomerId={customer.id}
            />
        </div>
    );
}

function StatusBadge({ status }: { status: 'active' | 'archived' }) {
    return status === 'active' ? (
        <Badge variant="secondary" className="gap-1">
            <span className="size-1.5 rounded-full bg-emerald-500" /> Active
        </Badge>
    ) : (
        <Badge variant="outline">Archived</Badge>
    );
}

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div className="space-y-0.5">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="truncate text-sm" title={value}>
                {value}
            </p>
        </div>
    );
}

function DevRow({
    label,
    value,
    onCopy,
}: {
    label: string;
    value: string;
    onCopy: () => void;
}) {
    return (
        <div className="flex items-center justify-between gap-4">
            <span className="text-muted-foreground">{label}</span>
            <button
                type="button"
                onClick={onCopy}
                className="flex items-center gap-1.5 font-mono transition-colors hover:text-foreground"
            >
                {value}
                <Copy className="size-3.5 text-muted-foreground" />
            </button>
        </div>
    );
}

function ActionsMenu({
    canManage,
    canManageSubscriptions,
    isArchived,
    portalUrl,
    onEdit,
    onCopyId,
    onAddAddress,
    onCharge,
    onCreateSubscription,
    onArchive,
    onRestore,
}: {
    canManage: boolean;
    canManageSubscriptions: boolean;
    isArchived: boolean;
    portalUrl: string;
    onEdit: () => void;
    onCopyId: () => void;
    onAddAddress: () => void;
    onCharge: () => void;
    onCreateSubscription: () => void;
    onArchive: () => void;
    onRestore: () => void;
}) {
    const copyPortalLink = async () => {
        await navigator.clipboard.writeText(portalUrl);
        toast.success('Portal link copied');
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" data-test="customer-actions">
                    Actions
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
                {canManage && !isArchived && (
                    <DropdownMenuItem onClick={onEdit}>
                        <Pencil /> Edit customer
                    </DropdownMenuItem>
                )}
                <DropdownMenuItem onClick={onCopyId}>
                    <Copy /> Copy customer ID
                </DropdownMenuItem>
                {!isArchived && (
                    <DropdownMenuItem
                        onClick={copyPortalLink}
                        data-test="copy-portal-link"
                    >
                        <ExternalLink /> Copy portal link
                    </DropdownMenuItem>
                )}

                {canManage && !isArchived && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onClick={onAddAddress}>
                            <Plus /> Add address
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={onCharge}>
                            <CreditCard /> Charge customer
                        </DropdownMenuItem>
                    </>
                )}

                {canManageSubscriptions && !isArchived && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onClick={onCreateSubscription}>
                            <RefreshCw /> Create subscription
                        </DropdownMenuItem>
                    </>
                )}

                {canManage && (
                    <>
                        <DropdownMenuSeparator />
                        {isArchived ? (
                            <DropdownMenuItem onClick={onRestore}>
                                <RefreshCw /> Restore customer
                            </DropdownMenuItem>
                        ) : (
                            <DropdownMenuItem
                                variant="destructive"
                                onClick={onArchive}
                            >
                                <Trash2 /> Archive customer
                            </DropdownMenuItem>
                        )}
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

CustomerShow.layout = () => ({
    breadcrumbs: [{ title: 'Customers', href: customersIndex() }],
});
