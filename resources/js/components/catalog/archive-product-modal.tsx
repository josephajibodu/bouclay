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
import { update } from '@/routes/catalog/products';

type Props = {
    currentTeamSlug: string;
    productId: number;
    productName: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function ArchiveProductModal({
    currentTeamSlug,
    productId,
    productName,
    open,
    onOpenChange,
}: Props) {
    const [processing, setProcessing] = useState(false);

    const archive = () => {
        router.patch(
            update.url([currentTeamSlug, productId]),
            { status: 'archived' },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => onOpenChange(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Archive "{productName}"?</DialogTitle>
                    <DialogDescription>
                        Archived products won't appear when creating new
                        subscriptions. Existing subscribers are unaffected.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        disabled={processing}
                        onClick={archive}
                        data-test="archive-product-confirm"
                    >
                        Archive product
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
