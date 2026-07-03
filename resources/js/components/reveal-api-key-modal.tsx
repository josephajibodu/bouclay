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
import type { GeneratedApiKey } from '@/types';

type Props = {
    generatedKey: GeneratedApiKey | null;
    onClose: () => void;
};

export default function RevealApiKeyModal({ generatedKey, onClose }: Props) {
    const [copied, setCopied] = useState(false);
    const [confirmingClose, setConfirmingClose] = useState(false);

    if (!generatedKey) {
        return null;
    }

    const copyKey = async () => {
        await navigator.clipboard.writeText(generatedKey.key);
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
        <Dialog
            open
            onOpenChange={(nextOpen) => !nextOpen && attemptClose()}
        >
            <DialogContent data-test="reveal-api-key-modal">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Check className="size-5 text-emerald-500" />
                        Key created
                    </DialogTitle>
                    <DialogDescription>
                        {generatedKey.name}
                    </DialogDescription>
                </DialogHeader>

                <div className="flex items-center gap-2 rounded-lg border bg-muted/30 p-3">
                    <code
                        className="flex-1 truncate font-mono text-sm"
                        data-test="generated-api-key-value"
                    >
                        {generatedKey.key}
                    </code>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={copyKey}
                        data-test="copy-api-key"
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
                        This is the only time you'll see this key. Store it
                        somewhere safe — we only keep a hash.
                    </p>
                </div>

                {confirmingClose && !copied ? (
                    <div className="space-y-3 rounded-lg border p-4 text-sm">
                        <p>
                            You haven't copied this key. Once you close this,
                            you won't see it again.
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
                                data-test="close-without-copying"
                            >
                                Close anyway
                            </Button>
                        </div>
                    </div>
                ) : (
                    <Button
                        type="button"
                        onClick={onClose}
                        data-test="acknowledge-api-key-saved"
                    >
                        I've saved it — close
                    </Button>
                )}
            </DialogContent>
        </Dialog>
    );
}
