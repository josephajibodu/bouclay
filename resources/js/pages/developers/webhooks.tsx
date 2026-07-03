import { Form, Head, usePage } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import AutofillGuard from '@/components/autofill-guard';
import RotateWebhookModal from '@/components/rotate-webhook-modal';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatRelativeTime } from '@/lib/utils';
import { secret, show, test } from '@/routes/developers/webhooks';
import type { ApiKeyMode, WebhookConnection } from '@/types';

type Props = {
    connection: WebhookConnection | null;
    canManage: boolean;
};

export default function Webhooks({ connection, canManage }: Props) {
    const { currentTeam } = usePage().props;
    const [rotateOpen, setRotateOpen] = useState(false);

    if (!currentTeam) {
        return null;
    }

    return (
        <div className="mx-auto flex max-w-2xl flex-col gap-6 p-4">
            <Head title="Webhooks" />

            <div className="space-y-1">
                <h1 className="text-2xl font-semibold">Webhooks</h1>
                <p className="text-sm text-muted-foreground">
                    Nomba sends payment events here. The same URL receives
                    both test and live events — Bouclay tells them apart.
                </p>
            </div>

            {!connection ? (
                <div className="space-y-2 rounded-lg border border-dashed p-6">
                    <p className="font-medium">No webhook configured</p>
                    <p className="text-sm text-muted-foreground">
                        Your webhook URL appears once Nomba is connected —
                        it's how subscription events (renewals, failed
                        payments) reach your app in real time.
                    </p>
                </div>
            ) : (
                <>
                    <div className="space-y-2">
                        <Label>Inbound webhook URL</Label>
                        <CopyableUrl url={connection.inboundUrl} />
                        <p className="text-sm text-muted-foreground">
                            Paste this into Nomba → Settings → Webhooks.
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
                            <Form
                                {...test.form(currentTeam.slug)}
                            >
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

                    <div className="space-y-3">
                        <Label>Signing secrets</Label>
                        <p className="text-sm text-muted-foreground">
                            The secret you set on Nomba's dashboard for each
                            environment — Bouclay uses it to verify events
                            genuinely came from Nomba.
                        </p>
                        <SigningSecretRow
                            mode="test"
                            secretSet={connection.testSecretSet}
                            canManage={canManage}
                            currentTeamSlug={currentTeam.slug}
                        />
                        <SigningSecretRow
                            mode="live"
                            secretSet={connection.liveSecretSet}
                            canManage={canManage}
                            currentTeamSlug={currentTeam.slug}
                        />
                    </div>

                    {canManage && (
                        <div className="flex items-center justify-between rounded-lg border p-4">
                            <div>
                                <p className="font-medium">Rotate endpoint</p>
                                <p className="text-sm text-muted-foreground">
                                    Generates a new URL. Update it in Nomba's
                                    dashboard immediately, or events will
                                    stop arriving.
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setRotateOpen(true)}
                                data-test="rotate-webhook-trigger"
                            >
                                Rotate
                            </Button>
                        </div>
                    )}

                    <RotateWebhookModal
                        currentTeamSlug={currentTeam.slug}
                        open={rotateOpen}
                        onOpenChange={setRotateOpen}
                    />
                </>
            )}
        </div>
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
    currentTeamSlug,
}: {
    mode: ApiKeyMode;
    secretSet: boolean;
    canManage: boolean;
    currentTeamSlug: string;
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
            {...secret.form(currentTeamSlug)}
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
                        placeholder="Paste the signing key from Nomba"
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

Webhooks.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Webhooks',
            href: props.currentTeam ? show(props.currentTeam.slug) : '/',
        },
    ],
});
