export type ApiKeyMode = 'test' | 'live';
export type ApiKeyKind = 'publishable' | 'secret';

/** One credential field, as declared by a driver's configSchema() manifest. */
export type GatewayConfigField = {
    key: string;
    label: string;
    secret: boolean;
    required: boolean;
    help: string | null;
    placeholder: string | null;
};

export type GatewayManifest = {
    label: string;
    docsUrl: string | null;
    fields: GatewayConfigField[];
};

export type GatewayCapabilities = {
    currencies: string[];
    refunds: boolean;
};

/** What's saved for one mode. Secrets report `set` only — never a value. */
export type GatewayFieldStatus = {
    set: boolean;
    preview: string | null;
};

export type GatewayModeStatus = {
    connected: boolean;
    connectedAt: string | null;
    fields: Record<string, GatewayFieldStatus>;
};

export type GatewayConnection = {
    test: GatewayModeStatus;
    live: GatewayModeStatus;
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
