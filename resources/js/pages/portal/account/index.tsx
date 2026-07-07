import { Head } from '@inertiajs/react';
import { PortalCard } from '@/components/portal/portal-card';
import { formatPortalDate } from '@/lib/portal-format';
import type { PortalSharedProps } from '@/types/portal';

type Props = PortalSharedProps;

function businessAddress(business: PortalSharedProps['business']): string | null {
    const parts = [
        business.line1,
        business.line2,
        business.city,
        business.postalCode,
        business.country,
    ].filter(Boolean);

    return parts.length > 0 ? parts.join(', ') : null;
}

export default function PortalAccountIndex({
    business,
    customer,
}: Props) {
    const address = businessAddress(business);

    return (
        <>
            <Head title={`Account · ${business.name}`} />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold">Account</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Your details with {business.name}.
                    </p>
                </div>

                <PortalCard title="Your details">
                    <dl className="space-y-4 text-sm">
                        <div>
                            <dt className="text-muted-foreground">Name</dt>
                            <dd className="mt-0.5 font-medium">
                                {customer.name ?? '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Email</dt>
                            <dd className="mt-0.5 font-medium">
                                {customer.email}
                            </dd>
                        </div>
                        {customer.createdAt && (
                            <div>
                                <dt className="text-muted-foreground">
                                    Customer since
                                </dt>
                                <dd className="mt-0.5 font-medium">
                                    {formatPortalDate(customer.createdAt)}
                                </dd>
                            </div>
                        )}
                    </dl>
                </PortalCard>

                <PortalCard title={business.name}>
                    <dl className="space-y-4 text-sm">
                        {address && (
                            <div>
                                <dt className="text-muted-foreground">
                                    Address
                                </dt>
                                <dd className="mt-0.5 font-medium">{address}</dd>
                            </div>
                        )}
                        {business.website && (
                            <div>
                                <dt className="text-muted-foreground">
                                    Website
                                </dt>
                                <dd className="mt-0.5 font-medium">
                                    {business.website}
                                </dd>
                            </div>
                        )}
                    </dl>
                </PortalCard>
            </div>
        </>
    );
}
