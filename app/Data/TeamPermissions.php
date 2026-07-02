<?php

namespace App\Data;

readonly class TeamPermissions
{
    public function __construct(
        public bool $canManageBusiness,
        public bool $canDeleteBusiness,
        public bool $canViewMembers,
        public bool $canManageMembers,
        public bool $canViewRoles,
        public bool $canManageRoles,
        public bool $canViewIntegrations,
        public bool $canManageIntegrations,
    ) {
        //
    }
}
