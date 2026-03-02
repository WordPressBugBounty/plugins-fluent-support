<?php

namespace FluentSupport\App\Hooks\Handlers;

use FluentSupport\App\Modules\PermissionManager;
use FluentSupport\App\Services\Helper;

class PermissionFilterManager
{
    public function init()
    {
        add_action('fluent_support/tickets_query_by_permission_ref', array($this, 'filterAgentTickets'), 10, 2);
        add_action('fluent_support\main_tickets_query', array($this, 'filterAgentTicketsByMailboxes'), 10, 2);
    }

    public function filterAgentTickets($ticketsQuery, $userId = false)
    {
        $permissionLevel = PermissionManager::getAgentTicketVisibility($userId);

        if ($permissionLevel == PermissionManager::VISIBILITY_ALL) {
            return;
        }

        $agent = Helper::getAgentByUserId();

        if ($permissionLevel == PermissionManager::VISIBILITY_ASSIGNED_ONLY) {
            $ticketsQuery->where('agent_id', $agent->id);
        } else {
            // assigned_and_unassigned: own tickets + unassigned tickets
            $ticketsQuery->where(function ($q) use ($agent) {
                $q->where('agent_id', $agent->id);
                $q->orWhereNull('agent_id');
            });
        }
    }

    public function filterAgentTicketsByMailboxes($ticketsQuery, $args = [] )
    {
        $restrictedBusinessBoxes = PermissionManager::getRestrictedMailboxIds();
        if (!empty($restrictedBusinessBoxes)) {
            $ticketsQuery->whereNotIn('mailbox_id', $restrictedBusinessBoxes);
        }
    }
}
