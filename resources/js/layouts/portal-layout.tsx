import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft, CreditCard, LayoutGrid, Receipt, User } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { useState } from 'react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { PortalContainerProvider } from '@/hooks/use-portal-container';
import { cn } from '@/lib/utils';
import { index as accountIndex } from '@/routes/portal/account';
import { index as paymentMethodsIndex } from '@/routes/portal/payment-methods';
import { index as paymentsIndex } from '@/routes/portal/payments';
import { index as subscriptionsIndex } from '@/routes/portal/subscriptions';
import type { PortalSharedProps } from '@/types/portal';

type PortalPageProps = PortalSharedProps & Record<string, unknown>;

const NAV_ITEMS = [
    {
        label: 'Subscriptions',
        icon: LayoutGrid,
        href: (token: string) => subscriptionsIndex(token),
        match: (url: string) => url.includes('/subscriptions'),
    },
    {
        label: 'Payments',
        icon: Receipt,
        href: (token: string) => paymentsIndex(token),
        match: (url: string) =>
            url.includes('/payments') && !url.includes('/payment-methods'),
    },
    {
        label: 'Payment methods',
        icon: CreditCard,
        href: (token: string) => paymentMethodsIndex(token),
        match: (url: string) => url.includes('/payment-methods'),
    },
    {
        label: 'Account',
        icon: User,
        href: (token: string) => accountIndex(token),
        match: (url: string) => url.includes('/account'),
    },
] as const;

export default function PortalLayout({ children }: PropsWithChildren) {
    const { currentUrl } = useCurrentUrl();
    const { token, business, returnUrl } = usePage<PortalPageProps>().props;
    // Radix portals (dialogs, popovers, …) mount here instead of
    // document.body, so they inherit .portal's light-mode theme rather than
    // whatever theme the real document root is in.
    const [portalRoot, setPortalRoot] = useState<HTMLDivElement | null>(null);

    return (
        <PortalContainerProvider value={portalRoot}>
            <div
                ref={setPortalRoot}
                className="portal flex min-h-screen w-full bg-[#f6f7f9] text-foreground"
            >
                <aside className="flex w-56 shrink-0 flex-col border-r border-border bg-white px-4 py-6">
                    <div className="mb-8 flex items-center gap-2 px-2">
                        <div className="flex size-8 items-center justify-center rounded-lg bg-primary/10 text-sm font-semibold text-primary">
                            {business.name.charAt(0).toUpperCase()}
                        </div>
                        <span className="truncate font-semibold text-foreground">
                            {business.name}
                        </span>
                    </div>

                    {returnUrl && (
                        <a
                            href={returnUrl}
                            className="mb-4 flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-zinc-100 hover:text-foreground"
                            data-test="portal-return-link"
                        >
                            <ArrowLeft className="size-3.5" />
                            Return to {business.name}
                        </a>
                    )}

                    <nav className="flex flex-1 flex-col gap-1">
                        {NAV_ITEMS.map((item) => {
                            const active = item.match(currentUrl);
                            const Icon = item.icon;

                            return (
                                <Link
                                    key={item.label}
                                    href={item.href(token)}
                                    className={cn(
                                        'flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        active
                                            ? 'bg-blue-50 text-blue-700'
                                            : 'text-muted-foreground hover:bg-zinc-100 hover:text-foreground',
                                    )}
                                >
                                    <Icon className="size-4 shrink-0" />
                                    {item.label}
                                </Link>
                            );
                        })}
                    </nav>

                    <p className="px-2 text-xs text-muted-foreground">
                        Powered by Bouclay
                    </p>
                </aside>

                <main className="min-h-screen flex-1 overflow-y-auto px-6 py-8 lg:px-10">
                    {children}
                </main>
            </div>
        </PortalContainerProvider>
    );
}
