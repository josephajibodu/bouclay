import type { InvoiceListItem, PaymentListItem } from './invoices';

export type Team = {
    id: number;
    name: string;
    slug: string;
    isPersonal: boolean;
    role?: string | null;
    isOwner?: boolean;
    isCurrent?: boolean;
};

export type TeamBusinessDetails = Team & {
    businessType: BusinessType | null;
    website: string | null;
    country: string | null;
    line1: string | null;
    line2: string | null;
    city: string | null;
    postalCode: string | null;
};

export type TeamMember = {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
    role_id: number;
    role_name: string;
    is_owner: boolean;
};

export type TeamInvitation = {
    code: string;
    email: string;
    role_name: string;
    created_at: string;
};

export type TeamInvitationContext = {
    code: string;
    teamName: string;
};

export type InvitationLandingContext = {
    code: string;
    teamName: string;
    inviterName: string;
    roleName: string;
    email: string;
};

export type InvitationViewerState = 'guest' | 'correct-user' | 'wrong-user';

export type DashboardInvitation = {
    code: string;
    inviterName: string;
    team: {
        name: string;
        slug: string;
    };
};

export type DashboardSummary = {
    currency: string;
    revenueLast30: number;
    successfulPaymentsLast30: number;
    activeSubscriptions: number;
    trialingSubscriptions: number;
    pastDueSubscriptions: number;
    customers: number;
    activeProducts: number;
    activePrices: number;
    openInvoices: number;
    openInvoiceAmountDue: number;
    recentPayments: PaymentListItem[];
    recentInvoices: InvoiceListItem[];
};

export type OnboardingState = {
    businessConfirmed: boolean;
    gatewayConnected: boolean;
    apiKeyGenerated: boolean;
    webhookVerified: boolean;
    firstProductCreated: boolean;
    links: {
        gateways: string;
        apiKeys: string;
        webhooks: string;
        products: string;
    };
};

export type TeamPermissions = {
    canManageBusiness: boolean;
    canDeleteBusiness: boolean;
    canViewMembers: boolean;
    canManageMembers: boolean;
    canViewRoles: boolean;
    canManageRoles: boolean;
    canViewIntegrations: boolean;
    canManageIntegrations: boolean;
    canViewApiKeys: boolean;
    canManageApiKeys: boolean;
    canViewWebhooks: boolean;
    canManageWebhooks: boolean;
    canViewCustomers: boolean;
    canManageCustomers: boolean;
    canViewProducts: boolean;
    canManageProducts: boolean;
    canViewPlans: boolean;
    canManagePlans: boolean;
    canViewPrices: boolean;
    canManagePrices: boolean;
    canViewEntitlements: boolean;
    canManageEntitlements: boolean;
    canViewDiscounts: boolean;
    canManageDiscounts: boolean;
    canViewSubscriptions: boolean;
    canManageSubscriptions: boolean;
    canViewInvoices: boolean;
    canManageInvoices: boolean;
    canViewRefunds: boolean;
    canProcessRefunds: boolean;
};

export type RoleOption = {
    id: number;
    name: string;
};

export type BusinessType = 'individual' | 'private' | 'public';

export type BusinessTypeOption = {
    value: BusinessType;
    label: string;
};

export type PermissionCatalogEntry = {
    name: string;
    label: string;
    group: string;
};

export type Role = {
    id: number;
    name: string;
    isSystem: boolean;
    memberCount: number;
    permissionNames: string[];
};
