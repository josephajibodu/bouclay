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
import { destroy } from '@/routes/developers/api-keys';
import type { ApiKey } from '@/types';

type Props = {
    apiKey: ApiKey | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function RevokeApiKeyModal({
    apiKey,
    open,
    onOpenChange,
}: Props) {
    const [processing, setProcessing] = useState(false);

    const revoke = () => {
        if (!apiKey) {
            return;
        }

        router.visit(destroy(apiKey.id), {
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
                    <DialogTitle>
                        Revoke "{apiKey?.name}"?
                    </DialogTitle>
                    <DialogDescription>
                        Any requests using this key will start failing
                        immediately. This can't be undone.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>

                    <Button
                        variant="destructive"
                        data-test="revoke-api-key-confirm"
                        disabled={processing}
                        onClick={revoke}
                    >
                        Revoke key
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
