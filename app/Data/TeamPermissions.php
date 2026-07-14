<?php

namespace App\Data;

readonly class TeamPermissions
{
    public function __construct(
        public bool $canManageBusiness,
        public bool $canDeleteBusiness,
        public bool $canViewMembers,
        public bool $canManageMembers,
        public bool $canViewRoles,
        public bool $canManageRoles,
        public bool $canViewIntegrations,
        public bool $canManageIntegrations,
        public bool $canViewApiKeys,
        public bool $canManageApiKeys,
        public bool $canViewWebhooks,
        public bool $canManageWebhooks,
        public bool $canViewCustomers,
        public bool $canManageCustomers,
        public bool $canViewProducts,
        public bool $canManageProducts,
        public bool $canViewPlans,
        public bool $canManagePlans,
        public bool $canViewPrices,
        public bool $canManagePrices,
        public bool $canViewEntitlements,
        public bool $canManageEntitlements,
        public bool $canViewDiscounts,
        public bool $canManageDiscounts,
        public bool $canViewSubscriptions,
        public bool $canManageSubscriptions,
        public bool $canViewInvoices,
        public bool $canManageInvoices,
        public bool $canViewRefunds,
        public bool $canProcessRefunds,
    ) {
        //
    }
}
