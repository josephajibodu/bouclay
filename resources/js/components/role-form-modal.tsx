import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store, update } from '@/routes/roles';
import type { PermissionCatalogEntry, Role } from '@/types';

type Props = {
    permissionCatalog: Record<string, PermissionCatalogEntry[]>;
    role?: Role | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function RoleFormModal({
    permissionCatalog,
    role,
    open,
    onOpenChange,
}: Props) {
    const [selectedPermissions, setSelectedPermissions] = useState<
        Set<string>
    >(new Set(role?.permissionNames ?? []));

    const togglePermission = (name: string) => {
        setSelectedPermissions((prev) => {
            const next = new Set(prev);

            if (next.has(name)) {
                next.delete(name);
            } else {
                next.add(name);
            }

            return next;
        });
    };

    const handleOpenChange = (nextOpen: boolean) => {
        onOpenChange(nextOpen);

        if (!nextOpen) {
            setSelectedPermissions(new Set(role?.permissionNames ?? []));
        }
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-xl">
                <Form
                    key={`${String(open)}-${role?.id ?? 'new'}`}
                    {...(role ? update.form(role.id) : store.form())}
                    className="space-y-6"
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    {role ? 'Edit role' : 'Create a new role'}
                                </DialogTitle>
                                <DialogDescription>
                                    {role
                                        ? 'Update the name and permissions for this role.'
                                        : 'Choose a name and the permissions this role should grant.'}
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-2">
                                <Label htmlFor="name">Role name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    data-test="role-name-input"
                                    defaultValue={role?.name}
                                    placeholder="e.g. Marketing"
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="space-y-4">
                                <Label>Permissions</Label>

                                {Array.from(selectedPermissions).map(
                                    (name) => (
                                        <input
                                            key={name}
                                            type="hidden"
                                            name="permissions[]"
                                            value={name}
                                        />
                                    ),
                                )}

                                <div className="max-h-72 space-y-4 overflow-y-auto rounded-lg border p-4">
                                    {Object.entries(permissionCatalog).map(
                                        ([group, permissions]) => (
                                            <div
                                                key={group}
                                                className="space-y-2"
                                            >
                                                <p className="text-sm font-medium">
                                                    {group}
                                                </p>
                                                <div className="grid gap-2 sm:grid-cols-2">
                                                    {permissions.map(
                                                        (permission) => (
                                                            <label
                                                                key={
                                                                    permission.name
                                                                }
                                                                className="flex items-center gap-2 text-sm"
                                                            >
                                                                <Checkbox
                                                                    data-test="role-permission-checkbox"
                                                                    checked={selectedPermissions.has(
                                                                        permission.name,
                                                                    )}
                                                                    onCheckedChange={() =>
                                                                        togglePermission(
                                                                            permission.name,
                                                                        )
                                                                    }
                                                                />
                                                                {
                                                                    permission.label
                                                                }
                                                            </label>
                                                        ),
                                                    )}
                                                </div>
                                            </div>
                                        ),
                                    )}
                                </div>
                                <InputError message={errors.permissions} />
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>

                                <Button
                                    type="submit"
                                    data-test="role-form-submit"
                                    disabled={processing}
                                >
                                    {role ? 'Save changes' : 'Create role'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
