export type DiscountType = 'percentage' | 'flat';
export type DiscountDuration = 'once' | 'repeating' | 'forever';

export type DiscountSummary = {
    id: number;
    publicId: string;
    code: string | null;
    type: DiscountType;
    amount: number | null;
    percentage: number | null;
    currency: string | null;
    duration: DiscountDuration;
    durationInIntervals: number | null;
    maxRedemptions: number | null;
    timesRedeemed: number;
    eligiblePlanIds: number[] | null;
    eligiblePriceIds: number[] | null;
    startsAt: string | null;
    expiresAt: string | null;
    active: boolean;
    summary: string;
    createdAt: string | null;
};

export type DiscountEligibilityPlan = {
    id: number;
    name: string;
    productName: string;
};

export type DiscountEligibilityPrice = {
    id: number;
    label: string;
    planName: string | null;
};
