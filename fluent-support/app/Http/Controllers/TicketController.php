<?php

namespace FluentSupport\App\Http\Controllers;

use FluentSupport\App\Models\Meta;
use FluentSupport\Framework\Http\Request\Request;
use FluentSupport\Framework\Support\Arr;
use FluentSupport\App\Http\Requests\TicketRequest;
use FluentSupport\App\Http\Requests\TicketResponseRequest;
use FluentSupport\App\Models\Conversation;
use FluentSupport\App\Models\Ticket;
use FluentSupport\App\Services\FluentBoardsService;
use FluentSupport\App\Services\FluentCRMServices;
use FluentSupport\App\Services\Helper;
use FluentSupport\App\Services\ProfileInfoService;
use FluentSupport\App\Services\TicketHelper;
use FluentSupport\App\Modules\PermissionManager;
use FluentSupport\App\Services\Tickets\TicketService;

/**
 *  TicketController class for REST API related to ticket
 * This class is responsible for getting / inserting/ modifying data for all request related to ticket
 * @package FluentSupport\App\Http\Controllers
 *
 * @version 1.0.0
 */
class TicketController extends Controller
{
    /**
     * This `me` method will return the current user profile info
     * @param Request $request
     * @param ProfileInfoService $profileInfoService
     * @return array
     */
    public function me(Request $request, ProfileInfoService $profileInfoService)
    {
        $user = wp_get_current_user();
        $requestData = $request->all();
        $sanitizedRequest = [];
        foreach ($requestData as $key => $value) {
            if (is_array($value)) {
                $sanitizedRequest[$key] = map_deep($value, 'sanitize_text_field');
            } else {
                $sanitizedRequest[$key] = sanitize_text_field($value);
            }
        }

        $settings = [
            'user_id'     => $user->ID,
            'email'       => $user->user_email,
            'person'      => Helper::getAgentByUserId($user->ID),
            'permissions' => PermissionManager::currentUserPermissions(),
            'request'     => $sanitizedRequest
        ];

        $withPortalSettings = $request->getSafe('with_portal_settings', 'sanitize_text_field');

        return $profileInfoService->me($settings, $withPortalSettings);
    }

    /**
     * index method will return the list of ticket based on the selected filter
     * @param Request $request
     * @return array
     */
    public function index(Request $request, TicketService $ticketService)
    {
        //Selected filter type, either simple or Advanced
        $filterType = $request->getSafe('filter_type', 'sanitize_text_field', 'simple');
        $requestData = $request->all();
        $data = [];
        foreach ($requestData as $key => $value) {
            if (is_array($value)) {
                $data[$key] = map_deep($value, 'sanitize_text_field');
            } else {
                $data[$key] = sanitize_text_field($value);
            }
        }
        return $ticketService->getTickets($data, $filterType);
    }

    /**
     * createTicket method will create new ticket as well as customer or WP user
     * @param Request $request
     * @param Ticket $ticket
     * @return array
     * @throws \Exception
     */
    public function createTicket(TicketRequest $request, Ticket $ticket)
    {
        $data = $request->sanitize();

        $ticketData = $data['ticket'];

        if (!empty($data['attachments'])) {
            $ticketData['attachments'] = $data['attachments'];
        }

        $maybeNewCustomer = $data['newCustomer'];

        $createdTicket = $ticket->createTicket($ticketData, $maybeNewCustomer);

        if (is_wp_error($createdTicket)) {
            return $this->sendError([
                'message' => $createdTicket->get_error_message()
            ]);
        }

        return [
            'message' => 'Ticket has been successfully created',
            'ticket'  => $createdTicket
        ];
    }

    /**
     * getTicket method will return ticket information by ticket id
     * @param Request $request
     * @param $ticket_id
     * @return array
     */
    public function getTicket(Request $request, Ticket $ticket, $ticket_id)
    {
        try {
            $ticketWith = $request->get('with');
            $ticketWith = is_array($ticketWith) ? map_deep($ticketWith, 'sanitize_text_field') : null;

            if (!$ticketWith) {
                $ticketWith = ['customer', 'agent', 'product', 'mailbox', 'tags', 'attachments' => function ($q) {
                    $q->whereIn('status', ['active', 'inline']);
                }];
            }
            $withData = $request->get('with_data', null);
            $withDataArray = is_array($withData) ? map_deep($withData, 'sanitize_text_field') : [];
            $withCrmData = in_array('fluentcrm_profile', $withDataArray);

            return $ticket->getTicket($ticketWith, $withCrmData, $ticket_id);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    /**
     * createResponse method will create response by agent for the ticket
     * @param Request $request
     * @param Ticket $ticket
     * @param int $ticket_id
     * @return array
     * @throws \FluentSupport\Framework\Validator\ValidationException
     */
    public function createResponse(TicketResponseRequest $request, Ticket $ticket, $ticket_id)
    {

        $data = $request->sanitize();

        try {
            return $ticket->createResponse($data, $ticket_id);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    /**
     * createDraft method will create draft by agent for the ticket
     * @param Request $request
     * @param Ticket $ticket
     * @param int $ticket_id
     * @return array
     * @throws \FluentSupport\Framework\Validator\ValidationException
     */
    public function createOrUpdatDraft(TicketResponseRequest $request, Ticket $ticket, $ticket_id)
    {

        $data = $request->sanitize();

        try {
            return $ticket->addOrUpdatDraft($data, $ticket_id);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    public function getDraft(Ticket $ticket, $ticket_id)
    {
        try {
            return $ticket->fetchDraft($ticket_id);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    public function deleteDraft(Ticket $ticket, $draft_id)
    {
        $draft_id = intval($draft_id);

        try {
            return $ticket->removeDraft($draft_id);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    /**
     * getTicketWidgets method generate additional information for a ticket by  customer
     * @param Ticket $ticket
     * @param $ticket_id
     * @return array
     */
    public function getTicketWidgets(Ticket $ticket, $ticket_id)
    {
        try {
            return $ticket->getTicketWidgets($ticket_id);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    /**
     * updateTicketProperty method will update ticket property
     * @param Request $request
     * @param Ticket $ticket
     * @param $ticket_id
     * @return array
     */
    public function updateTicketProperty(Request $request, Ticket $ticket, $ticket_id)
    {
        $propName = $request->getSafe('prop_name', 'sanitize_text_field');
        $propValue = $request->getSafe('prop_value', 'sanitize_text_field');

        try {
            return $ticket->updateTicketProperty($propName, $propValue, $ticket_id);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    /**
     * closeTicket method close the ticket by id
     * @param Ticket $ticket
     * @param int $ticket_id
     * @return array
     */
    public function closeTicket(Ticket $ticket, $ticket_id)
    {
        try {
            return $ticket->closeTicket($ticket_id, $this->request->getSafe('close_ticket_silently', 'rest_sanitize_boolean'));
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    /**
     * reOpenTicket method will reopen a closed ticket
     * @param Request $request
     * @param $ticket_id
     * @return array
     */
    public function reOpenTicket(Ticket $ticket, $ticket_id)
    {
        try {
            return $ticket->reOpenTicket($ticket_id);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    /**
     * doBulkActions method is responsible for bulk action
     * This function will get ticket ids and action as parameter and perform action based on the selection
     * @param Request $request
     * @param Ticket $ticket
     * @return array|string[]|void
     * @throws \Exception
     */
    public function doBulkActions(Request $request, Ticket $ticket)
    {
        $action = $request->getSafe('bulk_action', 'sanitize_text_field'); //get action
        $ticket_ids = $request->getSafe('ticket_ids', null, []);
        $sanitizedTicketIds = array_map('intval', $ticket_ids);

        try {
            return $ticket->handleBulkActions($action, $sanitizedTicketIds);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    /**
     * deleteTicket method will delete a ticket
     * @param Request $request
     * @param TicketService $ticketService
     * @return array
     */
    public function deleteTicket(TicketService $ticketService, $ticket_id)
    {
        $ticket = Ticket::findOrFail($ticket_id);
        try {
            return $ticketService->delete($ticket);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    /**
     * doBulkReplies method will create response for bulk tickets
     * This function will get ticket ids, content, attachment etc and create response for tickets
     * @param Request $request
     * @param Conversation $conversation
     * @return array
     * @throws \Exception
     */
    public function doBulkReplies(Request $request, Conversation $conversation)
    {
        // Sanitize all request data before validation
        $requestData = $request->all();
        $data = [];
        foreach ($requestData as $key => $value) {
            if (is_array($value)) {
                if ($key === 'ticket_ids') {
                    $data[$key] = array_map('intval', $value);
                } elseif ($key === 'content') {
                    $data[$key] = wp_kses_post($value);
                } else {
                    $data[$key] = map_deep($value, 'sanitize_text_field');
                }
            } else {
                $data[$key] = sanitize_text_field($value);
            }
        }

        $this->validate($data, [
            'content'    => 'required',
            'ticket_ids' => 'required|array'
        ]);

        try {
            return $conversation->doBulkReplies($data);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    /**
     * deleteResponse method will remove a response from ticket by ticket id and response id
     * @param Request $request
     * @param Conversation $conversation
     * @param $ticket_id
     * @param $response_id
     * @return array
     */
    public function deleteResponse(Conversation $conversation, $ticket_id, $response_id)
    {
        try {
            return $conversation->deleteResponse($ticket_id, $response_id);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    /**
     * updateResponse method will update ticket response using ticket and response id
     * @param Request $request
     * @param Conversation $conversation
     * @param int $ticket_id
     * @param int $response_id
     * @return array
     * @throws \Exception
     */
    public function updateResponse(TicketResponseRequest $request, Conversation $conversation, $ticket_id, $response_id)
    {
        $data = [
            'content'     => $request->getSafe('content', 'wp_kses_post'),
            'ticket_id'   => $request->getSafe('ticket_id', 'intval'),
            'response_id' => $request->getSafe('response_id', 'intval')
        ];

        try {
            return $conversation->updateResponse($data, $ticket_id, $response_id);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    public function approveDraftResponse(TicketResponseRequest $request, Conversation $conversation, $ticket_id, $response_id)
    {
        $data = [
            'content' => $request->getSafe('content', 'wp_kses_post')
        ];
        $conversationType = 'response';

        try {
            return $conversation->publishDraftResponse($data, $ticket_id, $response_id, $conversationType);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    /**
     * getLiveActivity method will return the activity in a ticket by agents
     * @param Request $request
     * @param $ticket_id
     * @return array
     */
    public function getLiveActivity(Request $request, $ticket_id)
    {
        $agent = Helper::getAgentByUserId();

        return [
            'live_activity' => TicketHelper::getActivity($ticket_id, $agent->id)
        ];
    }

    /**
     * removeLiveActivity method will remove activities that
     * @param Request $request
     * @param $ticket_id
     * @return array
     */
    public function removeLiveActivity(Request $request, $ticket_id)
    {
        $agent = Helper::getAgentByUserId();

        return [
            'result'   => TicketHelper::removeFromActivities($ticket_id, $agent->id),
            'agent_id' => $agent->id
        ];
    }

    /**
     * addTag method will add tag in ticket by ticket id
     * @param Request $request
     * @param $ticket_id
     * @return array
     */
    public function addTag(Request $request, $ticket_id)
    {
        $ticket = Ticket::findOrFail($ticket_id);

        $ticket->applyTags($request->getSafe('tag_id', 'intval'));

        return [
            'message' => __('Tag has been added to this ticket', 'fluent-support'),
            'tags'    => $ticket->tags
        ];
    }

    /**
     * detachTag method will remove all tags from tickets
     * @param $ticket_id
     * @param $tag_id
     * @return array
     */
    public function detachTag($ticket_id, $tag_id)
    {
        $ticket = Ticket::findOrFail($ticket_id);
        $ticket->detachTags($tag_id);

        return [
            'message' => __('Tag has been removed from this ticket', 'fluent-support'),
            'tags'    => $ticket->tags
        ];
    }

    /**
     * changeTicketCustomer method will update customer in a ticket
     * This method will get ticket id and customer id as parameter, it will replace existing customer id with new
     * @param Request $request
     * @return array
     */
    public function changeTicketCustomer(Request $request)
    {
        $ticketId = $request->getSafe('ticket_id', 'intval');
        $newCustomerId = $request->getSafe('customer', 'intval');

        if (!$newCustomerId) {
            return $this->sendError(__('Invalid customer selected.', 'fluent-support'));
        }

        try {
            $updated = Ticket::where('id', $ticketId)
                ->where('customer_id', '!=', $newCustomerId)
                ->update(['customer_id' => $newCustomerId]);

            return $updated
                ? ['message' => __('Customer has been updated', 'fluent-support')]
                : $this->sendError(__('Ticket not found or customer already assigned.', 'fluent-support'));

        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    /**
     * getTicketCustomData method will return the custom data by ticket id
     * @param Request $request
     * @param $ticket_id
     * @return array|array[]
     */
    public function getTicketCustomData(Request $request, $ticket_id)
    {
        if (!defined('FLUENTSUPPORTPRO')) {
            return [
                'custom_data'     => [],
                'rendered_fields' => []
            ];
        }

        $ticket = Ticket::findOrFail($ticket_id);

        return [
            'custom_data'     => (object)$ticket->customData(),
            'rendered_fields' => \FluentSupportPro\App\Services\CustomFieldsService::getRenderedPublicFields($ticket->customer, 'admin')
        ];
    }

    /**
     * syncFluentCrmTags method will synchronize the tags with Fluent CRM by contact id
     *This function will get contact id and tags as parameter, get existing tags from crm and updated added/removed tags
     * @param Request $request
     * @param FluentCRMServices $fluentCRMServices
     * @return array
     */
    public function syncFluentCrmTags(Request $request, FluentCRMServices $fluentCRMServices)
    {
        $data = [
            'contact_id' => $request->getSafe('contact_id', 'intval'),
            'tags'       => $request->getSafe('tags', null, [])
        ];

        // Sanitize tags array if it's an array
        if (is_array($data['tags'])) {
            $data['tags'] = array_map('intval', $data['tags']);
        }

        try {
            return $fluentCRMServices->syncCrmTags($data);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    /**
     * This `syncFluentCrmLists` method will synchronize the lists with Fluent CRM by contact id
     *  This method will get contact id and lists as parameter, get existing lists from crm and updated added/removed lists
     * @param Request $request
     * @param FluentCRMServices $fluentCRMServices
     * @return array
     */

    public function syncFluentCrmLists(Request $request, FluentCRMServices $fluentCRMServices)
    {
        $data = [
            'contact_id' => $request->getSafe('contact_id', 'intval'),
            'lists'      => $request->getSafe('lists', null, [])
        ];

        // Sanitize lists array if it's an array
        if (is_array($data['lists'])) {
            $data['lists'] = array_map('intval', $data['lists']);
        }

        try {
            return $fluentCRMServices->syncCrmLists($data);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    /**
     * Retrieve boards from Fluent Boards API.
     *
     * @return array Formatted array of boards.
     */
    public function getBoards()
    {
        $boards = FluentBoardsApi('boards')->getBoards();
        $formattedBoards = [];

        foreach ($boards as $board) {
            $formattedBoard = [
                'id'    => $board->id,
                'title' => $board->title,
                'tasks' => [],
            ];

            $formattedBoards[] = $formattedBoard;
        }

        return ['boards' => $formattedBoards];
    }

    /**
     * Retrieve stages for a specific board from Fluent Boards API.
     *
     * @param Request $request Request object containing 'board_id'.
     * @return array Formatted array of stages.
     */
    public function getStages(Request $request)
    {
        $boardId = $request->getSafe('board_id', 'intval');
        $boardStages = FluentBoardsApi('boards')->getStagesByBoard($boardId);

        $formattedStages = [];
        if (!empty($boardStages)) {
            foreach ($boardStages[0]->stages as $stage) {
                $formattedStages[] = [
                    'id'    => $stage->id,
                    'title' => $stage->title,
                ];
            }
        }

        return ['stages' => $formattedStages];
    }

    /**
     * Create a task using data provided in the request.
     *
     * @param Request $request Request object containing task data.
     * @return array Response containing message and task data.
     */
    public function createTask(Request $request, FluentBoardsService $fluentBoardsService)
    {
        try {
            $taskData = [
                'source_id'      => $request->getSafe('source_id', 'intval'),
                'board_id'       => $request->getSafe('board_id', 'intval'),
                'stage_id'       => $request->getSafe('stage_id', 'intval'),
                'crm_contact_id' => $request->getSafe('crm_contact_id', 'intval') ?: null,
                'title'          => $request->getSafe('title', 'sanitize_text_field'),
                'description'    => $request->getSafe('description', 'wp_kses_post'),
                'source'         => $request->getSafe('source', 'sanitize_text_field'),
                'started_at'     => $request->getSafe('started_at', 'sanitize_text_field'),
                'due_at'         => $request->getSafe('due_at', 'sanitize_text_field'),
            ];

            $task = FluentBoardsApi('tasks')->create($taskData);

            if (!$task) {
                return $this->sendError(__('Failed to create task.', 'fluent-support'));
            }

            $fluentBoardsService->addInternalNote($task);
            $fluentBoardsService->addComment($task);

            return [
                'message' => __('Task successfully added to Fluent Boards', 'fluent-support'),
                'task'    => $task
            ];
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    /**
     * Get ticket essentials data based on the provided types.
     *
     * @param \Illuminate\Http\Request $request
     * @return array The ticket essentials data.
     */
    public function getTicketEssentials(Request $request)
    {
        $type = $request->getSafe('type', 'sanitize_text_field');

        return TicketHelper::getTicketEssentials($type);
    }

    public function fetchLabelSearch(Ticket $ticket)
    {
        try {
            $agent_id = get_current_user_id();
            return TicketHelper::getLabelSearch($agent_id);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    public function storeOrUpdateLabelSearch(Request $request)
    {
        try {
            $agent_id = get_current_user_id();
            $searchData = $request->getSafe('query', null, []);
            if (is_array($searchData)) {
                $searchData = map_deep($searchData, 'sanitize_text_field');
            }
            $filterType = Arr::get($searchData, 'filter_type', '');
            if ($filterType == 'advanced') {
                return TicketHelper::saveSearchLabel($agent_id, $searchData, $filterType);
            }

            return [
                'message' => __('Invalid filter type.', 'fluent-support'),
            ];

        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    public function deleteLabelSearch(Request $request, $search_id)
    {
        try {
            $agent_id = get_current_user_id();
            return TicketHelper::deleteSavedSearch($search_id);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
}

