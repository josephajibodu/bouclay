import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editGeneral } from '@/routes/general';
import { edit } from '@/routes/profile';
import { index as roles } from '@/routes/roles';
import { edit as editSecurity } from '@/routes/security';
import { index as businesses } from '@/routes/teams';
import { index as members } from '@/routes/teams/members';
import type { NavItem, TeamPermissions } from '@/types';

/**
 * Every team member can reach these; team-scoped items are filtered by
 * teamPermissions below so a member without view access never sees the tab.
 */
function buildSettingsNavItems(
    teamPermissions: TeamPermissions | null,
): NavItem[] {
    return [
        {
            title: 'General',
            href: editGeneral(),
            icon: null,
        },
        {
            title: 'Profile',
            href: edit(),
            icon: null,
        },
        {
            title: 'Security',
            href: editSecurity(),
            icon: null,
        },
        {
            title: 'Businesses',
            href: businesses(),
            icon: null,
        },
        ...(teamPermissions?.canViewMembers || teamPermissions?.canManageMembers
            ? [
                  {
                      title: 'Teams',
                      href: members(),
                      icon: null,
                  },
              ]
            : []),
        ...(teamPermissions?.canViewRoles || teamPermissions?.canManageRoles
            ? [
                  {
                      title: 'Roles',
                      href: roles(),
                      icon: null,
                  },
              ]
            : []),
        {
            title: 'Appearance',
            href: editAppearance(),
            icon: null,
        },
    ];
}

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { teamPermissions } = usePage().props;
    const settingsNavItems = buildSettingsNavItems(teamPermissions);

    return (
        <div className="px-4 py-6">
            <Heading
                title="Settings"
                description="Manage your profile and account settings"
            />

            <nav
                className="flex gap-6 overflow-x-auto border-b"
                aria-label="Settings"
            >
                {settingsNavItems.map((item, index) => (
                    <Link
                        key={`${toUrl(item.href)}-${index}`}
                        href={item.href}
                        className={cn(
                            'shrink-0 border-b-2 border-transparent pb-3 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground',
                            isCurrentOrParentUrl(item.href) &&
                                'border-foreground text-foreground',
                        )}
                    >
                        {item.title}
                    </Link>
                ))}
            </nav>

            <div className="max-w-2xl">
                <section className="mt-6 space-y-12">{children}</section>
            </div>
        </div>
    );
}
