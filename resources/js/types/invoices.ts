export type PaymentStatus =
    | 'pending'
    | 'processing'
    | 'succeeded'
    | 'failed'
    | 'refunded';

export type InvoiceCustomerRef = {
    id: number;
    name: string | null;
    email: string;
};

/** One charge attempt against an invoice — used in subscription/customer hubs. */
export type PaymentListItem = {
    id: number;
    publicId: string;
    status: PaymentStatus;
    amount: number;
    currency: string;
    productsLabel: string;
    customer: InvoiceCustomerRef;
    paymentMethodLabel: string;
    processedAt: string | null;
    createdAt: string | null;
};

export type InvoiceFilters = {
    search: string;
    status: string;
};

export type InvoiceStatus = 'draft' | 'open' | 'paid' | 'void' | 'uncollectible';

export type InvoiceSummary = {
    id: number;
    publicId: string;
    number: string | null;
    status: InvoiceStatus;
    currency: string;
    total: number;
    amountDue: number;
    dueAt: string | null;
    paidAt: string | null;
    createdAt: string | null;
};

export type InvoiceListItem = InvoiceSummary & {
    customer: InvoiceCustomerRef;
    productsLabel: string;
    paymentMethodLabel: string;
};

export type CustomerInvoiceSummary = InvoiceSummary & {
    productsLabel: string;
};

export type InvoiceBillingAddress = {
    name: string | null;
    line1: string;
    line2: string | null;
    city: string | null;
    region: string | null;
    postalCode: string | null;
    country: string;
    phone: string | null;
    singleLine: string;
};

export type InvoiceLineDetail = {
    id: number;
    kind: string;
    description: string;
    quantity: number;
    unitAmount: number;
    subtotal: number;
    discountAmount: number;
    taxAmount: number;
    total: number;
    periodStart: string | null;
    periodEnd: string | null;
    productName: string | null;
    priceLabel: string | null;
};

export type RefundStatus = 'pending' | 'succeeded' | 'failed';

export type InvoiceRefundDetail = {
    id: number;
    publicId: string;
    status: RefundStatus;
    amount: number;
    currency: string;
    reason: string | null;
    createdAt: string | null;
};

/** What the gateway behind a charge can reverse, per its own capabilities(). */
export type RefundSupport = {
    refunds: boolean;
    partialRefunds: boolean;
    gatewayLabel: string;
};

export type InvoicePaymentDetail = {
    id: number;
    publicId: string;
    status: PaymentStatus;
    amount: number;
    currency: string;
    paymentMethodLabel: string;
    processedAt: string | null;
    failureReason: string | null;
    refundedAmount: number;
    refundableAmount: number;
    refundSupport: RefundSupport;
    refunds: InvoiceRefundDetail[];
};

/** Full shape for the invoice detail page. */
export type InvoiceDetail = InvoiceSummary & {
    billingReason: string;
    billingReasonLabel: string;
    collectionMode: 'automatic' | 'manual';
    subtotal: number;
    discountTotal: number;
    taxTotal: number;
    amountPaid: number;
    periodStart: string | null;
    periodEnd: string | null;
    finalizedAt: string | null;
    voidedAt: string | null;
    customer: InvoiceCustomerRef;
    billingAddress: InvoiceBillingAddress | null;
    subscription: { id: number; publicId: string } | null;
    business: {
        name: string;
        line1: string | null;
        line2: string | null;
        city: string | null;
        postalCode: string | null;
        country: string | null;
    };
    invoiceFooter: string | null;
    paymentMethodLabel: string;
    lines: InvoiceLineDetail[];
    payments: InvoicePaymentDetail[];
    canVoid: boolean;
    canMarkUncollectible: boolean;
};
