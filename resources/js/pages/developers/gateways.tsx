import { Form, Head, Link } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { setDefault } from '@/routes/developers/gateways';
import type { BreadcrumbItem } from '@/types';
import type { GatewaySummary } from '@/types/developers';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Developers', href: '#' },
    { title: 'Payment gateways', href: '#' },
];

export default function Gateways({
    gateways,
    canManage,
}: {
    gateways: GatewaySummary[];
    canManage: boolean;
}) {
    const connectedCount = gateways.filter(
        (gateway) => gateway.testConnected || gateway.liveConnected,
    ).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="flex max-w-3xl flex-col gap-6 p-4">
                <Head title="Payment gateways" />

                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold">Payment gateways</h1>
                    <p className="text-sm text-muted-foreground">
                        Connect your own account with any gateway below. Your
                        keys stay yours — Bouclay charges through them and never
                        holds your funds.
                    </p>
                </div>

                <div className="space-y-3">
                    {gateways.map((gateway) => (
                        <GatewayRow
                            key={gateway.processor}
                            gateway={gateway}
                            canManage={canManage}
                            canChooseDefault={connectedCount > 1}
                        />
                    ))}
                </div>

                {connectedCount > 1 && (
                    <p className="text-sm text-muted-foreground">
                        The default gateway applies to{' '}
                        <span className="font-medium">new checkouts only</span>.
                        Saved cards always charge through the gateway that
                        issued them, so changing this never moves an existing
                        subscription.
                    </p>
                )}
            </div>
        </AppLayout>
    );
}

function GatewayRow({
    gateway,
    canManage,
    canChooseDefault,
}: {
    gateway: GatewaySummary;
    canManage: boolean;
    canChooseDefault: boolean;
}) {
    const connected = gateway.testConnected || gateway.liveConnected;

    return (
        <div
            className="flex items-center justify-between gap-4 rounded-lg border p-4"
            data-test={`gateway-row-${gateway.processor}`}
        >
            <div className="space-y-1">
                <div className="flex items-center gap-2">
                    <Link
                        href={gateway.url}
                        className="font-medium hover:underline"
                        data-test={`gateway-link-${gateway.processor}`}
                    >
                        {gateway.label}
                    </Link>

                    {gateway.isDefault && connected && (
                        <Badge variant="secondary">Default</Badge>
                    )}
                </div>

                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <ModeBadge label="Test" connected={gateway.testConnected} />
                    <ModeBadge label="Live" connected={gateway.liveConnected} />
                    <span className="text-xs">
                        {gateway.currencies.slice(0, 4).join(', ')}
                        {gateway.currencies.length > 4 && '…'}
                    </span>
                </div>
            </div>

            <div className="flex shrink-0 items-center gap-2">
                {/* Only offer the choice once there's actually a choice to
                    make — a lone gateway is the default by definition. */}
                {canManage && connected && canChooseDefault && !gateway.isDefault && (
                    <Form
                        {...setDefault.form({ processor: gateway.processor })}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                disabled={processing}
                                data-test={`gateway-default-${gateway.processor}`}
                            >
                                Use for new checkouts
                            </Button>
                        )}
                    </Form>
                )}

                <Button asChild variant={connected ? 'ghost' : 'default'} size="sm">
                    <Link href={gateway.url}>
                        {connected ? 'Manage' : 'Connect'}
                    </Link>
                </Button>
            </div>
        </div>
    );
}

function ModeBadge({ label, connected }: { label: string; connected: boolean }) {
    return (
        <span className="flex items-center gap-1 text-xs">
            <span
                aria-hidden
                className={
                    connected
                        ? 'size-1.5 rounded-full bg-emerald-500'
                        : 'size-1.5 rounded-full bg-muted-foreground/40'
                }
            />
            {label} {connected ? 'connected' : 'not connected'}
        </span>
    );
}
