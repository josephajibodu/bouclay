export type CatalogStatus = 'active' | 'archived';
export type PriceType = 'recurring' | 'one_time';
export type PricingModel = 'standard' | 'graduated';
export type BillingInterval = 'day' | 'week' | 'month' | 'year';
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
    name: string | null;
    type: PriceType;
    pricingModel: PricingModel;
    unitAmount: number | null;
    currency: string;
    billingInterval: BillingInterval | null;
    billingFrequency: number;
    taxMode: TaxMode;
    status: CatalogStatus;
    hasBeenUsed: boolean;
    customData: Record<string, string> | null;
    paymentLink: { id: string; url: string; priceLabel: string } | null;
    createdAt: string | null;
    tiers: PriceTier[];
};

export type PriceRef = { id: number; label: string };

export type TrialOffer = {
    id: number;
    publicId: string;
    name: string;
    trialPrice: PriceRef;
    transitionToDifferentProduct: boolean;
    transitionProduct: { id: number; name: string } | null;
    transitionPrice: PriceRef;
    durationIterations: number;
    active: boolean;
    paymentLink: { id: string; url: string; priceLabel: string } | null;
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
    status: CatalogStatus;
    customData: Record<string, string> | null;
    createdAt: string | null;
};
