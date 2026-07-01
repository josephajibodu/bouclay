import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import DeleteTeamModal from '@/components/delete-team-modal';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { countries } from '@/lib/countries';
import { edit as editGeneral, update } from '@/routes/general';
import type {
    BusinessType,
    BusinessTypeOption,
    TeamBusinessDetails,
} from '@/types';

type Props = {
    team: TeamBusinessDetails;
    permissions: { canUpdateTeam: boolean; canDeleteTeam: boolean };
    businessTypes: BusinessTypeOption[];
};

export default function General({ team, permissions, businessTypes }: Props) {
    const [businessType, setBusinessType] = useState<BusinessType>(
        team.businessType ?? 'individual',
    );
    const [country, setCountry] = useState(team.country ?? countries[0].code);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);

    return (
        <>
            <Head title={`Business settings — ${team.name}`} />

            <h1 className="sr-only">General</h1>

            <div className="flex flex-col space-y-10">
                {permissions.canUpdateTeam ? (
                    <div className="space-y-6">
                        <Heading
                            variant="small"
                            title="Business settings"
                            description="Update your business details and address"
                        />

                        <Form
                            {...update.form()}
                            className="max-w-xl space-y-6"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">
                                            Business name
                                        </Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            data-test="team-name-input"
                                            defaultValue={team.name}
                                            required
                                        />
                                        <InputError message={errors.name} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="business_type">
                                            Business type
                                        </Label>
                                        <Select
                                            name="business_type"
                                            value={businessType}
                                            onValueChange={(value) =>
                                                setBusinessType(
                                                    value as BusinessType,
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                id="business_type"
                                                className="w-full"
                                            >
                                                <SelectValue placeholder="Select a business type" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {businessTypes.map((type) => (
                                                    <SelectItem
                                                        key={type.value}
                                                        value={type.value}
                                                    >
                                                        {type.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={errors.business_type}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="website">
                                            Website (optional)
                                        </Label>
                                        <Input
                                            id="website"
                                            type="url"
                                            name="website"
                                            defaultValue={team.website ?? ''}
                                            placeholder="https://example.com"
                                        />
                                        <InputError
                                            message={errors.website}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="country">
                                            Country
                                        </Label>
                                        <Select
                                            name="country"
                                            value={country}
                                            onValueChange={setCountry}
                                        >
                                            <SelectTrigger
                                                id="country"
                                                className="w-full"
                                            >
                                                <SelectValue placeholder="Select a country" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {countries.map((c) => (
                                                    <SelectItem
                                                        key={c.code}
                                                        value={c.code}
                                                    >
                                                        {c.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={errors.country}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="line1">
                                            Street address line 1
                                        </Label>
                                        <Input
                                            id="line1"
                                            name="line1"
                                            defaultValue={team.line1 ?? ''}
                                            autoComplete="address-line1"
                                            placeholder="Street address"
                                            required
                                        />
                                        <InputError message={errors.line1} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="line2">
                                            Street address line 2 (optional)
                                        </Label>
                                        <Input
                                            id="line2"
                                            name="line2"
                                            defaultValue={team.line2 ?? ''}
                                            autoComplete="address-line2"
                                            placeholder="Apartment, suite, etc."
                                        />
                                        <InputError message={errors.line2} />
                                    </div>

                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="city">
                                                City / Town
                                            </Label>
                                            <Input
                                                id="city"
                                                name="city"
                                                defaultValue={team.city ?? ''}
                                                autoComplete="address-level2"
                                                placeholder="City"
                                                required
                                            />
                                            <InputError
                                                message={errors.city}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="postal_code">
                                                Postal / Zip code (optional)
                                            </Label>
                                            <Input
                                                id="postal_code"
                                                name="postal_code"
                                                defaultValue={
                                                    team.postalCode ?? ''
                                                }
                                                autoComplete="postal-code"
                                                placeholder="Postal code"
                                            />
                                            <InputError
                                                message={errors.postal_code}
                                            />
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-4">
                                        <Button
                                            type="submit"
                                            data-test="team-save-button"
                                            disabled={processing}
                                        >
                                            Save
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </div>
                ) : (
                    <Heading variant="small" title={team.name} />
                )}

                {permissions.canDeleteTeam && !team.isPersonal ? (
                    <div className="max-w-xl space-y-6">
                        <Heading
                            variant="small"
                            title="Delete team"
                            description="Permanently delete your team"
                        />
                        <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                            <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                                <p className="font-medium">Warning</p>
                                <p className="text-sm">
                                    Please proceed with caution, this cannot be
                                    undone.
                                </p>
                            </div>
                            <Button
                                variant="destructive"
                                data-test="delete-team-button"
                                onClick={() => setDeleteDialogOpen(true)}
                            >
                                Delete team
                            </Button>
                        </div>
                    </div>
                ) : null}
            </div>

            {permissions.canDeleteTeam && !team.isPersonal ? (
                <DeleteTeamModal
                    team={team}
                    open={deleteDialogOpen}
                    onOpenChange={setDeleteDialogOpen}
                />
            ) : null}
        </>
    );
}

General.layout = () => ({
    breadcrumbs: [
        {
            title: 'General',
            href: editGeneral(),
        },
    ],
});
