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
import { archive } from '@/routes/catalog/prices';

type Props = {
    productId: number;
    priceId: number;
    priceLabel: string;
    isLastActivePrice: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function ArchivePriceModal({
    productId,
    priceId,
    priceLabel,
    isLastActivePrice,
    open,
    onOpenChange,
}: Props) {
    const [processing, setProcessing] = useState(false);

    const confirmArchive = () => {
        router.visit(archive.url([productId, priceId]), {
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
                    <DialogTitle>Archive "{priceLabel}"?</DialogTitle>
                    <DialogDescription>
                        {isLastActivePrice
                            ? "This is this product's only price — archiving it will mark the product as needing a price again."
                            : "New subscriptions won't be able to choose this price. Existing subscribers are unaffected."}
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
                        data-test="archive-price-confirm"
                    >
                        Archive price
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
