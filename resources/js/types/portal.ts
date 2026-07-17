import type { SubscriptionStatus } from '@/types';

export type PortalBusiness = {
    name: string;
    line1: string | null;
    line2: string | null;
    city: string | null;
    postalCode: string | null;
    country: string | null;
    website: string | null;
};

export type PortalCustomer = {
    name: string | null;
    email: string;
    createdAt?: string | null;
};

export type PortalPaymentMethod = {
    brand: string | null;
    last4: string | null;
    expMonth: number | null;
    expYear: number | null;
    isExpired: boolean;
};

export type PortalSharedProps = {
    token: string;
    business: PortalBusiness;
    customer: PortalCustomer;
    canUpdatePaymentMethod: boolean;
    /** Display name of the gateway new checkouts open, or null when none is connected. */
    paymentGateway: string | null;
    paymentMethod: PortalPaymentMethod | null;
    returnUrl: string | null;
};

export type PortalSubscriptionListItem = {
    publicId: string;
    status: SubscriptionStatus;
    planLabel: string;
    productName: string;
    priceLabel: string | null;
    currency: string;
    createdAt: string | null;
    trialEndsAt: string | null;
    currentPeriodEnd: string | null;
    endsAt: string | null;
    scheduledCancelAt: string | null;
    canCancel: boolean;
};

export type PortalSubscriptionDetail = PortalSubscriptionListItem & {
    collectionMode: string;
    currentPeriodStart: string | null;
    items: Array<{
        productName: string;
        priceLabel: string;
        quantity: number;
        unitAmount: number | null;
        currency: string;
        billingInterval: string | null;
        billingFrequency: number;
        type: string;
    }>;
    paymentMethod: { brand: string | null; last4: string | null } | null;
    nextPayment: {
        amount: number;
        currency: string;
        dueAt: string | null;
        subtotal: number;
        taxTotal: number;
        lines: Array<{
            description: string;
            detail: string;
            quantity: number;
            amount: number;
            currency: string;
            isRecurring: boolean;
        }>;
    };
    recentPayments: Array<{
        publicId: string;
        amount: number;
        currency: string;
        status: string;
        statusLabel: string;
        description: string;
        processedAt: string | null;
        invoicePublicId: string;
        invoicePayUrl: string;
    }>;
};

export type PortalPayment = {
    publicId: string;
    amount: number;
    currency: string;
    status: string;
    statusLabel: string;
    description: string;
    processedAt: string | null;
    invoicePublicId: string;
    invoiceNumber: string | null;
    invoicePayUrl: string;
    paymentMethodLabel: string | null;
    /** How much of this charge has been refunded, in minor units. */
    refundedAmount: number;
};

export type PortalOpenInvoice = {
    publicId: string;
    number: string | null;
    currency: string;
    amountDue: number;
    total: number;
    dueAt: string | null;
    createdAt: string | null;
    productsLabel: string;
    payUrl: string;
};
