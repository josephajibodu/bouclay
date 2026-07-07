import { Form, Head } from '@inertiajs/react';
import { CreditCard } from 'lucide-react';
import { PortalCard } from '@/components/portal/portal-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { store as storePaymentMethod } from '@/routes/portal/payment-method';
import type { PortalSharedProps } from '@/types/portal';

type SavedMethod = {
    brand: string | null;
    last4: string | null;
    expMonth: number | null;
    expYear: number | null;
    isDefault: boolean;
    isExpired: boolean;
};

type Props = PortalSharedProps & {
    paymentMethods: SavedMethod[];
};

function expiryLabel(method: SavedMethod): string {
    if (!method.expMonth || !method.expYear) {
        return 'Expiry unknown';
    }

    const mm = String(method.expMonth).padStart(2, '0');
    const yy = String(method.expYear).slice(-2);

    return `${method.isExpired ? 'Expired' : 'Expires'} ${mm}/${yy}`;
}

export default function PortalPaymentMethodsIndex({
    token,
    business,
    canUpdatePaymentMethod,
    paymentMethods,
}: Props) {
    return (
        <>
            <Head title={`Payment methods · ${business.name}`} />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold">Payment methods</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Cards saved for billing with {business.name}.
                    </p>
                </div>

                <PortalCard>
                    {paymentMethods.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No card on file yet. Add one below, or pay an
                            invoice to save a card automatically.
                        </p>
                    ) : (
                        <div className="divide-y">
                            {paymentMethods.map((method, index) => (
                                <div
                                    key={`${method.brand}-${method.last4}-${index}`}
                                    className="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                                >
                                    <div className="flex items-center gap-3">
                                        <CreditCard
                                            className={
                                                method.isExpired
                                                    ? 'size-5 text-destructive'
                                                    : 'size-5 text-muted-foreground'
                                            }
                                        />
                                        <div>
                                            <p className="font-medium">
                                                {method.brand ?? 'Card'} ····{' '}
                                                {method.last4 ?? '••••'}
                                            </p>
                                            <p
                                                className={
                                                    method.isExpired
                                                        ? 'text-xs text-destructive'
                                                        : 'text-xs text-muted-foreground'
                                                }
                                            >
                                                {expiryLabel(method)}
                                            </p>
                                        </div>
                                    </div>
                                    {method.isDefault && (
                                        <Badge variant="secondary">
                                            Default
                                        </Badge>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}

                    {canUpdatePaymentMethod && (
                        <Form
                            {...storePaymentMethod.form(token)}
                            className="mt-6 border-t pt-4"
                        >
                            {({ processing }) => (
                                <div className="space-y-2">
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                        data-test="portal-update-card"
                                    >
                                        {processing
                                            ? 'Redirecting…'
                                            : paymentMethods.length > 0
                                              ? 'Update payment method'
                                              : 'Add payment method'}
                                    </Button>
                                    <p className="text-xs text-muted-foreground">
                                        Secure Nomba checkout. A small
                                        verification charge may apply.
                                    </p>
                                </div>
                            )}
                        </Form>
                    )}
                </PortalCard>
            </div>
        </>
    );
}
