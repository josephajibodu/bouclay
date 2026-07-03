import { Head, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import CreateApiKeyDrawer from '@/components/create-api-key-drawer';
import RevealApiKeyModal from '@/components/reveal-api-key-modal';
import RevokeApiKeyModal from '@/components/revoke-api-key-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatRelativeTime } from '@/lib/utils';
import { index as apiKeysIndex } from '@/routes/developers/api-keys';
import type { ApiKey, ApiKeyMode, GeneratedApiKey } from '@/types';

type Props = {
    keys: ApiKey[];
    canManage: boolean;
    liveNombaConnected: boolean;
};

export default function ApiKeys({ keys, canManage, liveNombaConnected }: Props) {
    const { currentTeam } = usePage().props;
    const [activeTab, setActiveTab] = useState<ApiKeyMode>('test');
    const [createOpen, setCreateOpen] = useState(false);
    const [generatedKey, setGeneratedKey] = useState<GeneratedApiKey | null>(
        null,
    );
    const [revokeTarget, setRevokeTarget] = useState<ApiKey | null>(null);

    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const key = flash?.generatedKey as GeneratedApiKey | undefined;

            if (key) {
                setGeneratedKey(key);
            }
        });
    }, []);

    if (!currentTeam) {
        return null;
    }

    const keysForMode = keys.filter((key) => key.mode === activeTab);

    return (
        <div className="mx-auto flex max-w-2xl flex-col gap-6 p-4">
            <Head title="API Keys" />

            <div className="flex items-start justify-between gap-4">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold">API Keys</h1>
                    <p className="text-sm text-muted-foreground">
                        Use these to authenticate requests to the Bouclay
                        API.
                    </p>
                </div>

                {canManage && keys.length > 0 && (
                    <CreateApiKeyDrawer
                        currentTeamSlug={currentTeam.slug}
                        liveNombaConnected={liveNombaConnected}
                        open={createOpen}
                        onOpenChange={setCreateOpen}
                    >
                        <Button data-test="create-api-key-trigger">
                            <Plus /> Create key
                        </Button>
                    </CreateApiKeyDrawer>
                )}
            </div>

            {keys.length === 0 ? (
                <EmptyState
                    canManage={canManage}
                    currentTeamSlug={currentTeam.slug}
                    liveNombaConnected={liveNombaConnected}
                    open={createOpen}
                    onOpenChange={setCreateOpen}
                />
            ) : (
                <Tabs
                    value={activeTab}
                    onValueChange={(value) =>
                        setActiveTab(value as ApiKeyMode)
                    }
                >
                    <TabsList>
                        <TabsTrigger value="test" data-test="api-keys-tab-test">
                            Test
                        </TabsTrigger>
                        <TabsTrigger value="live" data-test="api-keys-tab-live">
                            Live
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value={activeTab} className="mt-6">
                        {keysForMode.length === 0 ? (
                            <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                No {activeTab} keys yet.
                            </p>
                        ) : (
                            <div className="space-y-3">
                                {keysForMode.map((key) => (
                                    <ApiKeyRow
                                        key={key.id}
                                        apiKey={key}
                                        canManage={canManage}
                                        onRevoke={() => setRevokeTarget(key)}
                                    />
                                ))}
                            </div>
                        )}
                    </TabsContent>
                </Tabs>
            )}

            <RevealApiKeyModal
                key={generatedKey?.id ?? 'none'}
                generatedKey={generatedKey}
                onClose={() => setGeneratedKey(null)}
            />

            <RevokeApiKeyModal
                currentTeamSlug={currentTeam.slug}
                apiKey={revokeTarget}
                open={revokeTarget !== null}
                onOpenChange={(open) => !open && setRevokeTarget(null)}
            />
        </div>
    );
}

function ApiKeyRow({
    apiKey,
    canManage,
    onRevoke,
}: {
    apiKey: ApiKey;
    canManage: boolean;
    onRevoke: () => void;
}) {
    const prefix = apiKey.kind === 'publishable' ? 'pk' : 'sk';
    const revoked = apiKey.revokedAt !== null;

    return (
        <div
            className="flex items-center justify-between gap-4 rounded-lg border p-4"
            data-test="api-key-row"
        >
            <div className="space-y-1">
                <div className="flex items-center gap-2">
                    <span className="font-medium">{apiKey.name}</span>
                    <Badge variant="secondary">
                        {apiKey.kind === 'publishable'
                            ? 'Publishable'
                            : 'Secret'}
                    </Badge>
                    {revoked && <Badge variant="destructive">Revoked</Badge>}
                </div>
                <div className="font-mono text-sm text-muted-foreground">
                    {prefix}_{apiKey.mode}_••••••••{apiKey.lastFour}
                </div>
                <div className="text-sm text-muted-foreground">
                    {apiKey.creatorName
                        ? `Created by ${apiKey.creatorName}`
                        : 'Created'}
                    {apiKey.createdAt &&
                        ` · ${formatRelativeTime(apiKey.createdAt)}`}
                    {' · '}
                    {apiKey.lastUsedAt
                        ? `Last used ${formatRelativeTime(apiKey.lastUsedAt)}`
                        : 'Never used'}
                </div>
            </div>

            {canManage && !revoked && (
                <Button
                    variant="ghost"
                    size="sm"
                    className="text-destructive hover:text-destructive"
                    onClick={onRevoke}
                    data-test="revoke-api-key-trigger"
                >
                    Revoke
                </Button>
            )}
        </div>
    );
}

function EmptyState({
    canManage,
    currentTeamSlug,
    liveNombaConnected,
    open,
    onOpenChange,
}: {
    canManage: boolean;
    currentTeamSlug: string;
    liveNombaConnected: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <div className="space-y-4 rounded-lg border border-dashed p-6">
            <div className="space-y-1">
                <p className="font-medium">No API keys yet</p>
                <p className="text-sm text-muted-foreground">
                    Publishable keys are safe for client-side code — your
                    storefront, checkout page, mobile app.
                </p>
                <p className="text-sm text-muted-foreground">
                    Secret keys authenticate server-to-server calls — never
                    ship one to a browser or commit one to a repo.
                </p>
            </div>

            {canManage && (
                <CreateApiKeyDrawer
                    currentTeamSlug={currentTeamSlug}
                    liveNombaConnected={liveNombaConnected}
                    open={open}
                    onOpenChange={onOpenChange}
                >
                    <Button data-test="create-first-api-key">
                        <Plus /> Create your first key
                    </Button>
                </CreateApiKeyDrawer>
            )}
        </div>
    );
}

ApiKeys.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'API Keys',
            href: props.currentTeam
                ? apiKeysIndex(props.currentTeam.slug)
                : '/',
        },
    ],
});
