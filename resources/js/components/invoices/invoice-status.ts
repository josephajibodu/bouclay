import type { InvoiceStatus } from '@/types';

/** Shared badge label/color for an invoice's status. */
export const INVOICE_STATUS_LABEL: Record<InvoiceStatus, string> = {
    draft: 'Draft',
    open: 'Awaiting payment',
    paid: 'Paid',
    void: 'Void',
    uncollectible: 'Uncollectible',
};

export const INVOICE_STATUS_COLOR: Record<InvoiceStatus, string> = {
    draft: 'bg-zinc-400',
    open: 'bg-amber-500',
    paid: 'bg-emerald-500',
    void: 'bg-zinc-400',
    uncollectible: 'bg-red-500',
};
