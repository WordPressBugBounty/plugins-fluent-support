<?php

namespace FluentSupport\App\Http\Policies;

use FluentSupport\App\Modules\PermissionManager;
use FluentSupport\Framework\Http\Request\Request;
use FluentSupport\Framework\Foundation\Policy;

class AgentTicketPolicy extends Policy
{
    /**
     * Default policy check: GET requests require view permission, POST/PUT/DELETE require manage permission.
     *
     * @param \FluentSupport\Framework\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        if ($request->method() === 'GET') {
            $status = PermissionManager::userCan([
                'fst_view_tickets', 'fst_draft_reply', 'fst_manage_own_tickets',
                'fst_manage_unassigned_tickets', 'fst_manage_other_tickets'
            ]);
        } else {
            $status = PermissionManager::canAccessTicketRoutes();
        }

        return apply_filters('fluent_support/agent_has_access', $status, $request);
    }

    /**
     * Bulk actions: delete requires fst_delete_tickets, others require manage permission.
     */
    public function doBulkActions(Request $request)
    {
        $action = $request->getSafe('bulk_action', 'sanitize_text_field');

        if ($action === 'delete_tickets') {
            return PermissionManager::currentUserCan('fst_delete_tickets');
        }

        return PermissionManager::canManageTickets();
    }

    /**
     * Delete ticket requires explicit delete permission.
     */
    public function deleteTicket(Request $request)
    {
        return PermissionManager::currentUserCan('fst_delete_tickets');
    }

    /**
     * Creating a response: accessible to draft agents too.
     * Fine-grained check (draft vs publish) is handled inside the controller.
     */
    public function createResponse(Request $request)
    {
        return PermissionManager::canAccessTicketRoutes();
    }

    /**
     * Auto-save draft: POST endpoint but accessible to any agent with ticket access.
     */
    public function createOrUpdatDraft(Request $request)
    {
        return PermissionManager::canAccessTicketRoutes();
    }

    /**
     * Label search save: POST endpoint accessible to any agent with ticket access.
     */
    public function storeOrUpdateLabelSearch(Request $request)
    {
        return PermissionManager::canAccessTicketRoutes();
    }

    /**
     * Label search delete: DELETE endpoint accessible to any agent (personal data).
     */
    public function deleteLabelSearch(Request $request)
    {
        return PermissionManager::canAccessTicketRoutes();
    }

    /**
     * Live activity removal: DELETE endpoint accessible to any agent (cleanup).
     */
    public function removeLiveActivity(Request $request)
    {
        return PermissionManager::canAccessTicketRoutes();
    }

    /**
     * Update ticket property (product, priority, etc.): requires manage ticket permission.
     */
    public function updateTicketProperty(Request $request)
    {
        return PermissionManager::canManageTickets();
    }

    /**
     * Add watchers to a ticket: requires manage ticket permission.
     */
    public function addTicketWatchers(Request $request)
    {
        return PermissionManager::canManageTickets();
    }

    /**
     * Sync watchers on a ticket: requires manage ticket permission.
     */
    public function syncTicketWatchers(Request $request)
    {
        return PermissionManager::canManageTickets();
    }

    /**
     * Update estimated time: requires manage ticket permission.
     */
    public function updateEstimatedTime(Request $request)
    {
        return PermissionManager::canManageTickets();
    }

    /**
     * Manual time commit: requires manage ticket permission.
     */
    public function manualCommitTrack(Request $request)
    {
        return PermissionManager::canManageTickets();
    }
}
