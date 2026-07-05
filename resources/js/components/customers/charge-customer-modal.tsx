import { router } from '@inertiajs/react';
import { Check, Copy, ExternalLink, Lock } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store as charge } from '@/routes/customers/charge';
import type { CustomerDetail } from '@/types';

type Props = {
    customer: CustomerDetail;
    currency: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

type CheckoutLink = { url: string; customerEmail: string };

export default function ChargeCustomerModal({
    customer,
    currency,
    open,
    onOpenChange,
}: Props) {
    const [amount, setAmount] = useState('');
    const [setDefault, setSetDefault] = useState(true);
    const [processing, setProcessing] = useState(false);
    const [link, setLink] = useState<CheckoutLink | null>(null);
    const [copied, setCopied] = useState(false);

    // The created checkout link comes back on the Inertia flash — the same
    // reveal-once channel API keys use.
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const payload = flash?.checkoutLink as CheckoutLink | undefined;

            if (payload) {
                setLink(payload);
            }
        });
    }, []);

    const reset = () => {
        setAmount('');
        setSetDefault(true);
        setLink(null);
        setCopied(false);
    };

    const submit = () => {
        router.post(
            charge(customer.id).url,
            { amount, set_default: setDefault },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const copyLink = async () => {
        if (!link) {
            return;
        }

        await navigator.clipboard.writeText(link.url);
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
                {link ? (
                    <>
                        <DialogHeader>
                            <DialogTitle>Payment link ready</DialogTitle>
                            <DialogDescription>
                                Send this to {link.customerEmail} to pay. Their
                                card is saved here automatically once they
                                complete it.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="flex flex-col gap-3">
                            <div className="flex items-center gap-2">
                                <Input
                                    readOnly
                                    value={link.url}
                                    className="font-mono text-xs"
                                    onFocus={(e) => e.target.select()}
                                    data-test="charge-link"
                                />
                                <Button
                                    variant="outline"
                                    size="icon"
                                    onClick={copyLink}
                                    aria-label="Copy payment link"
                                >
                                    {copied ? (
                                        <Check className="text-emerald-500" />
                                    ) : (
                                        <Copy />
                                    )}
                                </Button>
                            </div>

                            <p className="flex items-center gap-2 rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
                                <Lock className="size-3.5 shrink-0" />
                                Card details are entered on Nomba and never touch
                                Bouclay — only a secure token, brand, and last
                                four are stored.
                            </p>
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
                                    href={link.url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <ExternalLink /> Open checkout
                                </a>
                            </Button>
                        </DialogFooter>
                    </>
                ) : (
                    <>
                        <DialogHeader>
                            <DialogTitle>Charge customer</DialogTitle>
                            <DialogDescription>
                                Create a secure Nomba payment link. Send it to
                                the customer or open it here — their card is
                                saved automatically once it's paid.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="flex flex-col gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="charge-amount">
                                    Amount ({currency})
                                </Label>
                                <Input
                                    id="charge-amount"
                                    type="number"
                                    min="1"
                                    step="0.01"
                                    inputMode="decimal"
                                    value={amount}
                                    onChange={(e) => setAmount(e.target.value)}
                                    placeholder="0.00"
                                    autoFocus
                                    data-test="charge-amount"
                                />
                                <p className="text-xs text-muted-foreground">
                                    The amount the customer actually pays — this
                                    isn't a setup fee.
                                </p>
                            </div>

                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={setDefault}
                                    onCheckedChange={(value) =>
                                        setSetDefault(value === true)
                                    }
                                />
                                Set the saved card as default
                            </label>
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
                                onClick={submit}
                                disabled={processing || amount === ''}
                                data-test="charge-submit"
                            >
                                {processing && <Spinner />}
                                Create payment link
                            </Button>
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
