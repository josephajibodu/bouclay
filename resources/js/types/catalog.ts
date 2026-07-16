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

export type PricePhase = {
    id: number;
    sequence: number;
    chargePriceId: number;
    chargePriceLabel: string;
    chargePriceUnitAmount: number | null;
    durationInterval: BillingInterval;
    durationCount: number;
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
    phases: PricePhase[];
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
