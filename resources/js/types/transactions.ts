export type PaymentStatus =
    | 'pending'
    | 'processing'
    | 'succeeded'
    | 'failed'
    | 'refunded';

export type TransactionCustomerRef = {
    id: number;
    name: string | null;
    email: string;
};

/** One charge attempt — used for the payment-attempt lists nested inside a
 * subscription's or customer's own hub (scoped to that one object). */
export type TransactionListItem = {
    id: number;
    publicId: string;
    status: PaymentStatus;
    amount: number;
    currency: string;
    productsLabel: string;
    customer: TransactionCustomerRef;
    paymentMethodLabel: string;
    processedAt: string | null;
    createdAt: string | null;
};

export type TransactionFilters = {
    search: string;
    status: string;
};

export type InvoiceStatus = 'draft' | 'open' | 'paid' | 'void' | 'uncollectible';

/** The frozen-legal-document shape (schema.md §7) — enough for a summary
 * row; the future dedicated Invoice page will carry the full breakdown
 * (subtotal/tax/lines/billing address snapshot). */
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

/** One row on the global Transactions list — invoice-centric (Paddle's own
 * "Transactions" list is a list of invoices, not raw charge attempts), so a
 * manually-billed or not-yet-charged invoice is never invisible just
 * because no Payment row exists for it yet. */
export type InvoiceListItem = InvoiceSummary & {
    customer: TransactionCustomerRef;
    productsLabel: string;
    paymentMethodLabel: string;
};
