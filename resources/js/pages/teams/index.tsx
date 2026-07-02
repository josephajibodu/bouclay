import { Head, router } from '@inertiajs/react';
import { ArrowLeftRight, LogOut, Plus } from 'lucide-react';
import { useState } from 'react';
import CreateTeamModal from '@/components/create-team-modal';
import Heading from '@/components/heading';
import LeaveTeamModal from '@/components/leave-team-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { edit as editGeneral } from '@/routes/general';
import { index, switchMethod } from '@/routes/teams';
import type { Team } from '@/types';

type Props = {
    teams: Team[];
};

export default function TeamsIndex({ teams }: Props) {
    const [leaveTeamDialogOpen, setLeaveTeamDialogOpen] = useState(false);
    const [teamLeaving, setTeamLeaving] = useState<Team | null>(null);

    const openLeaveTeamDialog = (team: Team) => {
        setTeamLeaving(team);
        setLeaveTeamDialogOpen(true);
    };

    const switchToTeam = (team: Team) => {
        router.visit(switchMethod(team.slug), {
            onSuccess: () => router.visit(editGeneral()),
        });
    };

    return (
        <>
            <Head title="Businesses" />

            <h1 className="sr-only">Businesses</h1>

            <div className="flex flex-col space-y-6">
                <div className="flex items-center justify-between">
                    <Heading
                        variant="small"
                        title="Businesses"
                        description="Manage your businesses and team memberships"
                    />

                    <CreateTeamModal>
                        <Button data-test="teams-new-team-button">
                            <Plus /> New business
                        </Button>
                    </CreateTeamModal>
                </div>

                <div className="space-y-3">
                    {teams.map((team) => {
                        const canLeaveTeam =
                            !team.isPersonal && !team.isOwner;

                        return (
                            <div
                                key={team.id}
                                data-test="team-row"
                                className="flex items-center justify-between gap-4 rounded-lg border p-4"
                            >
                                <div className="flex items-center gap-4">
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium">
                                                {team.name}
                                            </span>
                                            {team.isCurrent ? (
                                                <Badge>Current</Badge>
                                            ) : null}
                                            {team.isPersonal ? (
                                                <Badge variant="secondary">
                                                    Personal
                                                </Badge>
                                            ) : null}
                                        </div>
                                        <span className="text-sm text-muted-foreground">
                                            {team.role}
                                        </span>
                                    </div>
                                </div>

                                <div className="flex items-center gap-2">
                                    {canLeaveTeam ? (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            data-test="team-leave-button"
                                            onClick={() =>
                                                openLeaveTeamDialog(team)
                                            }
                                        >
                                            <LogOut className="h-4 w-4" />
                                            Leave
                                        </Button>
                                    ) : null}

                                    {!team.isCurrent ? (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            data-test="team-switch-button"
                                            onClick={() =>
                                                switchToTeam(team)
                                            }
                                        >
                                            <ArrowLeftRight className="h-4 w-4" />
                                            Switch
                                        </Button>
                                    ) : null}
                                </div>
                            </div>
                        );
                    })}

                    {teams.length === 0 ? (
                        <p className="py-8 text-center text-muted-foreground">
                            You don't belong to any businesses yet.
                        </p>
                    ) : null}
                </div>
            </div>

            <LeaveTeamModal
                team={teamLeaving}
                open={leaveTeamDialogOpen}
                onOpenChange={setLeaveTeamDialogOpen}
            />
        </>
    );
}

TeamsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Businesses',
            href: index(),
        },
    ],
};
