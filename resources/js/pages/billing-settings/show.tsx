import { Form, Head } from '@inertiajs/react';
import { Gauge, Plus, ShieldAlert, TimerReset, X } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { show } from '@/routes/billing-settings';
import { update as updateDunning } from '@/routes/billing-settings/dunning';

type DunningTerminalAction = 'cancel' | 'pause' | 'leave_open';

type DunningSettings = {
    maxAttempts: number;
    retryIntervalsDays: number[];
    terminalAction: DunningTerminalAction;
    incompleteGraceDays: number;
};

type Props = {
    dunning: DunningSettings;
    canManage: boolean;
};

const TERMINAL_ACTION_LABEL: Record<DunningTerminalAction, string> = {
    cancel: 'Cancel the subscription',
    pause: 'Pause the subscription',
    leave_open: 'Leave the invoice open',
};

const MAX_RETRIES = 6;

export default function BillingSettingsShow({ dunning, canManage }: Props) {
    return (
        <div className="flex max-w-3xl flex-col gap-6 p-4">
            <Head title="Billing settings" />

            <div className="space-y-1">
                <h1 className="text-2xl font-semibold">Billing settings</h1>
                <p className="text-sm text-muted-foreground">
                    How Bouclay recovers failed subscription payments and
                    adjusts invoices for mid-cycle plan changes.
                </p>
            </div>

            <DunningCard dunning={dunning} canManage={canManage} />
            <ProrationCard />
        </div>
    );
}

function DunningCard({ dunning, canManage }: Props) {
    const [retries, setRetries] = useState<number[]>(
        dunning.retryIntervalsDays,
    );

    return (
        <Form
            {...updateDunning.form()}
            transform={(data) => ({ ...data, retry_intervals_days: retries })}
            options={{ preserveScroll: true }}
        >
            {({ errors, processing }) => (
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <TimerReset className="size-4 text-muted-foreground" />
                            <CardTitle>
                                Failed payment recovery (dunning)
                            </CardTitle>
                        </div>
                        <CardDescription>
                            When a subscription charge fails, Bouclay retries it
                            on this schedule before taking a terminal action.
                            Each retry is counted in days after the previous
                            failure.
                        </CardDescription>
                    </CardHeader>

                    <CardContent className="space-y-6 text-sm">
                        <div className="space-y-3">
                            <div className="flex items-center justify-between gap-4">
                                <Label>Retry schedule</Label>
                                <Badge variant="outline">
                                    {retries.length} retr
                                    {retries.length === 1 ? 'y' : 'ies'} ·{' '}
                                    {retries.length + 1} attempts total
                                </Badge>
                            </div>

                            <div className="flex flex-wrap items-center gap-2">
                                {retries.map((days, index) => (
                                    <RetryInput
                                        // The schedule is an ordered list of
                                        // positions, not identified rows —
                                        // index is the identity here.
                                        key={index}
                                        days={days}
                                        index={index}
                                        disabled={!canManage}
                                        onChange={(value) =>
                                            setRetries((current) =>
                                                current.map((d, i) =>
                                                    i === index ? value : d,
                                                ),
                                            )
                                        }
                                        onRemove={
                                            retries.length > 1
                                                ? () =>
                                                      setRetries((current) =>
                                                          current.filter(
                                                              (_, i) =>
                                                                  i !== index,
                                                          ),
                                                      )
                                                : undefined
                                        }
                                    />
                                ))}

                                {canManage && retries.length < MAX_RETRIES && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        data-test="dunning-add-retry"
                                        onClick={() =>
                                            setRetries((current) => [
                                                ...current,
                                                (current.at(-1) ?? 1) * 2,
                                            ])
                                        }
                                    >
                                        <Plus className="size-3.5" />
                                        Add retry
                                    </Button>
                                )}
                            </div>

                            <InputError
                                message={
                                    errors.retry_intervals_days ??
                                    errors['retry_intervals_days.0']
                                }
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="terminal_action">
                                If every retry fails
                            </Label>
                            <Select
                                name="terminal_action"
                                defaultValue={dunning.terminalAction}
                                disabled={!canManage}
                            >
                                <SelectTrigger
                                    id="terminal_action"
                                    data-test="dunning-terminal-action"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {Object.entries(TERMINAL_ACTION_LABEL).map(
                                        ([value, label]) => (
                                            <SelectItem
                                                key={value}
                                                value={value}
                                            >
                                                {label}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.terminal_action} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="incomplete_grace_days">
                                Grace period for incomplete subscriptions
                            </Label>
                            <Input
                                id="incomplete_grace_days"
                                name="incomplete_grace_days"
                                type="number"
                                min={1}
                                max={60}
                                className="w-32"
                                defaultValue={dunning.incompleteGraceDays}
                                disabled={!canManage}
                                data-test="dunning-grace-days"
                            />
                            <p className="text-muted-foreground">
                                How long a subscription may sit unpaid after
                                sign-up before it expires.
                            </p>
                            <InputError message={errors.incomplete_grace_days} />
                        </div>

                        <div className="flex items-start gap-3 rounded-lg border bg-muted/30 p-3 text-muted-foreground">
                            <ShieldAlert className="mt-0.5 size-4 shrink-0" />
                            <p>
                                This schedule applies to{' '}
                                <span className="font-medium text-foreground">
                                    soft declines
                                </span>{' '}
                                — insufficient funds, temporary holds.{' '}
                                <span className="font-medium text-foreground">
                                    Hard declines
                                </span>{' '}
                                (stolen card, closed account) skip the retries
                                and go straight to the terminal action:
                                retrying those burns processor goodwill for a
                                0% recovery rate.
                            </p>
                        </div>
                    </CardContent>

                    {canManage && (
                        <CardFooter>
                            <Button
                                type="submit"
                                disabled={processing}
                                data-test="dunning-save"
                            >
                                {processing && <Spinner />}
                                Save dunning settings
                            </Button>
                        </CardFooter>
                    )}
                </Card>
            )}
        </Form>
    );
}

function RetryInput({
    days,
    index,
    disabled,
    onChange,
    onRemove,
}: {
    days: number;
    index: number;
    disabled: boolean;
    onChange: (value: number) => void;
    onRemove?: () => void;
}) {
    return (
        <div className="flex items-center gap-1 rounded-md border px-2 py-1">
            <span className="text-xs text-muted-foreground">#{index + 1}</span>
            <Input
                type="number"
                min={0}
                max={30}
                value={days}
                disabled={disabled}
                onChange={(event) => onChange(Number(event.target.value))}
                className="h-7 w-14 border-0 px-1 text-center shadow-none focus-visible:ring-0"
                data-test={`dunning-retry-${index}`}
                aria-label={`Retry ${index + 1}, days after previous failure`}
            />
            <span className="text-xs text-muted-foreground">d</span>
            {!disabled && onRemove && (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-6"
                    onClick={onRemove}
                    data-test={`dunning-remove-retry-${index}`}
                    aria-label={`Remove retry ${index + 1}`}
                >
                    <X className="size-3" />
                </Button>
            )}
        </div>
    );
}

function ProrationCard() {
    return (
        <Card>
            <CardHeader>
                <div className="flex items-center justify-between gap-4">
                    <div className="flex items-center gap-2">
                        <Gauge className="size-4 text-muted-foreground" />
                        <CardTitle>Proration</CardTitle>
                    </div>
                    <Badge variant="outline">Set per change</Badge>
                </div>
                <CardDescription>
                    When a subscription's plan or quantity changes mid-cycle,
                    Bouclay adjusts the invoice according to the proration
                    behavior chosen for that change.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3 text-sm text-muted-foreground">
                <p>
                    <span className="font-medium text-foreground">
                        Defaults by direction.
                    </span>{' '}
                    An increase prorates immediately; a decrease defers to the
                    next cycle; a change during a trial applies immediately
                    with no proration.
                </p>
                <p>
                    <span className="font-medium text-foreground">
                        Time-based, to the second.
                    </span>{' '}
                    The prorated amount is calculated from the exact time
                    remaining in the billing period, not rounded to the day.
                </p>
                <p>
                    <span className="font-medium text-foreground">
                        Two invoice lines.
                    </span>{' '}
                    A credit for unused time on the old price and a charge for
                    remaining time on the new price.
                </p>
                <p>
                    <span className="font-medium text-foreground">
                        Zero-sum changes are skipped.
                    </span>{' '}
                    If the net adjustment comes out to zero, no proration line
                    is added.
                </p>
            </CardContent>
        </Card>
    );
}

BillingSettingsShow.layout = () => ({
    breadcrumbs: [{ title: 'Billing settings', href: show() }],
});
