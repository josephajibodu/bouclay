import { Head } from '@inertiajs/react';
import { Gauge, TimerReset } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { show } from '@/routes/billing-settings';

type DunningTerminalAction = 'cancel' | 'pause' | 'leave_open';

type DunningSettings = {
    maxAttempts: number;
    retryIntervalsDays: number[];
    terminalAction: DunningTerminalAction;
    incompleteGraceDays: number;
};

type Props = {
    dunning: DunningSettings;
};

const TERMINAL_ACTION_LABEL: Record<DunningTerminalAction, string> = {
    cancel: 'Cancel the subscription',
    pause: 'Pause the subscription',
    leave_open: 'Leave the invoice open',
};

function ComingSoonBadge() {
    return <Badge variant="outline">Coming soon: configurable</Badge>;
}

export default function BillingSettingsShow({ dunning }: Props) {
    return (
        <div className="flex max-w-3xl flex-col gap-6 p-4">
            <Head title="Billing settings" />

            <div className="space-y-1">
                <h1 className="text-2xl font-semibold">Billing settings</h1>
                <p className="text-sm text-muted-foreground">
                    How Bouclay recovers failed subscription payments and
                    adjusts invoices for mid-cycle plan changes. These are
                    fixed defaults today — per-business configuration is
                    planned.
                </p>
            </div>

            <Card>
                <CardHeader>
                    <div className="flex items-center justify-between gap-4">
                        <div className="flex items-center gap-2">
                            <TimerReset className="size-4 text-muted-foreground" />
                            <CardTitle>
                                Failed payment recovery (dunning)
                            </CardTitle>
                        </div>
                        <ComingSoonBadge />
                    </div>
                    <CardDescription>
                        When a subscription charge fails, Bouclay retries it
                        automatically before taking a terminal action.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4 text-sm">
                    <div className="flex items-start justify-between gap-4 border-b pb-3">
                        <span className="text-muted-foreground">
                            Retry attempts
                        </span>
                        <span className="font-medium">
                            Up to {dunning.maxAttempts}
                        </span>
                    </div>
                    <div className="flex items-start justify-between gap-4 border-b pb-3">
                        <span className="text-muted-foreground">
                            Retry schedule
                        </span>
                        <span className="font-medium">
                            {dunning.retryIntervalsDays
                                .map((days) => `${days}d`)
                                .join(' → ')}{' '}
                            after the first failure
                        </span>
                    </div>
                    <div className="flex items-start justify-between gap-4 border-b pb-3">
                        <span className="text-muted-foreground">
                            If retries are exhausted
                        </span>
                        <span className="font-medium">
                            {TERMINAL_ACTION_LABEL[dunning.terminalAction]}
                        </span>
                    </div>
                    <div className="flex items-start justify-between gap-4">
                        <span className="text-muted-foreground">
                            Grace period for incomplete subscriptions
                        </span>
                        <span className="font-medium">
                            {dunning.incompleteGraceDays} days
                        </span>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <div className="flex items-center justify-between gap-4">
                        <div className="flex items-center gap-2">
                            <Gauge className="size-4 text-muted-foreground" />
                            <CardTitle>Proration</CardTitle>
                        </div>
                        <ComingSoonBadge />
                    </div>
                    <CardDescription>
                        When a subscription's plan or quantity changes
                        mid-cycle, Bouclay adjusts the next invoice
                        automatically.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-3 text-sm text-muted-foreground">
                    <p>
                        <span className="font-medium text-foreground">
                            Always on.
                        </span>{' '}
                        Every plan or quantity change mid-cycle is prorated —
                        there's no way to opt out today.
                    </p>
                    <p>
                        <span className="font-medium text-foreground">
                            Time-based, to the second.
                        </span>{' '}
                        The prorated amount is calculated from the exact time
                        remaining in the billing period, not rounded to the
                        day.
                    </p>
                    <p>
                        <span className="font-medium text-foreground">
                            Two invoice lines.
                        </span>{' '}
                        A credit for unused time on the old price and a
                        charge for remaining time on the new price appear on
                        the next invoice.
                    </p>
                    <p>
                        <span className="font-medium text-foreground">
                            Zero-sum changes are skipped.
                        </span>{' '}
                        If the net adjustment comes out to zero, no proration
                        line is added.
                    </p>
                </CardContent>
            </Card>
        </div>
    );
}

BillingSettingsShow.layout = () => ({
    breadcrumbs: [{ title: 'Billing settings', href: show() }],
});
