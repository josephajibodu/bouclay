import { router } from '@inertiajs/react';
import { useState } from 'react';
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
import { destroy } from '@/routes/catalog/trials';

type Props = {
    currentTeamSlug: string;
    productId: number;
    productName: string;
    trialId: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function RemoveTrialModal({
    currentTeamSlug,
    productId,
    productName,
    trialId,
    open,
    onOpenChange,
}: Props) {
    const [processing, setProcessing] = useState(false);

    const remove = () => {
        router.visit(destroy.url([currentTeamSlug, productId, trialId]), {
            method: 'delete',
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Remove free trial?</DialogTitle>
                    <DialogDescription>
                        New subscriptions to {productName} will no longer
                        include a free trial. Customers currently mid-trial
                        are unaffected.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        disabled={processing}
                        onClick={remove}
                        data-test="remove-trial-confirm"
                    >
                        Remove trial
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
