<?php

namespace App\Enums;

enum PermissionName: string
{
    case TeamManage = 'team.manage';
    case TeamDelete = 'team.delete';

    case MembersView = 'members.view';
    case MembersManage = 'members.manage';

    case RolesView = 'roles.view';
    case RolesManage = 'roles.manage';

    case CustomersView = 'customers.view';
    case CustomersManage = 'customers.manage';

    case ProductsView = 'products.view';
    case ProductsManage = 'products.manage';

    case PlansView = 'plans.view';
    case PlansManage = 'plans.manage';

    case PricesView = 'prices.view';
    case PricesManage = 'prices.manage';

    case EntitlementsView = 'entitlements.view';
    case EntitlementsManage = 'entitlements.manage';

    case DiscountsView = 'discounts.view';
    case DiscountsManage = 'discounts.manage';

    case InvoicesView = 'invoices.view';
    case InvoicesManage = 'invoices.manage';
    case InvoicesFinalize = 'invoices.finalize';

    case SubscriptionsView = 'subscriptions.view';
    case SubscriptionsManage = 'subscriptions.manage';
    case SubscriptionKpisView = 'subscription_kpis.view';

    case OrdersView = 'orders.view';
    case OrdersManage = 'orders.manage';
    case PaymentsView = 'payments.view';
    case FinancialReportsView = 'financial_reports.view';
    case TransfersView = 'transfers.view';
    case TransfersManage = 'transfers.manage';

    case RefundsView = 'refunds.view';
    case RefundsProcess = 'refunds.process';
    case LicensesView = 'licenses.view';
    case LicensesManage = 'licenses.manage';

    case ApiKeysView = 'api_keys.view';
    case ApiKeysManage = 'api_keys.manage';
    case WebhooksView = 'webhooks.view';
    case WebhooksManage = 'webhooks.manage';
    case IntegrationsView = 'integrations.view';
    case IntegrationsManage = 'integrations.manage';
    case DiagnosticsView = 'diagnostics.view';

    /**
     * Get the human label for this permission.
     */
    public function label(): string
    {
        return match ($this) {
            self::TeamManage => 'Manage business settings',
            self::TeamDelete => 'Delete business',
            self::MembersView => 'View team members',
            self::MembersManage => 'Manage team members',
            self::RolesView => 'View roles',
            self::RolesManage => 'Manage roles',
            self::CustomersView => 'View customers',
            self::CustomersManage => 'Manage customers',
            self::ProductsView => 'View products',
            self::ProductsManage => 'Manage products',
            self::PlansView => 'View plans',
            self::PlansManage => 'Manage plans',
            self::PricesView => 'View prices',
            self::PricesManage => 'Manage prices',
            self::EntitlementsView => 'View entitlements',
            self::EntitlementsManage => 'Manage entitlements',
            self::DiscountsView => 'View discounts',
            self::DiscountsManage => 'Manage discounts',
            self::InvoicesView => 'View invoices',
            self::InvoicesManage => 'Manage invoices',
            self::InvoicesFinalize => 'Finalize invoices',
            self::SubscriptionsView => 'View subscriptions',
            self::SubscriptionsManage => 'Manage subscriptions',
            self::SubscriptionKpisView => 'View subscription KPIs',
            self::OrdersView => 'View orders',
            self::OrdersManage => 'Manage orders',
            self::PaymentsView => 'View payments',
            self::FinancialReportsView => 'View financial reports',
            self::TransfersView => 'View transfers',
            self::TransfersManage => 'Manage transfers',
            self::RefundsView => 'View refunds',
            self::RefundsProcess => 'Process refunds',
            self::LicensesView => 'View licenses',
            self::LicensesManage => 'Manage licenses',
            self::ApiKeysView => 'View API keys',
            self::ApiKeysManage => 'Manage API keys',
            self::WebhooksView => 'View webhook endpoints',
            self::WebhooksManage => 'Manage webhook endpoints',
            self::IntegrationsView => 'View integrations',
            self::IntegrationsManage => 'Manage integrations',
            self::DiagnosticsView => 'View diagnostics',
        };
    }

    /**
     * Get the UI grouping for this permission.
     */
    public function group(): string
    {
        return match ($this) {
            self::TeamManage, self::TeamDelete => 'Business',
            self::MembersView, self::MembersManage => 'Members',
            self::RolesView, self::RolesManage => 'Roles',
            self::CustomersView, self::CustomersManage,
            self::ProductsView, self::ProductsManage,
            self::PlansView, self::PlansManage,
            self::PricesView, self::PricesManage,
            self::EntitlementsView, self::EntitlementsManage,
            self::DiscountsView, self::DiscountsManage => 'Catalog',
            self::InvoicesView, self::InvoicesManage, self::InvoicesFinalize => 'Invoicing',
            self::SubscriptionsView, self::SubscriptionsManage, self::SubscriptionKpisView => 'Subscriptions',
            self::OrdersView, self::OrdersManage, self::PaymentsView,
            self::FinancialReportsView, self::TransfersView, self::TransfersManage => 'Finance',
            self::RefundsView, self::RefundsProcess,
            self::LicensesView, self::LicensesManage => 'Support',
            self::ApiKeysView, self::ApiKeysManage,
            self::WebhooksView, self::WebhooksManage,
            self::IntegrationsView, self::IntegrationsManage,
            self::DiagnosticsView => 'Technical',
        };
    }
}
