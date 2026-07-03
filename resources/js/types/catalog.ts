export type CatalogStatus = 'active' | 'archived';
export type PriceType = 'recurring' | 'one_time';
export type PricingModel = 'standard' | 'graduated';
export type BillingInterval = 'day' | 'week' | 'month' | 'year';
export type TrialDurationUnit = 'day' | 'week' | 'month';

export type PriceTier = {
    id: number;
    tierIndex: number;
    upTo: number | null;
    unitAmount: number;
    flatAmount: number | null;
};

export type PriceTrial = {
    id: number;
    durationAmount: number;
    durationUnit: TrialDurationUnit;
    oncePerCustomer: boolean;
};

export type Price = {
    id: number;
    publicId: string;
    name: string | null;
    type: PriceType;
    pricingModel: PricingModel;
    unitAmount: number | null;
    currency: string;
    billingInterval: BillingInterval | null;
    billingFrequency: number;
    status: CatalogStatus;
    tiers: PriceTier[];
    trial: PriceTrial | null;
};

export type CatalogProduct = {
    id: number;
    name: string;
    description: string | null;
    category: string | null;
    status: CatalogStatus;
    prices: Price[];
};

export type ProductDetail = {
    id: number;
    publicId: string;
    name: string;
    description: string | null;
    category: string | null;
    status: CatalogStatus;
    customData: Record<string, string> | null;
    createdAt: string | null;
};
