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

export type DashboardInvitation = {
    code: string;
    inviterName: string;
    team: {
        name: string;
        slug: string;
    };
};

export type TeamPermissions = {
    canManageBusiness: boolean;
    canDeleteBusiness: boolean;
    canViewMembers: boolean;
    canManageMembers: boolean;
    canViewRoles: boolean;
    canManageRoles: boolean;
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
