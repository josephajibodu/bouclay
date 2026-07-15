import { Form, Head, router } from '@inertiajs/react';
import { Check, Copy, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import AutofillGuard from '@/components/autofill-guard';
import RevealWebhookSecretModal from '@/components/reveal-webhook-secret-modal';
import RotateWebhookModal from '@/components/rotate-webhook-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatRelativeTime } from '@/lib/utils';
import {
    destroy as destroyEndpoint,
    rotateSecret,
    store as storeEndpoint,
    update as updateEndpoint,
} from '@/routes/developers/webhooks/endpoints';
import { secret, show, test } from '@/routes/developers/webhooks';
import type {
    GeneratedWebhookSecret,
    OutboundWebhookEndpoint,
    WebhookConnection,
    WebhookDeliveryLogEntry,
} from '@/types';

type Props = {
    endpoints: OutboundWebhookEndpoint[];
    deliveries: WebhookDeliveryLogEntry[];
    connection: WebhookConnection | null;
    canManage: boolean;
};

export default function Webhooks({
    endpoints,
    deliveries,
    connection,
    canManage,
}: Props) {
    const [activeTab, setActiveTab] = useState('endpoints');
    const [rotateOpen, setRotateOpen] = useState(false);
    const [generatedSecret, setGeneratedSecret] =
        useState<GeneratedWebhookSecret | null>(null);

    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const secretFlash = flash?.generatedWebhookSecret as
                | GeneratedWebhookSecret
                | undefined;

            if (secretFlash) {
                setGeneratedSecret(secretFlash);
            }
        });
    }, []);

    return (
        <div className="flex max-w-3xl flex-col gap-6 p-4">
            <Head title="Webhooks" />

            <div className="space-y-1">
                <h1 className="text-2xl font-semibold">Webhooks</h1>
                <p className="text-sm text-muted-foreground">
                    Receive billing events at your server, and configure the
                    payment notifications your gateway sends Bouclay.
                </p>
            </div>

            <Tabs value={activeTab} onValueChange={setActiveTab}>
                <TabsList>
                    <TabsTrigger
                        value="endpoints"
                        data-test="webhooks-tab-endpoints"
                    >
                        Your endpoints
                    </TabsTrigger>
                    <TabsTrigger
                        value="gateway-inbound"
                        data-test="webhooks-tab-gateway-inbound"
                    >
                        {connection?.gatewayLabel ?? 'Gateway'} inbound
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="endpoints" className="mt-6 space-y-6">
                    <OutboundEndpointsTab
                        endpoints={endpoints}
                        deliveries={deliveries}
                        canManage={canManage}
                    />
                </TabsContent>

                <TabsContent value="gateway-inbound" className="mt-6">
                    <GatewayInboundTab
                        connection={connection}
                        canManage={canManage}
                        rotateOpen={rotateOpen}
                        onRotateOpenChange={setRotateOpen}
                    />
                </TabsContent>
            </Tabs>

            <RevealWebhookSecretModal
                key={generatedSecret?.id ?? 'none'}
                generatedSecret={generatedSecret}
                onClose={() => setGeneratedSecret(null)}
            />
        </div>
    );
}

function OutboundEndpointsTab({
    endpoints,
    deliveries,
    canManage,
}: {
    endpoints: OutboundWebhookEndpoint[];
    deliveries: WebhookDeliveryLogEntry[];
    canManage: boolean;
}) {
    return (
        <>
            <div className="space-y-2">
                <Label>What Bouclay sends you</Label>
                <p className="text-sm text-muted-foreground">
                    Register a URL on your server. Bouclay POSTs signed billing
                    events like <code>invoice.paid</code> and{' '}
                    <code>subscription.updated</code> when things change.
                </p>
            </div>

            {canManage && (
                <Form
                    {...storeEndpoint.form()}
                    resetOnSuccess
                    className="flex items-end gap-2"
                >
                    {({ processing }) => (
                        <>
                            <AutofillGuard />
                            <div className="flex-1 space-y-2">
                                <Label htmlFor="endpoint-url">Endpoint URL</Label>
                                <Input
                                    id="endpoint-url"
                                    name="url"
                                    type="url"
                                    required
                                    placeholder="https://example.com/webhooks/bouclay"
                                    data-test="outbound-webhook-url-input"
                                />
                            </div>
                            <Button
                                type="submit"
                                disabled={processing}
                                data-test="add-outbound-webhook"
                            >
                                {processing ? <Spinner /> : <Plus />}
                                Add endpoint
                            </Button>
                        </>
                    )}
                </Form>
            )}

            {endpoints.length === 0 ? (
                <div className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                    No outbound endpoints yet. Add a URL to start receiving
                    billing events.
                </div>
            ) : (
                <div className="space-y-3">
                    {endpoints.map((endpoint) => (
                        <OutboundEndpointRow
                            key={endpoint.id}
                            endpoint={endpoint}
                            canManage={canManage}
                        />
                    ))}
                </div>
            )}

            <div className="space-y-3">
                <Label>Recent deliveries</Label>
                {deliveries.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                        Deliveries appear here once billing events fire.
                    </p>
                ) : (
                    <div className="overflow-hidden rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/30 text-left text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Event
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Endpoint
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Attempts
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Updated
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {deliveries.map((delivery) => (
                                    <tr
                                        key={delivery.id}
                                        className="border-b last:border-b-0"
                                        data-test="webhook-delivery-row"
                                    >
                                        <td className="px-4 py-3 font-mono text-xs">
                                            {delivery.event.type}
                                        </td>
                                        <td className="max-w-[12rem] truncate px-4 py-3">
                                            {delivery.endpoint.url}
                                        </td>
                                        <td className="px-4 py-3">
                                            <DeliveryStatusBadge
                                                status={delivery.status}
                                            />
                                        </td>
                                        <td className="px-4 py-3">
                                            {delivery.attempts}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {delivery.updatedAt
                                                ? formatRelativeTime(
                                                      delivery.updatedAt,
                                                  )
                                                : '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

function OutboundEndpointRow({
    endpoint,
    canManage,
}: {
    endpoint: OutboundWebhookEndpoint;
    canManage: boolean;
}) {
    return (
        <div
            className="space-y-3 rounded-lg border p-4"
            data-test="outbound-webhook-endpoint-row"
        >
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0 space-y-1">
                    <div className="flex items-center gap-2">
                        <code className="block truncate text-sm">
                            {endpoint.url}
                        </code>
                        <Badge
                            variant={
                                endpoint.active ? 'secondary' : 'outline'
                            }
                        >
                            {endpoint.active ? 'Active' : 'Disabled'}
                        </Badge>
                    </div>
                    <p className="font-mono text-sm text-muted-foreground">
                        whsec_••••••••{endpoint.secretLastFour}
                    </p>
                    {endpoint.createdAt && (
                        <p className="text-sm text-muted-foreground">
                            Added {formatRelativeTime(endpoint.createdAt)}
                        </p>
                    )}
                </div>

                {canManage && (
                    <div className="flex shrink-0 gap-2">
                        <Form
                            {...updateEndpoint.form(endpoint.id)}
                            transform={(data) => ({
                                ...data,
                                active: !endpoint.active,
                            })}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                    disabled={processing}
                                    data-test={`toggle-endpoint-${endpoint.id}`}
                                >
                                    {endpoint.active ? 'Disable' : 'Enable'}
                                </Button>
                            )}
                        </Form>
                        <Form
                            {...rotateSecret.form(endpoint.id)}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                    disabled={processing}
                                    data-test={`rotate-endpoint-secret-${endpoint.id}`}
                                >
                                    Rotate secret
                                </Button>
                            )}
                        </Form>
                        <Form {...destroyEndpoint.form(endpoint.id)}>
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="ghost"
                                    size="sm"
                                    className="text-destructive hover:text-destructive"
                                    disabled={processing}
                                    data-test={`delete-endpoint-${endpoint.id}`}
                                >
                                    Remove
                                </Button>
                            )}
                        </Form>
                    </div>
                )}
            </div>
        </div>
    );
}

function DeliveryStatusBadge({
    status,
}: {
    status: WebhookDeliveryLogEntry['status'];
}) {
    const label =
        status === 'succeeded'
            ? 'Delivered'
            : status === 'pending'
              ? 'Pending'
              : 'Failed';

    const variant =
        status === 'succeeded'
            ? 'secondary'
            : status === 'pending'
              ? 'outline'
              : 'destructive';

    return <Badge variant={variant}>{label}</Badge>;
}

function GatewayInboundTab({
    connection,
    canManage,
    rotateOpen,
    onRotateOpenChange,
}: {
    connection: WebhookConnection | null;
    canManage: boolean;
    rotateOpen: boolean;
    onRotateOpenChange: (open: boolean) => void;
}) {
    return (
        <>
            <div className="space-y-1">
                <p className="text-sm text-muted-foreground">
                    {connection
                        ? `${connection.gatewayLabel} sends payment events here. Bouclay verifies every one before acting on it.`
                        : 'Your payment gateway sends payment events here. Bouclay verifies every one before acting on it.'}
                </p>
            </div>

            {!connection ? (
                <div className="space-y-2 rounded-lg border border-dashed p-6">
                    <p className="font-medium">No webhook configured</p>
                    <p className="text-sm text-muted-foreground">
                        Your webhook URL appears once a payment gateway is
                        connected — it's how payment outcomes reach Bouclay in
                        real time.
                    </p>
                </div>
            ) : (
                <>
                    <div className="space-y-2">
                        <Label>Inbound webhook URL</Label>
                        <CopyableUrl url={connection.inboundUrl} />
                        <p className="text-sm text-muted-foreground">
                            Paste this into your {connection.gatewayLabel}{' '}
                            dashboard's webhook settings.
                        </p>
                    </div>

                    <div className="flex items-center justify-between rounded-lg border p-4">
                        <div className="flex items-center gap-2">
                            <span
                                className={`flex size-2 rounded-full ${connection.reachable ? 'bg-emerald-500' : 'bg-muted-foreground'}`}
                            />
                            <span className="text-sm font-medium">
                                {connection.reachable
                                    ? 'Reachable'
                                    : 'Not yet verified'}
                            </span>
                            {connection.reachable && connection.verifiedAt && (
                                <span className="text-sm text-muted-foreground">
                                    · last confirmed{' '}
                                    {formatRelativeTime(connection.verifiedAt)}
                                </span>
                            )}
                        </div>

                        {canManage && (
                            <Form {...test.form()}>
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="sm"
                                        disabled={processing}
                                        data-test="send-test-event"
                                    >
                                        {processing && <Spinner />}
                                        Send test event
                                    </Button>
                                )}
                            </Form>
                        )}
                    </div>

                    {/* Whether there's a secret to configure at all is the
                        driver's answer — a gateway that signs with credentials
                        it already holds has nothing to ask for here. */}
                    {connection.signingSecretField ? (
                        <div className="space-y-3">
                            <Label>
                                {connection.signingSecretField.label} per
                                environment
                            </Label>
                            <p className="text-sm text-muted-foreground">
                                {connection.signingSecretField.help ??
                                    `The value you set on ${connection.gatewayLabel}'s dashboard for each environment — Bouclay uses it to verify events genuinely came from ${connection.gatewayLabel}.`}
                            </p>
                            <SigningSecretRow
                                mode="test"
                                secretSet={connection.testSecretSet}
                                canManage={canManage}
                                fieldLabel={connection.signingSecretField.label}
                                gatewayLabel={connection.gatewayLabel}
                            />
                            <SigningSecretRow
                                mode="live"
                                secretSet={connection.liveSecretSet}
                                canManage={canManage}
                                fieldLabel={connection.signingSecretField.label}
                                gatewayLabel={connection.gatewayLabel}
                            />
                        </div>
                    ) : (
                        <div className="space-y-1 rounded-lg border bg-muted/30 p-4">
                            <p className="text-sm font-medium">
                                No signing secret needed
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {connection.gatewayLabel} signs events with the
                                credentials you already connected, so there's
                                nothing to configure here.
                            </p>
                        </div>
                    )}

                    {canManage && (
                        <div className="flex items-center justify-between rounded-lg border p-4">
                            <div>
                                <p className="font-medium">Rotate endpoint</p>
                                <p className="text-sm text-muted-foreground">
                                    Generates a new URL. Update it in your{' '}
                                    {connection.gatewayLabel} dashboard
                                    immediately, or events will stop arriving.
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => onRotateOpenChange(true)}
                                data-test="rotate-webhook-trigger"
                            >
                                Rotate
                            </Button>
                        </div>
                    )}

                    <RotateWebhookModal
                        open={rotateOpen}
                        onOpenChange={onRotateOpenChange}
                    />
                </>
            )}
        </>
    );
}

function CopyableUrl({ url }: { url: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        await navigator.clipboard.writeText(url);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 2000);
    };

    return (
        <div className="flex items-center gap-2 rounded-lg border bg-muted/30 p-3">
            <code className="flex-1 truncate font-mono text-sm">{url}</code>
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={copy}
                data-test="copy-webhook-url"
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
    );
}

function SigningSecretRow({
    mode,
    secretSet,
    canManage,
    fieldLabel,
    gatewayLabel,
}: {
    mode: 'test' | 'live';
    secretSet: boolean;
    canManage: boolean;
    fieldLabel: string;
    gatewayLabel: string;
}) {
    const [editing, setEditing] = useState(!secretSet);

    if (!canManage) {
        return (
            <div className="flex items-center justify-between rounded-lg border p-3 text-sm">
                <span className="capitalize">{mode}</span>
                <span className="text-muted-foreground">
                    {secretSet ? 'Saved' : 'Not set'}
                </span>
            </div>
        );
    }

    if (!editing) {
        return (
            <div className="flex items-center justify-between rounded-lg border p-3">
                <div className="flex items-center gap-2">
                    <span className="text-sm font-medium capitalize">
                        {mode}
                    </span>
                    <span className="font-mono text-sm text-muted-foreground">
                        ••••••••••••••••
                    </span>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => setEditing(true)}
                    data-test={`replace-webhook-secret-${mode}`}
                >
                    Replace
                </Button>
            </div>
        );
    }

    return (
        <Form
            {...secret.form()}
            transform={(data) => ({ ...data, mode })}
            resetOnSuccess
            onSuccess={() => setEditing(false)}
            className="flex items-center gap-2 rounded-lg border p-3"
        >
            {({ processing }) => (
                <>
                    <AutofillGuard />

                    <span className="text-sm font-medium capitalize">
                        {mode}
                    </span>
                    <Input
                        name="secret"
                        type="password"
                        required
                        autoComplete="new-password"
                        placeholder={`Paste the ${fieldLabel.toLowerCase()} from ${gatewayLabel}`}
                        className="flex-1"
                        data-test={`webhook-secret-input-${mode}`}
                    />
                    <Button
                        type="submit"
                        size="sm"
                        disabled={processing}
                        data-test={`save-webhook-secret-${mode}`}
                    >
                        {processing && <Spinner />}
                        Save
                    </Button>
                    {secretSet && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setEditing(false)}
                        >
                            Cancel
                        </Button>
                    )}
                </>
            )}
        </Form>
    );
}

Webhooks.layout = () => ({
    breadcrumbs: [{ title: 'Webhooks', href: show() }],
});
