import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import {
    PortalCard,
    PortalInteractiveCard,
    PortalProductIcon,
} from '@/components/portal/portal-card';
import { SubscriptionStatusBadge } from '@/components/subscriptions/subscription-status-badge';
import { show as subscriptionShow } from '@/routes/portal/subscriptions';
import type {
    PortalSharedProps,
    PortalSubscriptionListItem,
} from '@/types/portal';

type Props = PortalSharedProps & {
    subscriptions: PortalSubscriptionListItem[];
};

export default function PortalSubscriptionsIndex({
    token,
    business,
    subscriptions,
}: Props) {
    return (
        <>
            <Head title={`Subscriptions · ${business.name}`} />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold">Subscriptions</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Manage your plans with {business.name}.
                    </p>
                </div>

                {subscriptions.length === 0 ? (
                    <PortalCard>
                        <p className="text-sm text-muted-foreground">
                            You don&apos;t have any subscriptions yet.
                        </p>
                    </PortalCard>
                ) : (
                    <div className="space-y-3">
                        {subscriptions.map((subscription) => (
                            <Link
                                key={subscription.publicId}
                                href={subscriptionShow({
                                    token,
                                    publicId: subscription.publicId,
                                })}
                                className="block"
                            >
                                <PortalInteractiveCard className="flex items-center justify-between gap-4">
                                <div className="flex items-center gap-4">
                                    <PortalProductIcon
                                        name={subscription.productName}
                                    />
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="font-semibold">
                                                {subscription.productName}
                                            </p>
                                            <SubscriptionStatusBadge
                                                status={subscription.status}
                                            />
                                        </div>
                                        {subscription.priceLabel && (
                                            <p className="mt-0.5 text-sm text-muted-foreground">
                                                {subscription.priceLabel}
                                            </p>
                                        )}
                                    </div>
                                </div>
                                <ChevronRight className="size-5 text-muted-foreground" />
                                </PortalInteractiveCard>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
