<?php

namespace App\Actions\Teams;

use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;

class SeedDefaultRoles
{
    /**
     * Starter roles created for every new team, alongside the protected Admin role.
     *
     * @var array<string, array<int, PermissionName>>
     */
    private const STARTER_ROLES = [
        'Developer' => [
            PermissionName::ProductsView,
            PermissionName::ProductsManage,
            PermissionName::PlansView,
            PermissionName::PlansManage,
            PermissionName::PricesView,
            PermissionName::PricesManage,
            PermissionName::EntitlementsView,
            PermissionName::EntitlementsManage,
            PermissionName::DiscountsView,
            PermissionName::DiscountsManage,
            PermissionName::ApiKeysView,
            PermissionName::ApiKeysManage,
            PermissionName::WebhooksView,
            PermissionName::WebhooksManage,
            PermissionName::IntegrationsView,
            PermissionName::IntegrationsManage,
            PermissionName::DiagnosticsView,
        ],
        'Finance' => [
            PermissionName::InvoicesView,
            PermissionName::InvoicesManage,
            PermissionName::InvoicesFinalize,
            PermissionName::OrdersView,
            PermissionName::OrdersManage,
            PermissionName::PaymentsView,
            PermissionName::FinancialReportsView,
            PermissionName::TransfersView,
            PermissionName::TransfersManage,
        ],
        'Support' => [
            PermissionName::CustomersView,
            PermissionName::CustomersManage,
            PermissionName::SubscriptionsView,
            PermissionName::SubscriptionsManage,
            PermissionName::SubscriptionKpisView,
            PermissionName::RefundsView,
            PermissionName::RefundsProcess,
            PermissionName::LicensesView,
            PermissionName::LicensesManage,
        ],
    ];

    /**
     * Create the Admin role plus starter roles for a new team.
     *
     * @return Role the protected Admin role, for assigning to the team's owner
     */
    public function handle(Team $team): Role
    {
        $adminRole = $team->roles()->create(['name' => 'Admin', 'is_system' => true]);
        $adminRole->permissions()->attach(Permission::query()->pluck('id'));

        foreach (self::STARTER_ROLES as $name => $permissions) {
            $role = $team->roles()->create(['name' => $name]);

            $permissionIds = Permission::query()
                ->whereIn('name', array_map(fn (PermissionName $permission) => $permission->value, $permissions))
                ->pluck('id');

            $role->permissions()->attach($permissionIds);
        }

        return $adminRole;
    }
}
