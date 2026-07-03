import { Form } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { store } from '@/routes/developers/api-keys';
import type { ApiKeyKind, ApiKeyMode } from '@/types';

type Props = PropsWithChildren<{
    liveNombaConnected: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}>;

export default function CreateApiKeyDrawer({
    children,
    liveNombaConnected,
    open,
    onOpenChange,
}: Props) {
    const [kind, setKind] = useState<ApiKeyKind>('secret');
    const [mode, setMode] = useState<ApiKeyMode>('test');

    const handleOpenChange = (nextOpen: boolean) => {
        onOpenChange(nextOpen);

        if (!nextOpen) {
            setKind('secret');
            setMode('test');
        }
    };

    return (
        <Sheet open={open} onOpenChange={handleOpenChange}>
            <SheetTrigger asChild>{children}</SheetTrigger>
            <SheetContent className="h-auto w-full rounded-xl border sm:inset-y-4 sm:right-4 sm:w-3/4 sm:max-w-md">
                <Form
                    key={String(open)}
                    {...store.form()}
                    transform={(data) => ({ ...data, kind, mode })}
                    className="flex h-full flex-col"
                    onSuccess={() => handleOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <SheetHeader>
                                <SheetTitle>Create API key</SheetTitle>
                                <SheetDescription>
                                    Name your key so your team knows what it's
                                    for.
                                </SheetDescription>
                            </SheetHeader>

                            <div className="flex flex-col gap-6 overflow-y-auto px-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="api-key-name">
                                        Name{' '}
                                        <span className="text-destructive">
                                            *
                                        </span>
                                    </Label>
                                    <Input
                                        id="api-key-name"
                                        name="name"
                                        data-test="api-key-name"
                                        placeholder="Backend server"
                                        required
                                        autoComplete="off"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label>Key type</Label>
                                    <ToggleGroup
                                        type="single"
                                        variant="outline"
                                        value={kind}
                                        onValueChange={(value) =>
                                            value &&
                                            setKind(value as ApiKeyKind)
                                        }
                                        className="w-full"
                                    >
                                        <ToggleGroupItem
                                            value="publishable"
                                            className="flex-1"
                                            data-test="api-key-kind-publishable"
                                        >
                                            Publishable
                                        </ToggleGroupItem>
                                        <ToggleGroupItem
                                            value="secret"
                                            className="flex-1"
                                            data-test="api-key-kind-secret"
                                        >
                                            Secret
                                        </ToggleGroupItem>
                                    </ToggleGroup>
                                    <p className="text-sm text-muted-foreground">
                                        {kind === 'publishable'
                                            ? 'Safe for client-side code — your storefront, checkout page, mobile app.'
                                            : 'Server-to-server only — never ship one to a browser or commit one to a repo.'}
                                    </p>
                                    <InputError message={errors.kind} />
                                </div>

                                <div className="grid gap-2">
                                    <Label>Environment</Label>
                                    <ToggleGroup
                                        type="single"
                                        variant="outline"
                                        value={mode}
                                        onValueChange={(value) =>
                                            value &&
                                            setMode(value as ApiKeyMode)
                                        }
                                        className="w-full"
                                    >
                                        <ToggleGroupItem
                                            value="test"
                                            className="flex-1"
                                            data-test="api-key-mode-test"
                                        >
                                            Test
                                        </ToggleGroupItem>
                                        <ToggleGroupItem
                                            value="live"
                                            className="flex-1"
                                            disabled={!liveNombaConnected}
                                            data-test="api-key-mode-live"
                                        >
                                            Live
                                        </ToggleGroupItem>
                                    </ToggleGroup>
                                    {!liveNombaConnected && (
                                        <p className="text-sm text-muted-foreground">
                                            Connect a live Nomba account
                                            before creating a live key.
                                        </p>
                                    )}
                                    <InputError message={errors.mode} />
                                </div>
                            </div>

                            <SheetFooter className="flex-row justify-end gap-2">
                                <SheetClose asChild>
                                    <Button variant="secondary">
                                        Cancel
                                    </Button>
                                </SheetClose>

                                <Button
                                    type="submit"
                                    data-test="create-api-key-submit"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Create key
                                </Button>
                            </SheetFooter>
                        </>
                    )}
                </Form>
            </SheetContent>
        </Sheet>
    );
}
