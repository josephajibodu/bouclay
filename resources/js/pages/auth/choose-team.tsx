import { Form, Head, router } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import CreateTeamModal from '@/components/create-team-modal';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { switchMethod } from '@/routes/teams';
import type { Team } from '@/types';

type Props = {
    teams: Team[];
};

export default function ChooseTeam({ teams }: Props) {
    if (teams.length === 0) {
        return (
            <>
                <Head title="Choose a business" />

                <div className="space-y-4 text-center">
                    <p className="text-sm text-muted-foreground">
                        You don't belong to any business yet.
                    </p>
                    <CreateTeamModal>
                        <Button className="w-full" data-test="create-first-business">
                            Create a business
                        </Button>
                    </CreateTeamModal>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Choose a business" />

            <div className="space-y-2">
                {teams.map((team) => (
                    <Form
                        key={team.id}
                        {...switchMethod.form(team.slug)}
                        onSuccess={() => router.visit(dashboard())}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="outline"
                                className="w-full justify-start gap-3"
                                disabled={processing}
                                data-test="choose-team-option"
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <Building2 className="size-4 text-muted-foreground" />
                                )}
                                {team.name}
                            </Button>
                        )}
                    </Form>
                ))}
            </div>
        </>
    );
}

ChooseTeam.layout = {
    title: 'Choose a business',
    description: 'Pick which business you want to work in.',
};
