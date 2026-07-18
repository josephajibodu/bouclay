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

/** One step of the item's Pricing Journey schedule, resolved to real dates. */
export type SubscriptionScheduleStep = {
    id: number;
    sequence: number;
    priceLabel: string;
    unitAmount: number | null;
    currency: string;
    startsAt: string;
    endsAt: string | null;
    isTerminal: boolean;
};

/** The item's Pricing-Journey-derived schedule, when it's on one (schema.md §5). */
export type SubscriptionItemSchedule = {
    id: number;
    publicId: string;
    endBehavior: 'release' | 'cancel';
    status: 'active' | 'completed' | 'canceled';
    currentStepId: number | null;
    steps: SubscriptionScheduleStep[];
};

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
    schedule: SubscriptionItemSchedule | null;
};

export type SubscriptionScheduledChange = {
    id?: number;
    action: string;
    effectiveAt: string;
    description?: string;
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

/** An active Pricing Journey offered as an alternative to a flat price for
 * this product's base plan line (schema.md §3/§5). */
export type CreatePricingJourneyOption = {
    id: number;
    name: string;
    /** Auto-generated one-line summary, e.g. "$1/mo for 3 months, then $10/mo". */
    description: string;
    currency: string;
};

export type CreateProductOption = {
    id: number;
    name: string;
    prices: CreatePriceOption[];
    pricingJourneys: CreatePricingJourneyOption[];
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

