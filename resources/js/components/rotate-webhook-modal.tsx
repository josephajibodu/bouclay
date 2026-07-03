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
import { rotate } from '@/routes/developers/webhooks';

type Props = {
    currentTeamSlug: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function RotateWebhookModal({
    currentTeamSlug,
    open,
    onOpenChange,
}: Props) {
    const [processing, setProcessing] = useState(false);

    const rotateEndpoint = () => {
        router.visit(rotate(currentTeamSlug), {
            method: 'post',
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Rotate webhook endpoint?</DialogTitle>
                    <DialogDescription>
                        This generates a new URL. Update it in Nomba's
                        dashboard immediately, or events will stop arriving.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>

                    <Button
                        variant="destructive"
                        data-test="rotate-webhook-confirm"
                        disabled={processing}
                        onClick={rotateEndpoint}
                    >
                        Rotate endpoint
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
