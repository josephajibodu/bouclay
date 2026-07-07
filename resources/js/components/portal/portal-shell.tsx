import type { ReactNode } from 'react';

type Business = {
    name: string;
    line1: string | null;
    line2: string | null;
    city: string | null;
    postalCode: string | null;
    country: string | null;
    website: string | null;
};

type Customer = {
    name: string | null;
    email: string;
};

function businessAddress(business: Business): string | null {
    const parts = [
        business.line1,
        business.line2,
        business.city,
        business.postalCode,
        business.country,
    ].filter(Boolean);

    return parts.length > 0 ? parts.join(', ') : null;
}

export function PortalShell({
    business,
    customer,
    children,
}: {
    business: Business;
    customer: Customer;
    children: ReactNode;
}) {
    const address = businessAddress(business);

    return (
        <div className="min-h-screen bg-muted/30 px-4 py-10">
            <div className="mx-auto flex max-w-2xl flex-col gap-6">
                <header className="space-y-1 text-center sm:text-left">
                    <p className="text-sm font-medium text-muted-foreground">
                        {business.name}
                    </p>
                    <h1 className="text-2xl font-semibold">Your account</h1>
                    <p className="text-sm text-muted-foreground">
                        {customer.name ?? customer.email}
                        {customer.name && (
                            <span className="text-muted-foreground/80">
                                {' '}
                                · {customer.email}
                            </span>
                        )}
                    </p>
                    {address && (
                        <p className="text-xs text-muted-foreground">{address}</p>
                    )}
                </header>

                {children}

                <p className="text-center text-xs text-muted-foreground">
                    Powered by Bouclay
                </p>
            </div>
        </div>
    );
}
