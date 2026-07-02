import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { login, logout, register } from '@/routes';
import { accept } from '@/routes/invitations';
import { decline, register as joinRegisterView } from '@/routes/join';
import type { InvitationLandingContext, InvitationViewerState } from '@/types';

type Props = {
    invitation: InvitationLandingContext | null;
    accountExists: boolean;
    viewerState: InvitationViewerState;
    viewerEmail?: string | null;
};

export default function AcceptInvitation({
    invitation,
    accountExists,
    viewerState,
    viewerEmail,
}: Props) {
    const [processing, setProcessing] = useState<'accept' | 'decline' | null>(
        null,
    );

    const declineInvitation = () => {
        if (!invitation) {
            return;
        }

        router.post(
            decline(invitation.code),
            {},
            {
                onStart: () => setProcessing('decline'),
                onFinish: () => setProcessing(null),
            },
        );
    };

    const acceptAsCurrentUser = () => {
        if (!invitation) {
            return;
        }

        router.post(
            accept(invitation.code),
            {},
            {
                onStart: () => setProcessing('accept'),
                onFinish: () => setProcessing(null),
            },
        );
    };

    if (!invitation) {
        return (
            <>
                <Head title="Invitation no longer valid" />
                <div className="flex flex-col gap-6 text-center">
                    <p className="text-sm text-muted-foreground">
                        It may have expired or already been used. Ask the person
                        who invited you to send a new one.
                    </p>
                    <Button asChild>
                        <TextLink href={register()}>
                            Create your own business
                        </TextLink>
                    </Button>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={`Join ${invitation.teamName}`} />
            <div className="flex flex-col gap-6">
                <p className="text-center text-sm text-muted-foreground">
                    You'll join as <strong>{invitation.roleName}</strong>.
                </p>

                {viewerState === 'wrong-user' && (
                    <Alert data-test="invitation-wrong-user-alert">
                        <AlertDescription>
                            This invitation was sent to{' '}
                            <strong>{invitation.email}</strong>. You're
                            currently logged in as {viewerEmail}. Log out, then
                            reopen the invitation link from your email.
                        </AlertDescription>
                    </Alert>
                )}

                <div className="flex flex-col gap-2">
                    {viewerState === 'correct-user' && (
                        <Button
                            data-test="accept-invitation-button"
                            disabled={processing !== null}
                            onClick={acceptAsCurrentUser}
                        >
                            {processing === 'accept' && <Spinner />}
                            Accept invitation
                        </Button>
                    )}

                    {viewerState === 'wrong-user' && (
                        <Button
                            variant="secondary"
                            onClick={() => router.post(logout())}
                        >
                            Log out
                        </Button>
                    )}

                    {viewerState === 'guest' && accountExists && (
                        <Button asChild data-test="accept-invitation-button">
                            <TextLink
                                href={login({
                                    query: { invitation: invitation.code },
                                })}
                            >
                                Log in to accept
                            </TextLink>
                        </Button>
                    )}

                    {viewerState === 'guest' && !accountExists && (
                        <Button asChild data-test="accept-invitation-button">
                            <TextLink href={joinRegisterView(invitation.code)}>
                                Accept invitation
                            </TextLink>
                        </Button>
                    )}

                    {viewerState !== 'wrong-user' && (
                        <Button
                            variant="ghost"
                            data-test="decline-invitation-button"
                            disabled={processing !== null}
                            onClick={declineInvitation}
                        >
                            {processing === 'decline' && <Spinner />}
                            Decline
                        </Button>
                    )}
                </div>
            </div>
        </>
    );
}

AcceptInvitation.layout = (props: Props) => ({
    title: props.invitation
        ? `${props.invitation.inviterName} invited you to join ${props.invitation.teamName}`
        : 'Invitation no longer valid',
    description: props.invitation
        ? 'Accept the invitation to start collaborating.'
        : '',
});
