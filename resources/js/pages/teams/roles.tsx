import { Head, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import RoleFormModal from '@/components/role-form-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { destroy, index as rolesIndex } from '@/routes/roles';
import type { PermissionCatalogEntry, Role, TeamPermissions } from '@/types';

type Props = {
    team: { id: number; slug: string };
    roles: Role[];
    permissionCatalog: Record<string, PermissionCatalogEntry[]>;
    permissions: TeamPermissions;
};

export default function Roles({
    roles,
    permissionCatalog,
    permissions,
}: Props) {
    const [formOpen, setFormOpen] = useState(false);
    const [editingRole, setEditingRole] = useState<Role | null>(null);

    const openCreate = () => {
        setEditingRole(null);
        setFormOpen(true);
    };

    const openEdit = (role: Role) => {
        setEditingRole(role);
        setFormOpen(true);
    };

    const [deleteError, setDeleteError] = useState<string | null>(null);

    const deleteRole = (role: Role) => {
        router.visit(destroy(role.id), {
            method: 'delete',
            onError: (errors) => setDeleteError(errors.role ?? null),
            onSuccess: () => setDeleteError(null),
        });
    };

    return (
        <>
            <Head title="Roles" />

            <h1 className="sr-only">Roles</h1>

            <div className="flex flex-col space-y-6">
                <div className="flex items-center justify-between">
                    <Heading
                        variant="small"
                        title="Roles"
                        description="Manage roles and what each one can do"
                    />

                    {permissions.canManageRoles ? (
                        <Button
                            data-test="new-role-button"
                            onClick={openCreate}
                        >
                            <Plus /> New role
                        </Button>
                    ) : null}
                </div>

                {deleteError ? (
                    <p className="text-sm text-destructive">{deleteError}</p>
                ) : null}

                <div className="space-y-3">
                    {roles.map((role) => {
                        const canDelete =
                            permissions.canManageRoles &&
                            !role.isSystem &&
                            role.memberCount === 0;

                        return (
                            <div
                                key={role.id}
                                data-test="role-row"
                                className="flex items-center justify-between gap-4 rounded-lg border p-4"
                            >
                                <div>
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium">
                                            {role.name}
                                        </span>
                                        {role.isSystem ? (
                                            <Badge variant="secondary">
                                                System
                                            </Badge>
                                        ) : null}
                                    </div>
                                    <span className="text-sm text-muted-foreground">
                                        {role.permissionNames.length}{' '}
                                        permission
                                        {role.permissionNames.length === 1
                                            ? ''
                                            : 's'}{' '}
                                        &middot; {role.memberCount} member
                                        {role.memberCount === 1 ? '' : 's'}
                                    </span>
                                </div>

                                {permissions.canManageRoles &&
                                !role.isSystem ? (
                                    <TooltipProvider>
                                        <div className="flex items-center gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                data-test="edit-role-button"
                                                onClick={() => openEdit(role)}
                                            >
                                                Edit
                                            </Button>

                                            {canDelete ? (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    data-test="delete-role-button"
                                                    onClick={() =>
                                                        deleteRole(role)
                                                    }
                                                >
                                                    Delete
                                                </Button>
                                            ) : (
                                                <Tooltip>
                                                    <TooltipTrigger asChild>
                                                        <span>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                disabled
                                                            >
                                                                Delete
                                                            </Button>
                                                        </span>
                                                    </TooltipTrigger>
                                                    <TooltipContent>
                                                        <p>
                                                            Reassign the{' '}
                                                            {
                                                                role.memberCount
                                                            }{' '}
                                                            member
                                                            {role.memberCount ===
                                                            1
                                                                ? ''
                                                                : 's'}{' '}
                                                            on this role
                                                            before deleting it.
                                                        </p>
                                                    </TooltipContent>
                                                </Tooltip>
                                            )}
                                        </div>
                                    </TooltipProvider>
                                ) : null}
                            </div>
                        );
                    })}
                </div>
            </div>

            {permissions.canManageRoles ? (
                <RoleFormModal
                    permissionCatalog={permissionCatalog}
                    role={editingRole}
                    open={formOpen}
                    onOpenChange={setFormOpen}
                />
            ) : null}
        </>
    );
}

Roles.layout = () => ({
    breadcrumbs: [
        {
            title: 'Roles',
            href: rolesIndex(),
        },
    ],
});
