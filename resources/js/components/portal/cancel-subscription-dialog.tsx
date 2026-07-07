import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatPortalDate } from '@/lib/portal-format';
import { cancel as cancelSubscription } from '@/routes/portal/subscriptions';
import type { PortalSubscriptionListItem } from '@/types/portal';

export function CancelSubscriptionDialog({
    token,
    subscription,
    open,
    onOpenChange,
}: {
    token: string;
    subscription: PortalSubscriptionListItem | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [canceling, setCanceling] = useState(false);

    const confirmCancel = () => {
        if (!subscription) {
            return;
        }

        setCanceling(true);
        router.post(
            cancelSubscription({ token, publicId: subscription.publicId }).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setCanceling(false);
                    onOpenChange(false);
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Cancel subscription?</DialogTitle>
                    <DialogDescription>
                        {subscription && (
                            <>
                                <span className="font-medium text-foreground">
                                    {subscription.productName}
                                </span>{' '}
                                will stay active until{' '}
                                {formatPortalDate(
                                    subscription.currentPeriodEnd ??
                                        subscription.trialEndsAt,
                                )}
                                . You won&apos;t be charged again after that.
                            </>
                        )}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={canceling}
                    >
                        Keep subscription
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={confirmCancel}
                        disabled={canceling}
                        data-test="portal-confirm-cancel"
                    >
                        {canceling ? 'Canceling…' : 'Cancel at period end'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
