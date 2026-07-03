import { Form, Head } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import AutofillGuard from '@/components/autofill-guard';
import DisconnectNombaModal from '@/components/disconnect-nomba-modal';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatRelativeTime } from '@/lib/utils';
import { connect, show, test } from '@/routes/developers/nomba';
import type { ApiKeyMode, NombaConnection, NombaModeStatus } from '@/types';

type Props = {
    connection: NombaConnection;
    canManage: boolean;
};

export default function NombaIntegration({ connection, canManage }: Props) {
    const [activeTab, setActiveTab] = useState<ApiKeyMode>('test');
    const [disconnectMode, setDisconnectMode] = useState<ApiKeyMode | null>(
        null,
    );

    return (
        <div className="flex max-w-2xl flex-col gap-6 p-4">
            <Head title="Nomba Integration" />

            <div className="space-y-1">
                <h1 className="text-2xl font-semibold">Nomba Integration</h1>
                <p className="text-sm text-muted-foreground">
                    Your Nomba account processes all payments. Bouclay never
                    holds funds — everything settles directly to you.
                </p>
            </div>

            <Tabs
                value={activeTab}
                onValueChange={(value) => setActiveTab(value as ApiKeyMode)}
            >
                <TabsList>
                    <TabsTrigger value="test" data-test="nomba-tab-test">
                        Test
                    </TabsTrigger>
                    <TabsTrigger value="live" data-test="nomba-tab-live">
                        Live
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="test" className="mt-6">
                    <ModePanel
                        mode="test"
                        status={connection.test}
                        canManage={canManage}
                        onDisconnect={() => setDisconnectMode('test')}
                    />
                </TabsContent>

                <TabsContent value="live" className="mt-6">
                    <ModePanel
                        mode="live"
                        status={connection.live}
                        canManage={canManage}
                        onDisconnect={() => setDisconnectMode('live')}
                    />
                </TabsContent>
            </Tabs>

            <div className="rounded-lg border bg-muted/30 p-4 text-sm">
                <p className="font-medium">Why we ask for this</p>
                <p className="mt-1 text-muted-foreground">
                    Bouclay uses your Nomba credentials to tokenise cards,
                    charge customers, and process refunds — on your behalf,
                    under your account. We encrypt these at rest and never
                    display your client secret again after you save it.
                </p>
            </div>

            <DisconnectNombaModal
                mode={disconnectMode}
                open={disconnectMode !== null}
                onOpenChange={(open) => !open && setDisconnectMode(null)}
            />
        </div>
    );
}

type ModePanelProps = {
    mode: ApiKeyMode;
    status: NombaModeStatus;
    canManage: boolean;
    onDisconnect: () => void;
};

function ModePanel({ mode, status, canManage, onDisconnect }: ModePanelProps) {
    if (status.connected) {
        return (
            <ConnectedCard
                mode={mode}
                status={status}
                canManage={canManage}
                onDisconnect={onDisconnect}
            />
        );
    }

    return <ConnectForm mode={mode} canManage={canManage} />;
}

function ConnectedCard({
    mode,
    status,
    canManage,
    onDisconnect,
}: ModePanelProps) {
    return (
        <div
            className="space-y-4 rounded-lg border p-4"
            data-test={`nomba-connected-${mode}`}
        >
            <div className="flex items-center gap-2">
                <span className="flex size-2 rounded-full bg-emerald-500" />
                <span className="font-medium">Connected</span>
            </div>

            <dl className="grid gap-1 text-sm text-muted-foreground">
                <div>
                    Account ending in{' '}
                    <span className="font-mono">
                        •••• {status.accountIdPreview}
                    </span>
                </div>
                {status.subaccountIdPreview ? (
                    <div>
                        Requests scoped to sub-account ending in{' '}
                        <span className="font-mono">
                            •••• {status.subaccountIdPreview}
                        </span>
                    </div>
                ) : (
                    <div>Requests scoped to the main account</div>
                )}
                <div>
                    Client ID ending in{' '}
                    <span className="font-mono">
                        •••• {status.clientIdPreview}
                    </span>
                </div>
                {status.connectedAt && (
                    <div>Verified {formatRelativeTime(status.connectedAt)}</div>
                )}
            </dl>

            {canManage && (
                <Form
                    {...test.form()}
                    transform={(data) => ({ ...data, mode })}
                    className="flex items-center gap-2"
                >
                    {({ processing }) => (
                        <>
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                disabled={processing}
                                data-test={`nomba-test-connection-${mode}`}
                            >
                                {processing && <Spinner />}
                                Test connection
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="text-destructive hover:text-destructive"
                                onClick={onDisconnect}
                                data-test={`nomba-disconnect-${mode}`}
                            >
                                Disconnect
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}

function ConnectForm({
    mode,
    canManage,
}: {
    mode: ApiKeyMode;
    canManage: boolean;
}) {
    const [liveConfirmation, setLiveConfirmation] = useState('');
    const isLive = mode === 'live';
    const liveConfirmed = liveConfirmation === 'CONNECT LIVE';

    if (!canManage) {
        return (
            <EmptyState
                title={
                    isLive
                        ? 'No live Nomba account connected'
                        : 'Connect Nomba to start accepting payments'
                }
                description="Ask a teammate with integrations access to connect Nomba for this business."
            />
        );
    }

    return (
        <div className="space-y-6">
            <EmptyState
                title={
                    isLive
                        ? 'No live Nomba account connected'
                        : 'Connect Nomba to start accepting payments'
                }
                description={
                    isLive
                        ? "Once you're ready to accept real payments, connect your live Nomba credentials here."
                        : 'Bouclay is the billing engine — Nomba is the payment processor. Every charge, refund, and payout happens on your Nomba account. Start with test keys — nothing here touches real funds yet.'
                }
            />

            {isLive && (
                <div className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/50 dark:text-amber-100">
                    <ShieldAlert className="mt-0.5 size-4 shrink-0" />
                    <p>
                        You're about to connect a live Nomba account. Real
                        customers will be charged real money through this
                        integration once you go live.
                    </p>
                </div>
            )}

            <Form
                {...connect.form()}
                transform={(data) => ({ ...data, mode })}
                resetOnSuccess
                className="space-y-4"
            >
                {({ errors, processing }) => (
                    <>
                        <AutofillGuard />

                        <div className="grid gap-2">
                            <Label htmlFor={`account_id_${mode}`}>
                                Account ID
                            </Label>
                            <Input
                                id={`account_id_${mode}`}
                                name="account_id"
                                required
                                autoComplete="off"
                                placeholder="e.g. 01a10aeb-d989-460a-bbde-9842f2b4320f"
                            />
                            <InputErrorText message={errors.account_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor={`subaccount_id_${mode}`}>
                                Sub-account ID (optional)
                            </Label>
                            <Input
                                id={`subaccount_id_${mode}`}
                                name="subaccount_id"
                                autoComplete="off"
                                placeholder="Leave blank to use the main account"
                            />
                            <p className="text-sm text-muted-foreground">
                                If your Nomba account has sub-accounts, charges
                                and refunds will be scoped to this one instead
                                of the main account.
                            </p>
                            <InputErrorText message={errors.subaccount_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor={`client_id_${mode}`}>
                                Client ID
                            </Label>
                            <Input
                                id={`client_id_${mode}`}
                                name="client_id"
                                required
                                autoComplete="off"
                            />
                            <InputErrorText message={errors.client_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor={`client_secret_${mode}`}>
                                Client secret
                            </Label>
                            <PasswordInput
                                id={`client_secret_${mode}`}
                                name="client_secret"
                                required
                                autoComplete="new-password"
                            />
                            <InputErrorText message={errors.client_secret} />
                        </div>

                        {isLive && (
                            <div className="grid gap-2">
                                <Label htmlFor="live-confirm">
                                    Type <strong>"CONNECT LIVE"</strong> to
                                    confirm
                                </Label>
                                <Input
                                    id="live-confirm"
                                    value={liveConfirmation}
                                    onChange={(event) =>
                                        setLiveConfirmation(event.target.value)
                                    }
                                    placeholder="CONNECT LIVE"
                                    autoComplete="off"
                                />
                            </div>
                        )}

                        <Button
                            type="submit"
                            disabled={processing || (isLive && !liveConfirmed)}
                            data-test={`nomba-connect-${mode}`}
                        >
                            {processing && <Spinner />}
                            {isLive
                                ? 'Connect live account'
                                : 'Connect Nomba account'}
                        </Button>
                    </>
                )}
            </Form>
        </div>
    );
}

function EmptyState({
    title,
    description,
}: {
    title: string;
    description: string;
}) {
    return (
        <div className="space-y-2 rounded-lg border border-dashed p-4">
            <p className="font-medium">{title}</p>
            <p className="text-sm text-muted-foreground">{description}</p>
        </div>
    );
}

function InputErrorText({ message }: { message?: string }) {
    if (!message) {
        return null;
    }

    return <p className="text-sm text-destructive">{message}</p>;
}

NombaIntegration.layout = () => ({
    breadcrumbs: [{ title: 'Nomba Integration', href: show() }],
});
