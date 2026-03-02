<?php

namespace FluentSupport\App\Services\Tickets;

use FluentSupport\App\Models\Attachment;
use FluentSupport\App\Models\Conversation;
use FluentSupport\App\Models\MailBox;
use FluentSupport\App\Models\Ticket;
use FluentSupport\App\Services\Helper;
use FluentSupport\App\Services\Includes\UploadService;
use FluentSupport\Framework\Support\Arr;

class TicketService
{
    /**
     * this function will set the ticket status as closed
     * @param $ticket
     * @param $person
     * @param string $internalNote
     * @param bool $silently
     * @return mixed
     */

    public function close($ticket, $person, $internalNote = '', $silently = 'no')
    {
        if ($ticket->status != 'closed') {
            $ticket->status = 'closed';
            $ticket->resolved_at = current_time('mysql');
            $ticket->closed_by = $person->id;
            $ticket->total_close_time = current_time('timestamp') - strtotime($ticket->created_at);
            $ticket->save();

            if ('no' == $silently) {
                do_action('fluent_support/ticket_closed', $ticket, $person);
                do_action('fluent_support/ticket_closed_by_' . $person->person_type, $ticket, $person);
            }

            if (!$internalNote) {
                $internalNote = __('Ticket has been closed', 'fluent-support');
            }

            //Keep track in conversation
            Conversation::create([
                'ticket_id'         => $ticket->id,
                'person_id'         => $person->id,
                'conversation_type' => 'internal_info',
                'content'           => $internalNote
            ]);
        }

        return $ticket;
    }

    public function reopen($ticket, $person)
    {
        if ($ticket->status == 'closed') {
            $ticket->status = 'active';
            $ticket->waiting_since = current_time('mysql');
            $ticket->save();

            /*
             * Action on ticket reopen
             *
             * @since v1.0.0
             * @param object $ticket
             * @param object $person
             */
            do_action('fluent_support/ticket_reopen', $ticket, $person);
            do_action('fluent_support/ticket_reopen_by_' . $person->person_type, $ticket, $person);
            Conversation::create([
                'ticket_id'         => $ticket->id,
                'person_id'         => $person->id,
                'conversation_type' => 'internal_info',
                'content'           => __('Ticket has been reopened', 'fluent-support')
            ]);
        }

        return $person;
    }

    public function onAgentChange($ticket, $person)
    {
        do_action('fluent_support/ticket_agent_change', $ticket, $person);
        Conversation::create([
            'ticket_id'         => $ticket->id,
            'person_id'         => $person->id,
            'conversation_type' => 'internal_info',
            'content'           => $ticket->agent->user_id !== $person->user_id ?
                $person->full_name . __(' assigned ', 'fluent-support') . $ticket->agent->full_name . __(' in this ticket', 'fluent-support') :
                $person->full_name .__( ' assign this ticket to self', 'fluent-support')
        ]);

        return $person;
    }

    /**
     * This `addTicketAttachments` method is responsible for adding attachments to ticket
     * @param array $data
     * @param array $disabledFields
     * @param \FluentSupport\App\Models\Ticket $ticket
     * @param object $customer
     * @return \FluentSupport\App\Models\Ticket $ticket
     * @since 1.5.7
     */
    public static function addTicketAttachments($data, $disabledFields, $ticket, $customer)
    {
        Helper::tempImageMoveUploadDir($ticket->id, 'ticket-create');

        if (($attachmentsHashes = Arr::get($data, 'attachments')) && !in_array('file_upload', $disabledFields)) {
            $attachments = Attachment::whereIn('file_hash', $attachmentsHashes)
                ->where('status', 'in-active')
                ->get();

            if ($attachments->isEmpty()) {
                return $ticket;
            }

            $storageDriver = Helper::getUploadDriverKey();

            if ($storageDriver != 'local') {
                foreach ($attachments as $attachment) {
                    do_action_ref_array('fluent_support/finalize_file_upload_' . $storageDriver, [&$attachment, $ticket->id]);
                }
            }

            foreach ($attachments as $attachment) {
                if ($attachment->driver == 'local') {
                    $newFileInfo = UploadService::copyFileTicketFolder($attachment->file_path, $ticket->id);
                    if ($newFileInfo) {
                        $attachment->file_path = $newFileInfo['file_path'];
                        $attachment->full_url = $newFileInfo['url'];
                    }
                }

                $attachment->ticket_id = $ticket->id;
                $attachment->person_id = $customer->id;
                $attachment->status = 'active';
                $attachment->save();
            }

            $ticket->load('attachments');
        }

        return $ticket;
    }

    /**
     * Sanitize ticket data, create the ticket, handle attachments & custom fields, fire hooks.
     * Shared by TicketController::createTicket and ProTicketService::handleSplitTicket.
     *
     * @param array $ticketData
     * @param \FluentSupport\App\Models\Customer $customer
     * @return \FluentSupport\App\Models\Ticket
     */
    public function storeTicket($ticketData, $customer)
    {
        if (empty($ticketData['mailbox_id'])) {
            $mailbox = Helper::getDefaultMailBox();
            if ($mailbox) {
                $ticketData['mailbox_id'] = $mailbox->id;
            }
        } else {
            $mailbox = MailBox::findOrFail($ticketData['mailbox_id']);
        }

        if (!empty($ticketData['product_id'])) {
            $ticketData['product_source'] = 'local';
        }

        $ticketData['title'] = sanitize_text_field(wp_unslash($ticketData['title']));
        $ticketData['content'] = wp_specialchars_decode(wp_unslash(wp_kses_post($ticketData['content'])));

        if (!empty($ticketData['priority'])) {
            $ticketData['priority'] = sanitize_text_field($ticketData['priority']);
        }

        if (!empty($ticketData['client_priority'])) {
            $ticketData['client_priority'] = sanitize_text_field($ticketData['client_priority']);
        }

        $ticketData = apply_filters('fluent_support/create_ticket_data', $ticketData, $customer);

        do_action('fluent_support/before_ticket_create', $ticketData, $customer);

        $createdTicket = Ticket::create($ticketData);

        $disabledFields = apply_filters('fluent_support/disabled_ticket_fields', []);
        self::addTicketAttachments($ticketData, $disabledFields, $createdTicket, $customer);

        if (defined('FLUENTSUPPORTPRO') && !empty($ticketData['custom_fields'])) {
            $createdTicket->syncCustomFields($ticketData['custom_fields']);
        }

        do_action('fluent_support/ticket_created', $createdTicket, $customer);
        do_action('fluent_support/ticket_created_behalf_of_customer', $createdTicket, $customer, Helper::getAgentByUserId());

        return $createdTicket;
    }

    public function deleteTicket($ticket, $agent = null)
    {
        if (!$agent) {
            $agent = Helper::getAgentByUserId();
        }

        $ticketData = [
            'id'    => $ticket->id,
            'title' => $ticket->title
        ];

        do_action('fluent_support/deleting_ticket', $ticket);
        $ticket->delete();
        do_action('fluent_support/ticket_deleted', $agent, $ticketData);
    }

}
