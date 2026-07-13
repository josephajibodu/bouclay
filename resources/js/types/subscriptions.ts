export type SubscriptionStatus =
    | 'incomplete'
    | 'incomplete_expired'
    | 'trialing'
    | 'active'
    | 'past_due'
    | 'paused'
    | 'canceled';

export type CollectionMode = 'automatic' | 'manual';

export type TrialEndBehavior = 'cancel' | 'pause' | 'create_invoice';

export type SubscriptionCustomerRef = {
    id: number;
    name: string | null;
    email: string;
};

export type SubscriptionListItem = {
    id: number;
    publicId: string;
    status: SubscriptionStatus;
    planLabel: string;
    customer: SubscriptionCustomerRef;
    trialEndsAt: string | null;
    currentPeriodEnd: string | null;
    cancelsAt: string | null;
    dunningAttempt?: number;
    dunningMaxAttempts?: number;
    dunningNextRetryAt?: string | null;
    createdAt: string | null;
};

export type SubscriptionDunning = {
    attempt: number | null;
    maxAttempts: number | null;
    nextRetryAt: string | null;
    canRetryNow: boolean;
};

export type SubscriptionFilters = {
    search: string;
    status: string;
};

export type SubscriptionItemKind = 'plan' | 'addon';

export type SubscriptionItem = {
    id: number;
    publicId: string;
    kind: SubscriptionItemKind;
    product: { id: number; name: string };
    plan: { id: number; name: string };
    price: {
        id: number;
        label: string;
        unitAmount: number | null;
        currency: string;
        billingInterval: string | null;
    };
    quantity: number;
    status: string;
    trialEndsAt: string | null;
};

export type SubscriptionScheduledChange = {
    action: string;
    effectiveAt: string;
};

export type SubscriptionTimelineEvent = {
    type: string;
    label: string;
    at: string | null;
};

// Invoice types live in ./invoices — shared by invoice list, detail, and hubs.

export type SubscriptionDetail = {
    id: number;
    publicId: string;
    status: SubscriptionStatus;
    currency: string;
    collectionMode: CollectionMode;
    trialEndsAt: string | null;
    trialEndBehavior: TrialEndBehavior | null;
    currentPeriodStart: string | null;
    currentPeriodEnd: string | null;
    pauseResumesAt: string | null;
    canceledAt: string | null;
    endsAt: string | null;
    customData: Record<string, string> | null;
    createdAt: string | null;
};

// --- Create flow (SUBSCRIPTIONS_DESIGN §7) ---

export type CreatePriceTrial = {
    label: string;
    free: boolean;
};

export type CreatePriceOption = {
    id: number;
    label: string;
    planName?: string | null;
    unitAmount: number | null;
    currency: string;
    billingInterval: string | null;
    trial?: CreatePriceTrial | null;
};

export type CreateProductOption = {
    id: number;
    name: string;
    prices: CreatePriceOption[];
};

export type CreateCustomerPaymentMethod = {
    id: number;
    label: string;
    isDefault: boolean;
};

export type CreateCustomerOption = {
    id: number;
    name: string | null;
    email: string;
    currency: string | null;
    paymentMethods: CreateCustomerPaymentMethod[];
};

