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

export type PriceRef = { id: number; label: string };

export type Plan = {
    id: number;
    publicId: string;
    code: string | null;
    name: string;
    status: PlanStatus;
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
};
