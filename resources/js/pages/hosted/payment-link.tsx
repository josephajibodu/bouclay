import { Form, Head } from '@inertiajs/react';
import {
    AlertCircle,
    Building2,
    CreditCard,
    Lock,
    Mail,
    ReceiptText,
    ShieldCheck,
} from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { checkout } from '@/routes/hosted/payment-links';

type HostedPaymentLink = {
    publicId: string;
    business: {
        name: string;
        line1: string | null;
        line2: string | null;
        city: string | null;
        postalCode: string | null;
        country: string | null;
    };
    product: {
        name: string;
        description: string | null;
    };
    price: {
        name: string | null;
        type: 'recurring' | 'one_time';
        currency: string;
        unitAmount: number;
        billingInterval: 'day' | 'week' | 'month' | 'year' | null;
        billingFrequency: number;
        label: string;
    };
};

type Props = {
    paymentLink: HostedPaymentLink;
    prefill: {
        email: string;
        name: string;
    };
    checkoutError: string | null;
};

function money(amount: number, currency: string): string {
    return `${currency} ${(amount / 100).toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
}

function period(price: HostedPaymentLink['price']): string {
    if (price.type === 'one_time' || !price.billingInterval) {
        return '';
    }

    if (price.billingFrequency === 1) {
        return price.billingInterval;
    }

    return `${price.billingFrequency} ${price.billingInterval}s`;
}

function priceCaption(price: HostedPaymentLink['price']): string {
    if (price.type === 'one_time') {
        return 'One-time payment';
    }

    return `Billed every ${period(price)} until canceled`;
}

function dueTodayLabel(price: HostedPaymentLink['price']): string {
    if (price.type === 'one_time') {
        return 'Total due today';
    }

    return 'Due today';
}

function businessAddress(
    business: HostedPaymentLink['business'],
): string | null {
    const parts = [
        business.line1,
        business.line2,
        business.city,
        business.postalCode,
        business.country,
    ].filter(Boolean);

    return parts.length > 0 ? parts.join(', ') : null;
}

export default function HostedPaymentLink({
    paymentLink,
    prefill,
    checkoutError,
}: Props) {
    const recurringPeriod = period(paymentLink.price);
    const priceAmount = money(
        paymentLink.price.unitAmount,
        paymentLink.price.currency,
    );

    return (
        <div className="min-h-screen bg-background">
            <Head title={`Checkout · ${paymentLink.product.name}`} />

            <div className="mx-auto grid min-h-screen max-w-6xl lg:grid-cols-[1fr_440px]">
                <section className="flex flex-col gap-10 px-6 py-8 sm:px-10 lg:px-12 lg:py-12">
                    <div className="flex items-center gap-3">
                        <div className="flex size-9 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
                            {paymentLink.business.name.slice(0, 1)}
                        </div>
                        <div>
                            <p className="font-medium">
                                {paymentLink.business.name}
                            </p>
                            {businessAddress(paymentLink.business) && (
                                <p className="text-xs text-muted-foreground">
                                    {businessAddress(paymentLink.business)}
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="max-w-xl space-y-8">
                        <div className="space-y-3">
                            <p className="text-sm text-muted-foreground">
                                {paymentLink.price.type === 'recurring'
                                    ? 'Subscribe to'
                                    : 'Pay for'}
                            </p>
                            <div>
                                <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">
                                    {paymentLink.product.name}
                                </h1>
                                {paymentLink.product.description && (
                                    <p className="mt-3 max-w-lg text-sm leading-6 text-muted-foreground">
                                        {paymentLink.product.description}
                                    </p>
                                )}
                            </div>
                            <div className="flex items-end gap-2">
                                <span className="text-4xl font-semibold tracking-tight">
                                    {priceAmount}
                                </span>
                                {recurringPeriod && (
                                    <span className="pb-1 text-sm text-muted-foreground">
                                        per {recurringPeriod}
                                    </span>
                                )}
                            </div>
                        </div>

                        <div className="rounded-2xl border bg-card shadow-sm">
                            <div className="flex items-start gap-3 border-b p-5">
                                <ReceiptText className="mt-1 size-4 text-muted-foreground" />
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-start justify-between gap-4">
                                        <div>
                                            <p className="font-medium">
                                                {paymentLink.price.label}
                                            </p>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {priceCaption(
                                                    paymentLink.price,
                                                )}
                                            </p>
                                        </div>
                                        <p className="shrink-0 font-medium">
                                            {priceAmount}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div className="space-y-3 p-5 text-sm">
                                <div className="flex items-center justify-between text-muted-foreground">
                                    <span>Subtotal</span>
                                    <span>{priceAmount}</span>
                                </div>
                                <div className="flex items-center justify-between text-muted-foreground">
                                    <span>Tax</span>
                                    <span>Calculated by merchant settings</span>
                                </div>
                                <div className="flex items-center justify-between border-t pt-3 text-base font-semibold">
                                    <span>
                                        {dueTodayLabel(paymentLink.price)}
                                    </span>
                                    <span>{priceAmount}</span>
                                </div>
                            </div>
                        </div>

                        {paymentLink.price.type === 'recurring' && (
                            <p className="max-w-xl rounded-xl bg-muted/50 p-4 text-sm leading-6 text-muted-foreground">
                                This starts a recurring subscription with{' '}
                                {paymentLink.business.name}. You will be charged{' '}
                                {priceAmount} every {recurringPeriod} until the
                                subscription is canceled.
                            </p>
                        )}
                    </div>
                </section>

                <section className="border-t bg-muted/30 px-6 py-8 sm:px-10 lg:border-t-0 lg:border-l lg:px-10 lg:py-12">
                    <div className="mx-auto max-w-md space-y-6 lg:sticky lg:top-12">
                        <div>
                            <h2 className="text-xl font-semibold">
                                Complete checkout
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Enter your contact details, then continue to
                                Nomba to pay securely.
                            </p>
                        </div>

                        {checkoutError && (
                            <Alert variant="destructive">
                                <AlertCircle className="size-4" />
                                <AlertDescription>
                                    {checkoutError}
                                </AlertDescription>
                            </Alert>
                        )}

                        <Form
                            {...checkout.form(paymentLink.publicId)}
                            className="space-y-5 rounded-2xl border bg-background p-5 shadow-sm"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="space-y-4">
                                        <div className="flex items-center gap-2 text-sm font-medium">
                                            <Mail className="size-4 text-muted-foreground" />
                                            Contact information
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="email">Email</Label>
                                            <Input
                                                id="email"
                                                type="email"
                                                name="email"
                                                autoComplete="email"
                                                placeholder="you@example.com"
                                                defaultValue={prefill.email}
                                                required
                                            />
                                            {errors.email && (
                                                <p className="text-sm text-red-600">
                                                    {errors.email}
                                                </p>
                                            )}
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="name">Name</Label>
                                            <Input
                                                id="name"
                                                name="name"
                                                autoComplete="name"
                                                placeholder="Ada Lovelace"
                                                defaultValue={prefill.name}
                                            />
                                            {errors.name && (
                                                <p className="text-sm text-red-600">
                                                    {errors.name}
                                                </p>
                                            )}
                                        </div>
                                    </div>

                                    <div className="rounded-xl border bg-muted/30 p-4">
                                        <div className="flex items-center justify-between gap-4">
                                            <div className="flex items-center gap-3">
                                                <div className="flex size-9 items-center justify-center rounded-md bg-background">
                                                    <CreditCard className="size-4 text-muted-foreground" />
                                                </div>
                                                <div>
                                                    <p className="text-sm font-medium">
                                                        Pay with card
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        Visa, Mastercard, and
                                                        other Nomba-supported
                                                        methods
                                                    </p>
                                                </div>
                                            </div>
                                            <ShieldCheck className="size-5 text-emerald-600" />
                                        </div>
                                    </div>

                                    <label className="flex items-start gap-3 rounded-lg border p-3 text-sm">
                                        <input
                                            type="checkbox"
                                            name="purchasing_as_business"
                                            className="mt-0.5 size-4 rounded border-input"
                                        />
                                        <span className="flex-1 text-muted-foreground">
                                            <span className="flex items-center gap-2 font-medium text-foreground">
                                                <Building2 className="size-4" />
                                                I am purchasing as a business
                                            </span>
                                            Use this if the purchase is for your
                                            company or team.
                                        </span>
                                    </label>

                                    <label className="flex items-start gap-3 text-sm text-muted-foreground">
                                        <input
                                            type="checkbox"
                                            name="accept_terms"
                                            required
                                            className="mt-0.5 size-4 rounded border-input"
                                        />
                                        <span>
                                            I agree to complete this purchase
                                            with {paymentLink.business.name} and
                                            authorize the payment shown above.
                                        </span>
                                    </label>

                                    <Button
                                        type="submit"
                                        className="h-11 w-full text-base"
                                        disabled={processing}
                                    >
                                        {processing
                                            ? 'Redirecting...'
                                            : paymentLink.price.type ===
                                                'recurring'
                                              ? 'Subscribe'
                                              : 'Pay now'}
                                    </Button>

                                    <p className="text-center text-xs leading-5 text-muted-foreground">
                                        You will review and enter card details
                                        on Nomba before payment is completed.
                                    </p>
                                </>
                            )}
                        </Form>

                        <div className="flex items-start gap-3 rounded-xl bg-background p-4 text-xs leading-5 text-muted-foreground">
                            <Lock className="mt-0.5 size-4 shrink-0" />
                            <p>
                                Payment is completed securely on Nomba. Bouclay
                                never sees your card details.
                            </p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    );
}
