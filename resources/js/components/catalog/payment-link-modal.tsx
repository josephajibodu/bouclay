import { router } from '@inertiajs/react';
import { Check, Copy, ExternalLink, Link as LinkIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { paymentLink as createPaymentLink } from '@/routes/catalog/prices';
import { paymentLink as createTrialPaymentLink } from '@/routes/catalog/trials';
import type { Price, TrialOffer } from '@/types';

type PaymentLinkPayload = {
    url: string;
    productName: string;
    priceLabel: string;
};

type Props = {
    productId: number;
    productName: string;
    price: Price | null;
    trial?: TrialOffer | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function PaymentLinkModal({
    productId,
    productName,
    price,
    trial = null,
    open,
    onOpenChange,
}: Props) {
    const [processing, setProcessing] = useState(false);
    const [link, setLink] = useState<PaymentLinkPayload | null>(null);
    const [copied, setCopied] = useState(false);
    const existingLink = price?.paymentLink
        ? {
              url: price.paymentLink.url,
              productName,
              priceLabel: price.paymentLink.priceLabel,
          }
        : trial?.paymentLink
          ? {
                url: trial.paymentLink.url,
                productName,
                priceLabel: trial.paymentLink.priceLabel,
            }
          : null;
    const visibleLink = link ?? existingLink;
    const isTrialLink = trial !== null;

    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const payload = flash?.paymentLink as
                PaymentLinkPayload | undefined;

            if (payload) {
                setLink(payload);
            }
        });
    }, []);

    const reset = () => {
        setProcessing(false);
        setLink(null);
        setCopied(false);
    };

    const create = () => {
        if (!price && !trial) {
            return;
        }

        const url =
            trial !== null
                ? createTrialPaymentLink({
                      product: productId,
                      trial_offer: trial.id,
                  }).url
                : createPaymentLink({ product: productId, price: price!.id })
                      .url;

        router.post(
            url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const copy = async () => {
        if (!visibleLink) {
            return;
        }

        await navigator.clipboard.writeText(visibleLink.url);
        setCopied(true);
        toast.success('Payment link copied');
        window.setTimeout(() => setCopied(false), 2000);
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    reset();
                }

                onOpenChange(next);
            }}
        >
            <DialogContent>
                {visibleLink ? (
                    <>
                        <DialogHeader>
                            <DialogTitle>
                                {isTrialLink ? 'Trial link' : 'Payment link'}
                            </DialogTitle>
                            <DialogDescription>
                                {isTrialLink
                                    ? 'Share this hosted trial page anywhere. Buyers enter their email and start the free trial without a card.'
                                    : 'Share this hosted checkout page anywhere. Buyers enter their email, then pay the exact catalog price on Nomba.'}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="flex flex-col gap-3">
                            <div className="rounded-md border bg-muted/30 px-3 py-2 text-sm">
                                <p className="font-medium">
                                    {visibleLink.productName ||
                                        'Hosted checkout'}
                                </p>
                                <p className="text-muted-foreground">
                                    {visibleLink.priceLabel}
                                </p>
                            </div>
                            <div className="flex items-center gap-2">
                                <Input
                                    readOnly
                                    value={visibleLink.url}
                                    className="font-mono text-xs"
                                    onFocus={(e) => e.target.select()}
                                    data-test="payment-link-url"
                                />
                                <Button
                                    variant="outline"
                                    size="icon"
                                    onClick={copy}
                                    aria-label="Copy payment link"
                                >
                                    {copied ? (
                                        <Check className="text-emerald-500" />
                                    ) : (
                                        <Copy />
                                    )}
                                </Button>
                            </div>
                        </div>

                        <DialogFooter className="flex-row justify-end gap-2">
                            <Button
                                variant="secondary"
                                type="button"
                                onClick={() => onOpenChange(false)}
                            >
                                Done
                            </Button>
                            <Button asChild type="button">
                                <a
                                    href={visibleLink.url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <ExternalLink /> Open link
                                </a>
                            </Button>
                        </DialogFooter>
                    </>
                ) : (
                    <>
                        <DialogHeader>
                            <DialogTitle>
                                {isTrialLink
                                    ? 'Create trial link'
                                    : 'Create payment link'}
                            </DialogTitle>
                            <DialogDescription>
                                {isTrialLink
                                    ? 'Create a reusable hosted URL for this trial offer. Customers can start the trial without you using the API.'
                                    : 'Create a reusable hosted checkout URL for this price. Customers can pay without you using the API.'}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="flex items-start gap-3 rounded-md border bg-muted/30 p-3 text-sm">
                            <LinkIcon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                            <p className="text-muted-foreground">
                                {isTrialLink
                                    ? 'The link creates the customer and starts a trialing subscription immediately. No invoice or payment is created today.'
                                    : 'The link creates the customer at checkout time and sends them to Nomba for secure payment.'}
                            </p>
                        </div>

                        <DialogFooter className="flex-row justify-end gap-2">
                            <Button
                                variant="secondary"
                                type="button"
                                onClick={() => onOpenChange(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                onClick={create}
                                disabled={processing || (!price && !trial)}
                                data-test="create-payment-link-submit"
                            >
                                {processing && <Spinner />}
                                {isTrialLink
                                    ? 'Create trial link'
                                    : 'Create payment link'}
                            </Button>
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
