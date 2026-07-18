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
import { archive } from '@/routes/catalog/pricing-journeys';

type Props = {
    productId: number;
    journeyId: number;
    journeyName: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function ArchivePricingJourneyModal({
    productId,
    journeyId,
    journeyName,
    open,
    onOpenChange,
}: Props) {
    const [processing, setProcessing] = useState(false);

    const confirmArchive = () => {
        router.visit(archive.url([productId, journeyId]), {
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
                    <DialogTitle>Archive "{journeyName}"?</DialogTitle>
                    <DialogDescription>
                        New subscriptions won't be able to start through this
                        journey. Existing schedules already copied from it are
                        unaffected — they keep running exactly as they are.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        disabled={processing}
                        onClick={confirmArchive}
                        data-test="archive-journey-confirm"
                    >
                        Archive journey
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
