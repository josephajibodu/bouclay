export type ApiKeyMode = 'test' | 'live';
export type ApiKeyKind = 'publishable' | 'secret';

export type NombaModeStatus = {
    connected: boolean;
    connectedAt: string | null;
    accountIdPreview: string | null;
    subaccountIdPreview: string | null;
    clientIdPreview: string | null;
    webhookSecretSet: boolean;
};

export type NombaConnection = {
    test: NombaModeStatus;
    live: NombaModeStatus;
};

export type ApiKey = {
    id: number;
    name: string;
    mode: ApiKeyMode;
    kind: ApiKeyKind;
    lastFour: string | null;
    creatorName: string | null;
    createdAt: string | null;
    lastUsedAt: string | null;
    revokedAt: string | null;
};

export type GeneratedApiKey = {
    id: number;
    name: string;
    key: string;
};

export type WebhookConnection = {
    inboundUrl: string;
    reachable: boolean;
    verifiedAt: string | null;
    testSecretSet: boolean;
    liveSecretSet: boolean;
};
