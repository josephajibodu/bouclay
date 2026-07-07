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

export type OutboundWebhookEndpoint = {
    id: number;
    publicId: string;
    url: string;
    active: boolean;
    secretLastFour: string;
    createdAt: string | null;
};

export type GeneratedWebhookSecret = {
    id: number;
    url: string;
    secret: string;
};

export type WebhookDeliveryLogEntry = {
    id: number;
    publicId: string;
    status: 'pending' | 'succeeded' | 'failed';
    attempts: number;
    nextAttemptAt: string | null;
    updatedAt: string | null;
    event: {
        publicId: string;
        type: string;
        createdAt: string | null;
    };
    endpoint: {
        id: number;
        publicId: string;
        url: string;
    };
};
