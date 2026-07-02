export type ApiKeyMode = 'test' | 'live';

export type NombaModeStatus = {
    connected: boolean;
    connectedAt: string | null;
    accountIdPreview: string | null;
    subaccountIdPreview: string | null;
    clientIdPreview: string | null;
};

export type NombaConnection = {
    test: NombaModeStatus;
    live: NombaModeStatus;
};
