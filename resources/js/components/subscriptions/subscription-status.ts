import type { SubscriptionStatus } from '@/types';

type StatusMeta = {
    label: string;
    /** Tailwind background class for the badge dot. */
    dot: string;
    description: string;
};

/**
 * The single client-side source of truth for how a subscription status reads —
 * mirrors App\Enums\SubscriptionStatus (SUBSCRIPTIONS_DESIGN §4, §9). Users
 * never see the raw enum value.
 */
export const SUBSCRIPTION_STATUS_META: Record<SubscriptionStatus, StatusMeta> =
    {
        incomplete: {
            label: 'Awaiting payment',
            dot: 'bg-amber-500',
            description:
                "The first payment hasn't gone through yet. Access shouldn't be granted until it does.",
        },
        incomplete_expired: {
            label: 'Expired',
            dot: 'bg-zinc-400',
            description:
                'The first payment was never completed, so this subscription never started.',
        },
        trialing: {
            label: 'On trial',
            dot: 'bg-blue-500',
            description:
                'Currently on a free trial. No payment has been taken yet.',
        },
        active: {
            label: 'Active',
            dot: 'bg-emerald-500',
            description: 'Billing on schedule. The customer has access.',
        },
        past_due: {
            label: 'Past due',
            dot: 'bg-red-500',
            description: 'A renewal payment failed. Bouclay is retrying.',
        },
        paused: {
            label: 'Paused',
            dot: 'bg-violet-500',
            description:
                'Billing is paused. No charges will be made while paused.',
        },
        canceled: {
            label: 'Canceled',
            dot: 'bg-zinc-400',
            description: 'This subscription has ended. No further charges.',
        },
    };
