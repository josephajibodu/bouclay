<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::TeamManage);
    }

    /**
     * Determine whether the user can leave the team.
     */
    public function leave(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team)
            && ! $user->ownsTeam($team)
            && $user->teams()->count() > 1;
    }

    /**
     * Determine whether the user can view the team's members and invitations.
     */
    public function viewMembers(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::MembersView)
            || $user->hasTeamPermission($team, PermissionName::MembersManage);
    }

    /**
     * Determine whether the user can add a member to the team.
     */
    public function addMember(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::MembersManage);
    }

    /**
     * Determine whether the user can update a member's role in the team.
     */
    public function updateMember(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::MembersManage);
    }

    /**
     * Determine whether the user can remove a member from the team.
     */
    public function removeMember(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::MembersManage);
    }

    /**
     * Determine whether the user can invite members to the team.
     */
    public function inviteMember(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::MembersManage);
    }

    /**
     * Determine whether the user can cancel invitations.
     */
    public function cancelInvitation(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::MembersManage);
    }

    /**
     * Determine whether the user can view the team's Nomba integration.
     */
    public function viewIntegrations(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::IntegrationsView)
            || $user->hasTeamPermission($team, PermissionName::IntegrationsManage);
    }

    /**
     * Determine whether the user can connect, test, or disconnect the
     * team's Nomba integration.
     */
    public function manageIntegrations(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::IntegrationsManage);
    }

    /**
     * Determine whether the user can view the team's Bouclay API keys.
     */
    public function viewApiKeys(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::ApiKeysView)
            || $user->hasTeamPermission($team, PermissionName::ApiKeysManage);
    }

    /**
     * Determine whether the user can create or revoke the team's Bouclay API keys.
     */
    public function manageApiKeys(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::ApiKeysManage);
    }

    /**
     * Determine whether the user can view the team's inbound webhook.
     */
    public function viewWebhooks(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::WebhooksView)
            || $user->hasTeamPermission($team, PermissionName::WebhooksManage);
    }

    /**
     * Determine whether the user can rotate the endpoint or update signing secrets.
     */
    public function manageWebhooks(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::WebhooksManage);
    }

    /**
     * Determine whether the user can view the team's customers.
     */
    public function viewCustomers(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::CustomersView)
            || $user->hasTeamPermission($team, PermissionName::CustomersManage);
    }

    /**
     * Determine whether the user can create, edit, archive, or charge
     * customers and manage their addresses and payment methods.
     */
    public function manageCustomers(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::CustomersManage);
    }

    /**
     * Determine whether the user can view the team's product catalog.
     */
    public function viewProducts(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::ProductsView)
            || $user->hasTeamPermission($team, PermissionName::ProductsManage);
    }

    /**
     * Determine whether the user can create, edit, or archive products.
     */
    public function manageProducts(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::ProductsManage);
    }

    /**
     * Determine whether the user can view prices.
     */
    public function viewPrices(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::PricesView)
            || $user->hasTeamPermission($team, PermissionName::PricesManage);
    }

    /**
     * Determine whether the user can create, edit, or archive prices.
     */
    public function managePrices(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::PricesManage);
    }

    /**
     * Determine whether the user can view trial offers.
     */
    public function viewTrialOffers(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::TrialOffersView)
            || $user->hasTeamPermission($team, PermissionName::TrialOffersManage);
    }

    /**
     * Determine whether the user can create, edit, or remove trial offers.
     */
    public function manageTrialOffers(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::TrialOffersManage);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, PermissionName::TeamDelete)
            && $user->ownsTeam($team)
            && $user->teams()->count() > 1;
    }
}
