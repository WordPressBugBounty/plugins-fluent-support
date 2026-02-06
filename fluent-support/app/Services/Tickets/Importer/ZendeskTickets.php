<?php

namespace FluentSupport\App\Services\Tickets\Importer;
class ZendeskTickets extends BaseImporter
{
    protected $handler = 'zendesk';
    public $accessToken;
    public $mailbox_id;
    private $domain;
    private $email;
    protected $limit;
    private $hasMore;
    private $currentPage;
    private $totalTickets;
    private $originId;
    private $responseCount;
    private $totalPage;
    private $errorMessage;

    public function stats()
    {
        return [
            'name' => esc_html('Zendesk'),
            'handler' => $this->handler,
            'type' => 'sass',
            'last_migrated' => get_option('_fs_migrate_zendesk')
        ];
    }

    public function doMigration($page, $handler)
    {

        $this->currentPage = $page;
        $this->handler = $handler;
        $this->errorMessage = null;
        $tickets = $this->ticketsWithReply();
        $results = $this->migrateTickets($tickets);

        $this->totalPage = $this->limit > 0 ? ceil($this->totalTickets / $this->limit) : 0;
        
        $this->hasMore = $this->currentPage < $this->totalPage;
        $completedNow = isset($results['inserts']) ? count($results['inserts']) : 0;
        $completedTickets = $completedNow + (($this->currentPage - 1) * $this->limit);
        $remainingTickets = $this->totalTickets - $completedTickets;
        
        $completed = $this->totalTickets > 0 ? intval(($completedTickets / $this->totalTickets) * 100) : 0;

        $response = [
            'handler' => $this->handler,
            'insert_ids' => $results['inserts'],
            'skips' => count($results['skips']),
            'has_more' => $this->hasMore,
            'completed' => $completed,
            'imported_page' => $page,
            'total_pages' => $this->totalPage,
            'next_page' => $page + 1,
            'total_tickets' => $this->totalTickets,
            'remaining' => $remainingTickets
        ];

        // Handle errors or success
        if ($this->errorMessage) {
            $response['error'] = true;
            $response['message'] = $this->errorMessage;
        } elseif (!$this->hasMore && ($this->totalTickets > 0 || $completedNow > 0)) {
            $response['message'] = __('All tickets have been imported successfully', 'fluent-support');
            update_option('_fs_migrate_zendesk', current_time('mysql'), 'no');
        }

        return $response;
    }

    private function ticketsWithReply()
    {
        try {
            // Initialize totalTickets to prevent undefined
            $this->totalTickets = 0;
            
            $this->totalTickets = $this->countTotalTickets();
            $url = "{$this->domain}/api/v2/tickets?per_page={$this->limit}&page={$this->currentPage}";
            $tickets = $this->makeRequest($url);

            $formattedTickets = [];
            if (empty($tickets)) {
                $this->hasMore = false;
                return [];
            }

            $this->hasMore = true;
            foreach ($tickets->tickets as $ticket) {
                $singleTicketUrl = $this->domain . '/api/v2/tickets/' . $ticket->id . '/comments.json?include=attachments,users';
                $singleTicket = $this->makeRequest($singleTicketUrl);
                $this->originId = $ticket->id;
                $ticketAttacments  = [];
                if (!empty($singleTicket->comments[0]->attachments)) {
                    $ticketAttacments = $this->getAttachments($singleTicket->comments[0]->attachments);
                }

                $formattedTickets[] = [
                    'title' => sanitize_text_field($ticket->subject),
                    'content' => wp_kses_post($ticket->description),
                    'origin_id' => intval($ticket->id),
                    'source' => sanitize_text_field($this->handler),
                    'customer' => $this->fetchPerson($ticket->requester_id),
                    'replies' => $this->getReplies($singleTicket),
                    'status' => $this->getStatus($ticket->status),
                    'client_priority' => $this->getPriority($ticket->priority),
                    'priority' => $this->getPriority($ticket->priority),
                    'created_at' => $ticket->created_at ? gmdate('Y-m-d h:i:s', strtotime($ticket->created_at)) : null,
                    'updated_at' => $ticket->updated_at ? gmdate('Y-m-d h:i:s', strtotime($ticket->updated_at)) : null,
                    'last_customer_response' => NULL,
                    'last_agent_response' => NULL,
                    'attachments' => $ticketAttacments
                ];
            }

            return $formattedTickets;

        } catch (\Exception $e) {
            // Store error message for authentication errors
            $errorMsg = $e->getMessage();
            if (strpos($errorMsg, 'authenticate') !== false || 
                strpos($errorMsg, 'Couldn\'t authenticate') !== false ||
                strpos($errorMsg, '401') !== false) {
                $this->errorMessage = __('Authentication failed. Please check your Zendesk credentials.', 'fluent-support');
            } else {
                // Store any other error message
                $this->errorMessage = $errorMsg;
            }
            // Ensure totalTickets is set to 0 on error
            if (!isset($this->totalTickets)) {
                $this->totalTickets = 0;
            }
            return [];
        }
    }

    private function getReplies($replies)
    {
        unset($replies->comments[0]);
        $formattedReplies = [];
        foreach ($replies->comments as $reply) {
            $ticketReply = [
                'content' => wp_kses_post($reply->body),
                'conversation_type' => 'response',
                'created_at' => $reply->created_at ? gmdate('Y-m-d h:i:s', strtotime($reply->created_at)) : null,
                'updated_at' => !empty($reply->updated_at) ? gmdate('Y-m-d h:i:s', strtotime($reply->updated_at)) : null,
            ];

            $ticketReply = $this->populatePersonInfo($ticketReply, $reply, $replies->users);

            if (!empty($reply->attachments) && count($reply->attachments)) {
                $ticketReply['attachments'] = $this->getAttachments($reply->attachments);
            }

            $formattedReplies[] = $ticketReply;
        }

        return $formattedReplies;
    }

    private function populatePersonInfo($ticketReply,$reply,$users)
    {
        foreach ($users as $user) {
            if ($user->id !== $reply->author_id) {
                continue;
            }

            $ticketReply['is_customer_reply'] = $user->role === 'end-user';
            $type = $user->role === 'end-user' ? 'user' : 'agent';
            $ticketReply['user'] = Common::formatPersonData($user, $type);
            break;
        }

        return $ticketReply;
    }

    private function makeRequest($url)
    {
        $token = base64_encode($this->email . '/token:' . $this->accessToken);

        $request = wp_remote_get($url, [
            'headers' => [
                'Authorization' => "Basic {$token}",
                'Content-Type' => 'application/json'
            ]
        ]);

        if (is_wp_error($request)) {
            throw new \Exception('Error while making request: ' . esc_html($request->get_error_message()));
        }

        $response_code = wp_remote_retrieve_response_code($request);
        $response_body = wp_remote_retrieve_body($request);
        $response = json_decode($response_body);

        // If status code is 200, don't throw error - check response body for errors instead
        if ($response_code === 200) {
            // Check for errors in response body
            if (isset($response->error)) {
                $error_msg = $response->error;
                if (isset($response->description)) {
                    $error_msg .= ': ' . $response->description;
                }
                throw new \Exception(esc_html($error_msg));
            }
            return $response;
        }

        // Handle non-200 status codes
        if ($response_code === 401) {
            throw new \Exception('Couldn\'t authenticate you');
        }

        // Other error status codes
        $error_msg = 'HTTP Error ' . $response_code;
        if (isset($response->error)) {
            $error_msg = $response->error;
            if (isset($response->description)) {
                $error_msg .= ': ' . $response->description;
            }
        }
        throw new \Exception(esc_html($error_msg));
    }

    private function fetchPerson($requesterId)
    {
        $userUrl = $this->domain . '/api/v2/users/' . $requesterId . '.json';
        $fetchUser = $this->makeRequest($userUrl);

        $user = (object)[
            'name' => $fetchUser->user->name,
            'address' => $fetchUser->user->email
        ];

        $personArray = Common::formatPersonData($user, 'customer');
        return Common::updateOrCreatePerson($personArray);
    }

    private function countTotalTickets()
    {
        $url = "{$this->domain}/api/v2/tickets/count.json";
        $count = $this->makeRequest($url);
        return $count->count->value;
    }

    private function getAttachments($attachments)
    {
        $wpUploadDir = wp_upload_dir();
        $baseDir = $wpUploadDir['basedir'] . '/fluent-support/zendesk-ticket-' . $this->originId . '/';

        $formattedAttachments = [];
        foreach ($attachments as $attachment) {
            $filePath = Common::downloadFile($attachment->content_url, $baseDir, $attachment->file_name);
            $fileUrl = $wpUploadDir['baseurl'] . '/fluent-support/zendesk-ticket-' . $this->originId . '/' . $attachment->file_name;
            $formattedAttachments[] = [
                'full_url' => $fileUrl,
                'title' => $attachment->file_name,
                'file_path' => $filePath,
                'driver' => 'local',
                'status' => 'active',
                'file_type' => $attachment->content_type
            ];
        }

        return $formattedAttachments;
    }

    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;
    }

    public function setDomain($domain)
    {
        $this->domain = $domain;
    }

    public function setEmail($email)
    {
        $this->email = $email;
    }

    private function setResponseCount($count)
    {
        $this->responseCount = $count;
    }

    private function getStatus($status)
    {
        switch ($status) {
            case 'open':
                return 'active';
            case 'pending':
                return 'waiting';
            case 'solved':
                return 'closed';
            default:
                return 'active';
        }

    }

    private function getPriority($priority)
    {
        switch ($priority) {
            case 'low':
            case 'normal':
                return 'normal';
            case 'high':
                return 'medium';
            case 'urgent':
                return 'critical';
            default:
                return 'normal';
        }
    }

    public function deleteTickets($page)
    {
        return;
    }
}
