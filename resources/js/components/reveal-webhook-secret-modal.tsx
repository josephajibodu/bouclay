import { Check, Copy, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { GeneratedWebhookSecret } from '@/types';

type Props = {
    generatedSecret: GeneratedWebhookSecret | null;
    onClose: () => void;
};

export default function RevealWebhookSecretModal({
    generatedSecret,
    onClose,
}: Props) {
    const [copied, setCopied] = useState(false);
    const [confirmingClose, setConfirmingClose] = useState(false);

    if (!generatedSecret) {
        return null;
    }

    const copySecret = async () => {
        await navigator.clipboard.writeText(generatedSecret.secret);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 2000);
    };

    const attemptClose = () => {
        if (copied) {
            onClose();

            return;
        }

        setConfirmingClose(true);
    };

    return (
        <Dialog open onOpenChange={(nextOpen) => !nextOpen && attemptClose()}>
            <DialogContent data-test="reveal-webhook-secret-modal">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Check className="size-5 text-emerald-500" />
                        Signing secret
                    </DialogTitle>
                    <DialogDescription>{generatedSecret.url}</DialogDescription>
                </DialogHeader>

                <div className="flex items-center gap-2 rounded-lg border bg-muted/30 p-3">
                    <code
                        className="flex-1 truncate font-mono text-sm"
                        data-test="generated-webhook-secret-value"
                    >
                        {generatedSecret.secret}
                    </code>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={copySecret}
                        data-test="copy-webhook-secret"
                    >
                        {copied ? (
                            <>
                                <Check /> Copied
                            </>
                        ) : (
                            <>
                                <Copy /> Copy
                            </>
                        )}
                    </Button>
                </div>

                <div className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/50 dark:text-amber-100">
                    <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                    <p>
                        Use this secret to verify Bouclay-Signature headers on
                        incoming webhooks. This is the only time you'll see it.
                    </p>
                </div>

                {confirmingClose && !copied ? (
                    <div className="space-y-3 rounded-lg border p-4 text-sm">
                        <p>
                            You haven't copied this secret. Once you close
                            this, you won't see it again.
                        </p>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setConfirmingClose(false)}
                            >
                                Keep open
                            </Button>
                            <Button
                                type="button"
                                variant="destructive"
                                size="sm"
                                onClick={onClose}
                                data-test="close-webhook-secret-without-copying"
                            >
                                Close anyway
                            </Button>
                        </div>
                    </div>
                ) : (
                    <Button
                        type="button"
                        onClick={onClose}
                        data-test="acknowledge-webhook-secret-saved"
                    >
                        I've saved it — close
                    </Button>
                )}
            </DialogContent>
        </Dialog>
    );
}
