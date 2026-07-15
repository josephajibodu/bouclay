import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney } from '@/lib/utils';
import { refund } from '@/routes/invoices/payments';
import type { InvoicePaymentDetail } from '@/types';

type Props = {
    invoiceId: number;
    payment: InvoicePaymentDetail | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function RefundPaymentDrawer({
    invoiceId,
    payment,
    open,
    onOpenChange,
}: Props) {
    // Amounts are entered in major units; the server stores minor.
    const [amount, setAmount] = useState('');

    const handleOpenChange = (nextOpen: boolean) => {
        onOpenChange(nextOpen);

        if (!nextOpen) {
            setAmount('');
        }
    };

    if (!payment) {
        return null;
    }

    const refundableMajor = payment.refundableAmount / 100;
    const partialOnly = !payment.refundSupport.partialRefunds;

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent>
                <Form
                    key={payment.id}
                    {...refund.form({
                        invoice: invoiceId,
                        payment: payment.id,
                    })}
                    className="space-y-6"
                    options={{ preserveScroll: true }}
                    onSuccess={() => handleOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Refund payment</DialogTitle>
                                <DialogDescription>
                                    {formatMoney(
                                        payment.refundableAmount / 100,
                                        payment.currency,
                                    )}{' '}
                                    of{' '}
                                    {formatMoney(
                                        payment.amount / 100,
                                        payment.currency,
                                    )}{' '}
                                    is still refundable on this charge. The
                                    money is returned through{' '}
                                    {payment.refundSupport.gatewayLabel}.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="space-y-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="refund-amount">
                                        Amount ({payment.currency})
                                    </Label>
                                    <Input
                                        id="refund-amount"
                                        name="amount"
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        max={refundableMajor}
                                        required
                                        readOnly={partialOnly}
                                        value={
                                            partialOnly
                                                ? refundableMajor.toFixed(2)
                                                : amount
                                        }
                                        onChange={(event) =>
                                            setAmount(event.target.value)
                                        }
                                        placeholder={refundableMajor.toFixed(2)}
                                        data-test="refund-amount"
                                    />
                                    {partialOnly ? (
                                        <p className="text-sm text-muted-foreground">
                                            {payment.refundSupport.gatewayLabel}{' '}
                                            only supports refunding a charge in
                                            full.
                                        </p>
                                    ) : (
                                        <button
                                            type="button"
                                            className="w-fit text-sm text-muted-foreground underline underline-offset-4"
                                            onClick={() =>
                                                setAmount(
                                                    refundableMajor.toFixed(2),
                                                )
                                            }
                                        >
                                            Refund the full remaining balance
                                        </button>
                                    )}
                                    <InputError message={errors.amount} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="refund-reason">
                                        Reason (optional)
                                    </Label>
                                    <Input
                                        id="refund-reason"
                                        name="reason"
                                        maxLength={255}
                                        placeholder="Shown on the refund record"
                                        data-test="refund-reason"
                                    />
                                    <InputError message={errors.reason} />
                                </div>
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary" type="button">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="refund-submit"
                                >
                                    {processing && <Spinner />}
                                    Refund
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
