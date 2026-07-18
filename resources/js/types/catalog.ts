export type CatalogStatus = 'active' | 'archived';
export type PlanStatus = 'draft' | 'active' | 'archived';
export type PriceType = 'recurring' | 'one_time';
export type PricingModel = 'standard' | 'graduated';
export type BillingInterval = 'day' | 'week' | 'month' | 'year';
export type TrialUnit = 'day' | 'week' | 'month';
export type TaxMode = 'inclusive' | 'exclusive' | 'account';

export type PriceTier = {
    id: number;
    tierIndex: number;
    upTo: number | null;
    unitAmount: number;
    flatAmount: number | null;
};

export type Price = {
    id: number;
    publicId: string;
    planId: number | null;
    name: string | null;
    type: PriceType;
    pricingModel: PricingModel;
    unitAmount: number | null;
    currency: string;
    billingInterval: BillingInterval | null;
    billingFrequency: number;
    taxMode: TaxMode;
    status: CatalogStatus;
    version: number;
    purchasable: boolean;
    trialLength: number | null;
    trialUnit: TrialUnit | null;
    trialRequiresPaymentInfo: boolean;
    trialOncePerCustomer: boolean;
    hasBeenUsed: boolean;
    customData: Record<string, string> | null;
    paymentLink: { id: string; url: string; priceLabel: string } | null;
    createdAt: string | null;
    tiers: PriceTier[];
};

/** One step of a Pricing Journey — always charges a real, existing price. */
export type PricingJourneyStep = {
    id: number;
    sequence: number;
    priceId: number;
    priceLabel: string;
    priceUnitAmount: number | null;
    currency: string;
    quantity: number;
    durationInterval: BillingInterval | null;
    durationCount: number | null;
    /** `duration*` both null — the "forever" step, always last. */
    isTerminal: boolean;
};

/**
 * A reusable, Product-scoped commercial offer template — schema table
 * `price_phases`, UI label "Pricing Journey". Copied into a customer-owned
 * Subscription Schedule the moment a subscription is created through it.
 */
export type PricingJourney = {
    id: number;
    productId: number;
    name: string;
    description: string | null;
    status: CatalogStatus;
    /** Auto-generated one-line summary, e.g. "$1/mo for 3 months, then $10/mo". */
    autoDescription: string | null;
    steps: PricingJourneyStep[];
    createdAt: string | null;
};

export type PriceRef = { id: number; label: string };

export type Plan = {
    id: number;
    publicId: string;
    code: string | null;
    name: string;
    status: PlanStatus;
    /** Entitlements this plan grants its subscribers. */
    entitlementIds: number[];
};

export type OtherProduct = {
    id: number;
    name: string;
    prices: PriceRef[];
};

export type CatalogProduct = {
    id: number;
    name: string;
    description: string | null;
    category: string | null;
    status: CatalogStatus;
    createdAt: string | null;
    prices: Price[];
};

export type ProductFilters = {
    search: string;
    status: 'all' | CatalogStatus;
    category: string;
};

export type ProductDetail = {
    id: number;
    publicId: string;
    name: string;
    description: string | null;
    category: string | null;
    websiteUrl: string | null;
    status: CatalogStatus;
    customData: Record<string, string> | null;
    createdAt: string | null;
    /** Entitlements this product grants across all its plans. */
    entitlementIds: number[];
};

/** An entitlement as the grants editor on a plan/product page sees it. */
export type GrantableEntitlement = {
    id: number;
    code: string;
    name: string;
};

/** One plan/product that grants an entitlement (IMPLEMENTATION_V2 §V2-5). */
export type CatalogEntitlementGrant = {
    id: number;
    /** The enforced morph alias — `plan` or `product`, never a class name. */
    grantorType: string;
    grantorId: number;
    grantorName: string;
};

export type CatalogEntitlement = {
    id: number;
    publicId: string;
    /** What application code gates on. Immutable once created. */
    code: string;
    name: string;
    description: string | null;
    grants: CatalogEntitlementGrant[];
};

/** The plans and products a team can grant entitlements from. */
export type EntitlementGrantors = {
    plans: { id: number; name: string }[];
    products: { id: number; name: string }[];
};
