import type { PaymentStatus } from '@/types';

/** Shared badge label/color for a payment (charge attempt) status — used
 * wherever a payment-attempt list is nested inside another hub (subscription,
 * customer), so the two never drift apart. */
export const PAYMENT_STATUS_LABEL: Record<PaymentStatus, string> = {
    pending: 'Pending',
    processing: 'Processing',
    succeeded: 'Completed',
    failed: 'Failed',
    refunded: 'Refunded',
};

export const PAYMENT_STATUS_COLOR: Record<PaymentStatus, string> = {
    pending: 'bg-amber-500',
    processing: 'bg-amber-500',
    succeeded: 'bg-emerald-500',
    failed: 'bg-red-500',
    refunded: 'bg-zinc-400',
};
