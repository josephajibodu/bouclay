import { Form, Head } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import AutofillGuard from '@/components/autofill-guard';
import DisconnectGatewayModal from '@/components/disconnect-gateway-modal';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatRelativeTime } from '@/lib/utils';
import {
    connect,
    index as gatewaysIndex,
    show,
    test,
} from '@/routes/developers/gateways';
import type {
    ApiKeyMode,
    GatewayConfigField,
    GatewayConnection,
    GatewayManifest,
    GatewayModeStatus,
} from '@/types';

type Props = {
    processor: string;
    manifest: GatewayManifest;
    capabilities: { currencies: string[]; refunds: boolean };
    connection: GatewayConnection;
    canManage: boolean;
};

/**
 * The connect page for any gateway. Every field below comes from the driver's
 * configSchema() manifest — nothing here knows what a Nomba account ID is.
 */
export default function GatewayIntegration({
    processor,
    manifest,
    capabilities,
    connection,
    canManage,
}: Props) {
    const [activeTab, setActiveTab] = useState<ApiKeyMode>('test');
    const [disconnectMode, setDisconnectMode] = useState<ApiKeyMode | null>(
        null,
    );

    return (
        <div className="flex max-w-2xl flex-col gap-6 p-4">
            <Head title={`${manifest.label} Integration`} />

            <div className="space-y-1">
                <h1 className="text-2xl font-semibold">
                    {manifest.label} Integration
                </h1>
                <p className="text-sm text-muted-foreground">
                    Your {manifest.label} account processes payments in{' '}
                    {capabilities.currencies.join(', ')}. Bouclay never holds
                    funds — everything settles directly to you.
                </p>
            </div>

            <Tabs
                value={activeTab}
                onValueChange={(value) => setActiveTab(value as ApiKeyMode)}
            >
                <TabsList>
                    <TabsTrigger value="test" data-test="gateway-tab-test">
                        Test
                    </TabsTrigger>
                    <TabsTrigger value="live" data-test="gateway-tab-live">
                        Live
                    </TabsTrigger>
                </TabsList>

                {(['test', 'live'] as const).map((mode) => (
                    <TabsContent key={mode} value={mode} className="mt-6">
                        <ModePanel
                            processor={processor}
                            manifest={manifest}
                            mode={mode}
                            status={connection[mode]}
                            canManage={canManage}
                            onDisconnect={() => setDisconnectMode(mode)}
                        />
                    </TabsContent>
                ))}
            </Tabs>

            <div className="rounded-lg border bg-muted/30 p-4 text-sm">
                <p className="font-medium">Why we ask for this</p>
                <p className="mt-1 text-muted-foreground">
                    Bouclay uses your {manifest.label} credentials to tokenise
                    cards, charge customers, and process refunds — on your
                    behalf, under your account. We encrypt these at rest and
                    never display your secrets again after you save them.
                </p>
                {manifest.docsUrl && (
                    <a
                        className="mt-2 inline-block underline underline-offset-4"
                        href={manifest.docsUrl}
                        target="_blank"
                        rel="noreferrer"
                    >
                        Where to find these in {manifest.label}
                    </a>
                )}
            </div>

            <DisconnectGatewayModal
                processor={processor}
                label={manifest.label}
                mode={disconnectMode}
                open={disconnectMode !== null}
                onOpenChange={(open) => !open && setDisconnectMode(null)}
            />
        </div>
    );
}

type ModePanelProps = {
    processor: string;
    manifest: GatewayManifest;
    mode: ApiKeyMode;
    status: GatewayModeStatus;
    canManage: boolean;
    onDisconnect: () => void;
};

function ModePanel(props: ModePanelProps) {
    if (props.status.connected) {
        return <ConnectedCard {...props} />;
    }

    return (
        <ConnectForm
            processor={props.processor}
            manifest={props.manifest}
            mode={props.mode}
            canManage={props.canManage}
        />
    );
}

function ConnectedCard({
    processor,
    manifest,
    mode,
    status,
    canManage,
    onDisconnect,
}: ModePanelProps) {
    return (
        <div
            className="space-y-4 rounded-lg border p-4"
            data-test={`gateway-connected-${mode}`}
        >
            <div className="flex items-center gap-2">
                <span className="flex size-2 rounded-full bg-emerald-500" />
                <span className="font-medium">Connected</span>
            </div>

            <dl className="grid gap-1 text-sm text-muted-foreground">
                {manifest.fields.map((field) => (
                    <FieldSummary
                        key={field.key}
                        field={field}
                        status={status.fields[field.key]}
                    />
                ))}
                {status.connectedAt && (
                    <div>Verified {formatRelativeTime(status.connectedAt)}</div>
                )}
            </dl>

            {canManage && (
                <Form
                    {...test.form({ processor })}
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
                                data-test={`gateway-test-connection-${mode}`}
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
                                data-test={`gateway-disconnect-${mode}`}
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

/**
 * A saved field, described without revealing it: a secret reports only that
 * it's set, anything else shows its last four characters.
 */
function FieldSummary({
    field,
    status,
}: {
    field: GatewayConfigField;
    status?: { set: boolean; preview: string | null };
}) {
    if (!status?.set) {
        if (!field.required) {
            return null;
        }

        return <div>{field.label} not set</div>;
    }

    if (field.secret) {
        return <div>{field.label} saved</div>;
    }

    return (
        <div>
            {field.label} ending in{' '}
            <span className="font-mono">•••• {status.preview}</span>
        </div>
    );
}

function ConnectForm({
    processor,
    manifest,
    mode,
    canManage,
}: {
    processor: string;
    manifest: GatewayManifest;
    mode: ApiKeyMode;
    canManage: boolean;
}) {
    const [liveConfirmation, setLiveConfirmation] = useState('');
    const isLive = mode === 'live';
    const liveConfirmed = liveConfirmation === 'CONNECT LIVE';

    const title = isLive
        ? `No live ${manifest.label} account connected`
        : `Connect ${manifest.label} to start accepting payments`;

    if (!canManage) {
        return (
            <EmptyState
                title={title}
                description={`Ask a teammate with integrations access to connect ${manifest.label} for this business.`}
            />
        );
    }

    return (
        <div className="space-y-6">
            <EmptyState
                title={title}
                description={
                    isLive
                        ? `Once you're ready to accept real payments, connect your live ${manifest.label} credentials here.`
                        : `Bouclay is the billing engine — ${manifest.label} is the payment processor. Every charge, refund, and payout happens on your ${manifest.label} account. Start with test keys — nothing here touches real funds yet.`
                }
            />

            {isLive && (
                <div className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/50 dark:text-amber-100">
                    <ShieldAlert className="mt-0.5 size-4 shrink-0" />
                    <p>
                        You're about to connect a live {manifest.label} account.
                        Real customers will be charged real money through this
                        integration once you go live.
                    </p>
                </div>
            )}

            <Form
                {...connect.form({ processor })}
                transform={(data) => ({ ...data, mode })}
                resetOnSuccess
                className="space-y-4"
            >
                {({ errors, processing }) => (
                    <>
                        <AutofillGuard />

                        {manifest.fields.map((field) => (
                            <ManifestField
                                key={field.key}
                                field={field}
                                mode={mode}
                                error={errors[field.key]}
                            />
                        ))}

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
                            data-test={`gateway-connect-${mode}`}
                        >
                            {processing && <Spinner />}
                            {isLive
                                ? 'Connect live account'
                                : `Connect ${manifest.label} account`}
                        </Button>
                    </>
                )}
            </Form>
        </div>
    );
}

/**
 * One input, rendered from the manifest. A secret field never carries a value
 * in or out — it's write-only from the browser's side.
 */
function ManifestField({
    field,
    mode,
    error,
}: {
    field: GatewayConfigField;
    mode: ApiKeyMode;
    error?: string;
}) {
    const id = `${field.key}_${mode}`;
    const InputComponent = field.secret ? PasswordInput : Input;

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>
                {field.label}
                {!field.required && ' (optional)'}
            </Label>
            <InputComponent
                id={id}
                name={field.key}
                required={field.required}
                autoComplete={field.secret ? 'new-password' : 'off'}
                placeholder={field.placeholder ?? undefined}
                data-test={`gateway-field-${field.key}`}
            />
            {field.help && (
                <p className="text-sm text-muted-foreground">{field.help}</p>
            )}
            <InputErrorText message={error} />
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

// Inertia calls this with the page's props directly, not a { props } wrapper.
GatewayIntegration.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Payment gateways', href: gatewaysIndex() },
        {
            title: props.manifest.label,
            href: show({ processor: props.processor }),
        },
    ],
});
