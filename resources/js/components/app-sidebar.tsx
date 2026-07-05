import { Link, usePage } from '@inertiajs/react';
import {
    Code2,
    LayoutGrid,
    Package,
    Receipt,
    RefreshCw,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { TeamSwitcher } from '@/components/team-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as products } from '@/routes/catalog/products';
import { index as customers } from '@/routes/customers';
import { index as apiKeys } from '@/routes/developers/api-keys';
import { show as nombaIntegration } from '@/routes/developers/nomba';
import { show as webhooks } from '@/routes/developers/webhooks';
import { index as subscriptions } from '@/routes/subscriptions';
import { index as invoices } from '@/routes/invoices';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const page = usePage();
    const { currentTeam, teamPermissions } = page.props;

    const catalogItems: NavItem[] = currentTeam
        ? [
              ...(teamPermissions?.canViewProducts
                  ? [{ title: 'Products', href: products() }]
                  : []),
          ]
        : [];

    const developerItems: NavItem[] = currentTeam
        ? [
              ...(teamPermissions?.canViewIntegrations
                  ? [
                        {
                            title: 'Nomba Integration',
                            href: nombaIntegration(),
                        },
                    ]
                  : []),
              ...(teamPermissions?.canViewApiKeys
                  ? [{ title: 'API Keys', href: apiKeys() }]
                  : []),
              ...(teamPermissions?.canViewWebhooks
                  ? [{ title: 'Webhooks', href: webhooks() }]
                  : []),
          ]
        : [];

    const mainNavItems: NavItem[] = [
        {
            title: 'Overview',
            href: dashboard(),
            icon: LayoutGrid,
        },
        ...(currentTeam && catalogItems.length > 0
            ? [
                  {
                      title: 'Catalog',
                      href: catalogItems[0].href,
                      icon: Package,
                      items: catalogItems,
                  },
              ]
            : []),
        ...(currentTeam && teamPermissions?.canViewCustomers
            ? [
                  {
                      title: 'Customers',
                      href: customers(),
                      icon: Users,
                  },
              ]
            : []),
        ...(currentTeam && teamPermissions?.canViewSubscriptions
            ? [
                  {
                      title: 'Subscriptions',
                      href: subscriptions(),
                      icon: RefreshCw,
                  },
              ]
            : []),
        ...(currentTeam && teamPermissions?.canViewInvoices
            ? [
                  {
                      title: 'Invoices',
                      href: invoices(),
                      icon: Receipt,
                  },
              ]
            : []),
        ...(currentTeam && developerItems.length > 0
            ? [
                  {
                      title: 'Developers',
                      href: developerItems[0].href,
                      icon: Code2,
                      items: developerItems,
                  },
              ]
            : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <TeamSwitcher />
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
