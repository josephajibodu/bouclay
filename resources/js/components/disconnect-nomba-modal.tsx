import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { disconnect } from '@/routes/developers/nomba';
import type { ApiKeyMode } from '@/types';

type Props = {
    mode: ApiKeyMode | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const CONFIRMATION_PHRASE = 'DISCONNECT';

export default function DisconnectNombaModal({
    mode,
    open,
    onOpenChange,
}: Props) {
    const [confirmationText, setConfirmationText] = useState('');

    const canDisconnect = confirmationText === CONFIRMATION_PHRASE;

    const handleOpenChange = (nextOpen: boolean) => {
        onOpenChange(nextOpen);

        if (!nextOpen) {
            setConfirmationText('');
        }
    };

    if (!mode) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent>
                <Form
                    key={mode}
                    {...disconnect.form()}
                    className="space-y-6"
                    onSuccess={() => handleOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <input type="hidden" name="mode" value={mode} />

                            <DialogHeader>
                                <DialogTitle>
                                    Disconnect {mode} Nomba account?
                                </DialogTitle>
                                <DialogDescription>
                                    {mode === 'live' ? (
                                        <>
                                            Any active subscriptions will fail
                                            to renew until you reconnect a live
                                            Nomba account.
                                        </>
                                    ) : (
                                        <>
                                            You won't be able to test checkout,
                                            charges, or refunds until you
                                            reconnect.
                                        </>
                                    )}
                                </DialogDescription>
                            </DialogHeader>

                            <div className="space-y-4 py-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="disconnect-confirmation">
                                        Type{' '}
                                        <strong>"{CONFIRMATION_PHRASE}"</strong>{' '}
                                        to confirm
                                    </Label>
                                    <Input
                                        id="disconnect-confirmation"
                                        data-test="disconnect-nomba-confirmation"
                                        value={confirmationText}
                                        onChange={(event) =>
                                            setConfirmationText(
                                                event.target.value,
                                            )
                                        }
                                        placeholder={CONFIRMATION_PHRASE}
                                        autoComplete="off"
                                    />
                                    <InputError message={errors.mode} />
                                </div>
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>

                                <Button
                                    variant="destructive"
                                    type="submit"
                                    data-test="disconnect-nomba-confirm"
                                    disabled={!canDisconnect || processing}
                                >
                                    Disconnect
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
