import { Badge } from '@/components/ui/badge';
import type { SubscriptionStatus } from '@/types';
import { SUBSCRIPTION_STATUS_META } from './subscription-status';

/**
 * The dot + plain-language label used everywhere a subscription state appears
 * (SUBSCRIPTIONS_DESIGN §9.1). One component, one source of truth.
 */
export function SubscriptionStatusBadge({
    status,
}: {
    status: SubscriptionStatus;
}) {
    const meta = SUBSCRIPTION_STATUS_META[status];

    return (
        <Badge variant="secondary" className="gap-1.5">
            <span className={`size-1.5 rounded-full ${meta.dot}`} />
            {meta.label}
        </Badge>
    );
}
