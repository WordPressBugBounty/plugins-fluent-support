<?php

namespace FluentSupport\App\Http\Controllers;

use FluentSupport\App\Models\Agent;
use FluentSupport\App\Models\Attachment;
use FluentSupport\App\Models\Meta;
use FluentSupport\App\Models\Customer;
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
use FluentSupport\App\Services\TicketQueryService;
use FluentSupport\App\Modules\PermissionManager;
use FluentSupport\App\Services\Tickets\ResponseService;
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
     * @return array
     */
    public function me(Request $request)
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

        if ($request->getSafe('with_portal_settings', 'sanitize_text_field')) {
            $mimeHeadings = Helper::getAcceptedMimeHeadings();
            $businessSettings = (new \FluentSupport\App\Services\EmailNotification\Settings())->globalBusinessSettings();
            $maxFileSize = absint($businessSettings['max_file_size']);

            $portalSettings = [
                'support_products'           => \FluentSupport\App\Models\Product::select(['id', 'title'])->get(),
                'customer_ticket_priorities' => Helper::customerTicketPriorities(),
                'has_file_upload'            => !!Helper::ticketAcceptedFileMiles(),
                'has_rich_text_editor'       => true,
                'max_file_size'              => $maxFileSize,
                'mime_headings'              => $mimeHeadings
            ];

            $portalSettings = apply_filters('fluent_support/customer_portal_vars', $portalSettings);
            $settings['portal_settings'] = $portalSettings;
        }

        return $settings;
    }

    /**
     * index method will return the list of ticket based on the selected filter
     * @param Request $request
     * @return array
     */
    public function index(Request $request)
    {
        //Selected filter type, either simple or Advanced
        $filterType = $request->getSafe('filter_type', 'sanitize_text_field', 'simple');

        /*Prepare Query Arguments*/
        $queryArgs = [
            'with'        => [],
            'filter_type' => $filterType,
            'sort_by'     => sanitize_sql_orderby($request->getSafe('order_by', 'sanitize_text_field', 'id')),
            'sort_type'   => $request->getSafe('order_type', 'sanitize_text_field', 'DESC') == 'DESC' ? 'DESC' : 'ASC',
        ];

        //If the selected filter type is advanced
        if ($filterType == 'advanced') {
            $advanced_filters = map_deep($request->get('advanced_filters', []), 'sanitize_text_field');
            //Get the selected query params for advanced filter
            $queryArgs['filters_groups_raw'] = json_decode($advanced_filters, true);
        } else {
            //Selected filter type is simple
            $queryArgs['simple_filters'] = map_deep($request->get('filters', []), 'sanitize_text_field');
            $queryArgs['search'] = trim($request->getSafe('search', 'sanitize_text_field', ''));

            if ($customerId = $request->getSafe('customer_id', 'intval')) {
                $queryArgs['customer_id'] = $customerId;
            }
        }
        /*End Prepare Query Arguments*/

        $ticketsModel = (new TicketQueryService($queryArgs))->getModel();

        $ticketsModel = $ticketsModel->with([
            'customer'         => function ($query) {
                $query->select(['first_name', 'last_name', 'email', 'id', 'avatar']);
            }, 'agent'         => function ($query) {
                $query->select(['first_name', 'last_name', 'email', 'avatar', 'id']);
            },
            'mailbox',
            'product',
            'tags',
            'preview_response' => function ($query) {
                $query->latest('id');
            }
        ]);

        // apply filters by access level
        do_action_ref_array('fluent_support/tickets_query_by_permission_ref', [&$ticketsModel, false]);

        $tickets = $ticketsModel->paginate();

        $perPage = $request->getSafe('per_page', 'intval', 15);

        foreach ($tickets as $ticket) {
            if ($perPage < 15) {
                if ($ticket->status != 'closed') {
                    $ticket->live_activity = TicketHelper::getActivity($ticket->id);
                } else {
                    $ticket->live_activity = [];
                }
            }
        }

        return [
            'tickets' => $tickets
        ];
    }

    /**
     * createTicket method will create new ticket as well as customer or WP user
     * @param TicketRequest $request
     * @return array
     */
    public function createTicket(TicketRequest $request)
    {
        try {
            //Sanitize and validate request data via TicketRequest
            $data = $request->sanitize();
            $ticketData = $data['ticket'];
            $maybeNewCustomer = Arr::get($data, 'newCustomer', []);

            //Include attachments if provided
            if (!empty($data['attachments'])) {
                $ticketData['attachments'] = $data['attachments'];
            }

            /*
             * If customer_id is not provided, attempt to create a new customer
             * This handles WP user creation and customer creation
             */
            if (empty($ticketData['customer_id'])) {
                $createdUserId = false;

                //If user selected create WP user during ticket creation
                if (Arr::get($ticketData, 'create_wp_user') == 'yes' && !empty($maybeNewCustomer['username'])) {
                    //Check if username already in use, if not create new user
                    if (!username_exists($maybeNewCustomer['username'])) {
                        $authController = new AuthController();
                        $createdUserId = $authController->createUser($maybeNewCustomer);
                        $authController->maybeUpdateUser($createdUserId, $maybeNewCustomer);
                    }
                }

                $email = Arr::get($maybeNewCustomer, 'email');
                if (!$email || !is_email($email)) {
                    return $this->sendError([
                        'message' => __('A valid email is required to create a ticket', 'fluent-support')
                    ]);
                }

                //Check if customer already exists by email
                $existingCustomer = Customer::where('email', $email)->first();

                if ($existingCustomer) {
                    $ticketData['customer_id'] = $existingCustomer->id;
                } else {
                    //Create the customer now
                    $customerData = Arr::only($maybeNewCustomer, (new Customer())->getFillable());
                    $customerData['user_id'] = $createdUserId;
                    $customerData = array_filter($customerData);

                    $createCustomer = Customer::create($customerData);

                    do_action('fluent_support/customer_created', $createCustomer);

                    if (!$createCustomer) {
                        return $this->sendError([
                            'message' => __('Customer could not be created', 'fluent-support')
                        ]);
                    }

                    $ticketData['customer_id'] = $createCustomer->id;
                }
            }

            //Get customer information from db
            $customer = Customer::findOrFail($ticketData['customer_id']);

            //Sanitize, store ticket, handle attachments, fire hooks
            $createdTicket = (new TicketService())->storeTicket($ticketData, $customer);

            return [
                'message' => __('Ticket has been created successfully', 'fluent-support'),
                'ticket'  => $createdTicket
            ];
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * getTicket method will return ticket information by ticket id
     * @param Request $request
     * @param $ticket_id
     * @return array
     */
    public function getTicket(Request $request, $ticket_id)
    {
        try {
            //Get logged in agent information
            $agent = Helper::getAgentByUserId();

            $ticketWith = $request->get('with');
            $ticketWith = is_array($ticketWith) ? map_deep($ticketWith, 'sanitize_text_field') : null;

            if (!$ticketWith) {
                $ticketWith = ['customer', 'agent', 'product', 'mailbox', 'tags', 'attachments' => function ($q) {
                    $q->whereIn('status', ['active', 'inline']);
                }];
            }

            //Get ticket by id
            $ticket = Ticket::with($ticketWith)->findOrFail($ticket_id);

            //Check if ticket is in a restricted mailbox
            $restrictedBusinessBoxes = PermissionManager::getRestrictedMailboxIds();

            if (in_array($ticket->mailbox_id, $restrictedBusinessBoxes)) {
                throw new \Exception(esc_html__('Ticket cannot be fetched due to restricted mailbox', 'fluent-support'));
            }

            $this->ensureCanAccessTicket($ticket);

            //If ticket has customer, set custom fields and profile url
            if ($ticket->customer) {
                $customFieldsKey = apply_filters('fluent_support/custom_registration_form_fields_key', Helper::getBusinessSettings('custom_registration_form_field'));
                $ticket->customer->custom_field_keys = $customFieldsKey;

                if ($ticket->customer->user_id) {
                    $customFieldKeysUsingHook = apply_filters('fluent_support/custom_registration_form_fields_key', []);
                    foreach ($customFieldKeysUsingHook as $key) {
                        $userMeta = get_user_meta($ticket->customer->user_id, $key, true);
                        if ($userMeta) {
                            $ticket->customer->$key = $userMeta;
                        }
                    }
                }

                $ticket->customer->profile_edit_url = $ticket->customer->getUserProfileEditUrl();
            }

            //If ticket is closed, load closed by person
            if ($ticket->status == 'closed') {
                $ticket->load('closed_by_person');
            }

            //Load agent feedback ratings if pro is active and feature is enabled
            if (defined('FLUENTSUPPORTPRO_PLUGIN_VERSION') && Helper::isAgentFeedbackEnabled()) {
                foreach ($ticket->responses as $response) {
                    $agentFeedback = Meta::where('object_id', $response->id)
                        ->where('object_type', 'conversation_meta')
                        ->where('key', 'agent_feedback_ratings')
                        ->first();

                    if ($agentFeedback) {
                        $response->agent_feedback = $agentFeedback->value;
                    }
                }
            }

            //Format response content
            foreach ($ticket->responses as $response) {
                $response->content = links_add_target(make_clickable(wpautop($response->content, false)));
                if (!empty($response->ccinfo)) {
                    $val = Helper::safeUnserialize($response->ccinfo->value);
                    if (isset($val['cc_email']) && !empty($val['cc_email'])) {
                        $response->cc_info = $val['cc_email'];
                    } else {
                        $response->cc_info = '';
                    }
                } else {
                    $response->cc_info = '';
                }
            }

            $ticket->content = links_add_target(make_clickable(wpautop($ticket->content, false)));

            //Get last activity by agent
            $ticket->live_activity = TicketHelper::getActivity($ticket->id, $agent->id);

            //Get all carbon copy customer
            $ccInfo = $ticket->getSettingsValue('cc_email', []);
            $ticket->carbon_copy = !empty($ccInfo) ? implode(', ', $ccInfo) : '';

            if (defined('FLUENTSUPPORTPRO')) {
                $ticket->custom_fields = $ticket->customData('admin', true);
            }

            $data = [
                'ticket'    => $ticket,
                'responses' => $ticket->responses,
                'agent_id'  => $agent->id
            ];

            if (defined('FLUENTSUPPORTPRO') && $ticket->watchers) {
                $data['watchers'] = TicketHelper::getWatchers($ticket->watchers);
            }

            $withData = $request->get('with_data', null);
            $withDataArray = is_array($withData) ? map_deep($withData, 'sanitize_text_field') : [];

            if (defined('FLUENTCRM') && in_array('fluentcrm_profile', $withDataArray)) {
                $data['fluentcrm_profile'] = Helper::getFluentCrmContactData($ticket->customer);
            }

            return $data;
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
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
    public function createResponse(TicketResponseRequest $request, $ticket_id)
    {
        $data = $request->sanitize();

        try {
            $convoType = Arr::get($data, 'conversation_type', 'response');
            $isDraft = $convoType === 'draft_response';

            if (!$isDraft) {
                $this->ensureCanManageTickets();
            }

            //Get logged-in agent information
            $agent = Helper::getAgentByUserId();

            if (!$agent) {
                return $this->sendError([
                    'message' => __('Sorry, You do not have permission. Please add yourself as support agent first', 'fluent-support')
                ]);
            }

            $ticket = Ticket::findOrFail($ticket_id);

            $this->ensureCanAccessTicket($ticket);

            $responseData = (new ResponseService())->createResponse($data, $agent, $ticket);

            $responseData['response']->content = wp_specialchars_decode(wpautop($responseData['response']->content, false));

            return [
                'message'     => __('Response has been added', 'fluent-support'),
                'response'    => $responseData['response'],
                'ticket'      => $responseData['ticket'],
                'update_data' => $responseData['update_data']
            ];
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
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
    public function createOrUpdatDraft(TicketResponseRequest $request, $ticket_id)
    {
        $data = $request->sanitize();

        try {
            //Get logged-in agent information
            $agent = Helper::getAgentByUserId();

            if (!$agent) {
                return $this->sendError([
                    'message' => __('Sorry, You do not have permission. Please add yourself as support agent first', 'fluent-support')
                ]);
            }

            $ticket = Ticket::findOrFail($ticket_id);

            $this->ensureCanAccessTicket($ticket);

            $key = 'ticket_no_' . $ticket_id . '_agent_id_' . $agent->id . '_response_draft';
            $previousDraft = Meta::where('key', $key)->first();

            if ($data['draftID'] || $previousDraft) {
                Meta::where('key', $key)->update([
                    'value' => maybe_serialize($data)
                ]);

                return [
                    'message' => __('Draft has been updated', 'fluent-support'),
                    'draftID' => $data['draftID']
                ];
            }

            $draftID = Meta::insertGetId([
                'object_type' => '_fs_auto_draft',
                'object_id'   => $ticket_id,
                'key'         => $key,
                'value'       => maybe_serialize($data)
            ]);

            return [
                'message' => __('Draft has been added', 'fluent-support'),
                'draftID' => $draftID
            ];
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getDraft($ticket_id)
    {
        try {
            //Get logged-in agent information
            $agent = Helper::getAgentByUserId();

            if (!$agent) {
                return $this->sendError([
                    'message' => __('Sorry, You do not have permission. Please add yourself as support agent first', 'fluent-support')
                ]);
            }

            $ticket = Ticket::findOrFail($ticket_id);

            $this->ensureCanAccessTicket($ticket);

            $key = 'ticket_no_' . $ticket_id . '_agent_id_' . $agent->id . '_response_draft';

            $draft = Meta::where([
                'object_type' => '_fs_auto_draft',
                'key'         => $key,
            ])->first();

            if ($draft) {
                $draft->value = Helper::safeUnserialize($draft->value);
            }

            return [
                'draft' => $draft
            ];
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function deleteDraft($draft_id)
    {
        $draft_id = intval($draft_id);

        try {
            Meta::where('id', $draft_id)->delete();

            return [
                'message' => __('Discard draft successfully', 'fluent-support'),
            ];
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * getTicketWidgets method generate additional information for a ticket by  customer
     * @param Ticket $ticket
     * @param $ticket_id
     * @return array
     */
    public function getTicketWidgets($ticket_id)
    {
        try {
            //Get ticket with customer by ticket id
            $ticket = Ticket::with('customer')->findOrFail($ticket_id);

            $this->ensureCanAccessTicket($ticket);

            //Get last N tickets of this customer except this
            $limit = apply_filters('fluent_support/previous_ticket_widgets_limit', 10);

            $otherTickets = Ticket::where('id', '!=', $ticket_id)
                ->select(['id', 'title', 'status', 'created_at'])
                ->where('customer_id', $ticket->customer_id)
                ->latest('id')
                ->limit($limit)
                ->get();

            return [
                'other_tickets' => $otherTickets,
                'extra_widgets' => ProfileInfoService::getProfileExtraWidgets($ticket->customer)
            ];
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * updateTicketProperty method will update ticket property
     * @param Request $request
     * @param Ticket $ticket
     * @param $ticket_id
     * @return array
     */
    public function updateTicketProperty(Request $request, $ticket_id)
    {
        try {
            $assigner = Helper::getAgentByUserId();
            $ticket = Ticket::findOrFail($ticket_id);

            $this->ensureCanAccessTicket($ticket);

            $propName = $request->getSafe('prop_name', 'sanitize_text_field');
            $propValue = $request->getSafe('prop_value', 'sanitize_text_field');
            $prevValue = $ticket->{$propName};

            //Validate agent assignment restrictions
            if ($propName === 'agent_id') {
                if (!PermissionManager::currentUserCan('fst_assign_agents')) {
                    throw new \Exception(esc_html__('Permission denied to assign agent', 'fluent-support'), 403);
                }

                $agent = Agent::findOrFail($propValue);
                $restrictions = $agent->getMeta('agent_restrictions', []);

                if (!empty($restrictions['restrictedBusinessBoxes'])) {
                    $mailboxId = (int) $ticket->mailbox_id;
                    if (in_array($mailboxId, $restrictions['restrictedBusinessBoxes'], true)) {
                        throw new \Exception(esc_html__('Agent is restricted for this mailbox ticket', 'fluent-support'), 403);
                    }
                }
            }

            if ($propName && $propValue && $prevValue != $propValue) {
                $ticket->{$propName} = $propValue;
                $ticket->save();
            }

            $updateData = [];

            if ($propName == 'product_id') {
                $ticket->load('product');
                $updateData['product'] = $ticket->product;
            } else if ($propName == 'agent_id') {
                $ticket->load('agent');
                $updateData['agent'] = $ticket->agent;
                $updateData['assigner'] = (new TicketService())->onAgentChange($ticket, $assigner);
                if ($prevValue != $ticket->{$propName}) {
                    do_action('fluent_support/agent_assigned_to_ticket', $ticket->agent, $ticket, $assigner);
                }
            }

            $message = sprintf(
                /* translators: %s: The name of the property that was updated */
                __('%s has been updated', 'fluent-support'),
                esc_html(str_replace('_', ' ', ucwords((string) $propName)))
            );

            return [
                'message'     => $message,
                'update_data' => $updateData
            ];
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * closeTicket method close the ticket by id
     * @param Ticket $ticket
     * @param int $ticket_id
     * @return array
     */
    public function closeTicket(Request $request, $ticket_id)
    {
        try {
            $agent = Helper::getAgentByUserId();
            $ticket = Ticket::findOrFail($ticket_id);

            $this->ensureCanAccessTicket($ticket);

            $closeSilently = $request->getSafe('close_ticket_silently', 'rest_sanitize_boolean');

            return [
                'message' => __('Ticket has been closed', 'fluent-support'),
                'ticket'  => (new TicketService())->close($ticket, $agent, '', $closeSilently)
            ];
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * reOpenTicket method will reopen a closed ticket
     * @param Request $request
     * @param $ticket_id
     * @return array
     */
    public function reOpenTicket($ticket_id)
    {
        try {
            $agent = Helper::getAgentByUserId();
            $ticket = Ticket::findOrFail($ticket_id);

            $this->ensureCanAccessTicket($ticket);

            return [
                'message' => __('Ticket has been opened again', 'fluent-support'),
                'ticket'  => (new TicketService())->reopen($ticket, $agent)
            ];
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
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
    public function doBulkActions(Request $request)
    {
        try {
            $action = $request->getSafe('bulk_action', 'sanitize_text_field');
            $ticketIds = array_map('intval', $request->get('ticket_ids', null, []));

            $hasAllPermission = PermissionManager::currentUserCan('fst_manage_other_tickets');
            $agent = Helper::getAgentByUserId();
            $query = Ticket::whereIn('id', $ticketIds);

            //If agent do not have permission to manage other tickets
            if (!$hasAllPermission) {
                $query->where('agent_id', $agent->id);
            }

            //If bulk action is close tickets
            if ($action == 'close_tickets') {
                $tickets = $query->get();
                $tickets->each(function ($ticket) use ($agent) {
                    (new TicketService())->close($ticket, $agent);
                });

                return [
                    'message' => sprintf(
                        /* translators: %d represents the number of closed tickets. */
                        __('%d tickets have been closed.', 'fluent-support'),
                        count($tickets)
                    )
                ];
            } else if ($action == 'delete_tickets') {
                $tickets = $query->get();
                $ticketService = new TicketService();

                foreach ($tickets as $ticket) {
                    $ticketService->deleteTicket($ticket, $agent);
                }

                return [
                    'message' => sprintf(
                        /* translators: %d is the number of tickets that were deleted */
                        __('%d tickets have been deleted', 'fluent-support'),
                        count($tickets)
                    )
                ];
            } else if ($action == 'assign_agent') {
                if (!$request->has('agent_id')) {
                    throw new \Exception(esc_html__('agent_id param is required', 'fluent-support'));
                }

                $assignAgent = Agent::findOrFail($request->getSafe('agent_id', 'intval'));

                $query->where(function ($q) use ($assignAgent) {
                    $q->where('agent_id', '!=', $assignAgent->id)
                        ->orWhereNull('agent_id');
                });

                $tickets = $query->get();
                $assignedCount = 0;
                $skippedCount = 0;

                $tickets->each(function ($ticket) use ($assignAgent, $agent, &$assignedCount, &$skippedCount) {
                    $restrictions = $assignAgent->getMeta('agent_restrictions', []);

                    //Skip ticket if mailbox is restricted for the agent
                    if (!empty($restrictions) && in_array($ticket->mailbox_id, $restrictions['restrictedBusinessBoxes'])) {
                        $skippedCount++;
                        return;
                    }

                    $ticket->agent_id = $assignAgent->id;
                    $ticket->save();
                    $assignedCount++;

                    do_action('fluent_support/agent_assigned_to_ticket', $assignAgent, $ticket, $agent);
                });

                $assignedMessage = sprintf(
                    /* translators: %1$d is the number of tickets assigned, %2$s is the agent's name. */
                    __('%1$d tickets have been assigned to %2$s.', 'fluent-support'),
                    $assignedCount,
                    $assignAgent->full_name
                );

                $skippedMessage = $skippedCount > 0
                    ? sprintf(
                        /* translators: %1$d is the number of skipped tickets due to mailbox restrictions. */
                        __('%1$d tickets were skipped due to mailbox restrictions or already being assigned.', 'fluent-support'),
                        $skippedCount
                    )
                    : '';

                return [
                    'message' => trim($assignedMessage . ' ' . $skippedMessage)
                ];
            } else if ($action == 'assign_tags') {
                $tagIds = $request->get('tag_ids', null);
                if (!is_array($tagIds)) {
                    $tagIds = [];
                }
                $tags = array_filter(array_map('absint', $tagIds));

                $query->get()->each(function ($ticket) use ($tags) {
                    $ticket->applyTags($tags);
                });

                return [
                    'message' => __('Selected tags has been added to tickets', 'fluent-support')
                ];
            }

            throw new \Exception(esc_html__('Sorry no action found as available', 'fluent-support'));
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * deleteTicket method will delete a ticket
     * @param int $ticket_id
     * @return array
     */
    public function deleteTicket($ticket_id)
    {
        try {
            $ticket = Ticket::findOrFail($ticket_id);

            (new TicketService())->deleteTicket($ticket);

            return [
                'message' => __('Ticket has been deleted successfully', 'fluent-support')
            ];
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
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
    public function doBulkReplies(Request $request)
    {
        try {
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

            //Get logged in agent information
            $agent = Helper::getAgentByUserId();
            $ticketIds = array_filter($data['ticket_ids'], 'absint');

            $hasAllPermission = PermissionManager::currentUserCan('fst_manage_other_tickets');
            $query = Ticket::whereIn('id', $ticketIds)->where('status', '!=', 'closed');

            //If the agent does not have permission
            if (!$hasAllPermission) {
                $query->where('agent_id', $agent->id);
            }

            $tickets = $query->get();

            if ($tickets->isEmpty()) {
                throw new \Exception(esc_html__('Sorry no tickets found based on your filter and bulk actions', 'fluent-support'));
            }

            $responseData = [
                'content'           => wp_kses_post(Arr::get($data, 'content', '')),
                'conversation_type' => 'response',
                'close_ticket'      => Arr::get($data, 'close_ticket'),
            ];

            //If request with file attachments
            $attachmentHashes = Arr::get($data, 'attachments', []);
            $attachments = false;
            if ($attachmentHashes) {
                $attachments = Attachment::whereNull('ticket_id')
                    ->orderBy('id', 'asc')
                    ->whereIn('file_hash', $attachmentHashes)
                    ->get();
            }

            $responseService = new ResponseService();

            foreach ($tickets as $ticket) {
                if ($attachments) {
                    $responseData['attachments'] = [];
                    foreach ($attachments as $attachment) {
                        $attachedFile = $attachment->replicate();
                        $attachedFile->ticket_id = $ticket->id;
                        $attachedFile->save();
                        $responseData['attachments'][] = $attachedFile->file_hash;
                    }
                }

                $responseService->createResponse($responseData, $agent, $ticket);
            }

            return [
                'message' => __('Response has been added to the selected tickets', 'fluent-support')
            ];
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
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
    public function deleteResponse($ticket_id, $response_id)
    {
        try {
            $ticket = Ticket::findOrFail($ticket_id);
            $response = Conversation::findOrFail($response_id);
            $agent = Helper::getAgentByUserId();

            if (!PermissionManager::currentUserCan('fst_delete_tickets') && $ticket->agent_id !== $agent->id) {
                throw new \Exception(
                    esc_html__('Sorry, you do not have permission to delete this response.', 'fluent-support')
                );
            }

            Conversation::where('id', $response->id)->delete();
            $response->ccinfo()->delete();

            return [
                'message' => __('Selected response has been deleted', 'fluent-support')
            ];
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * updateResponse method will update ticket response using ticket and response id
     * @param Request $request
     * @param int $ticket_id
     * @param int $response_id
     * @return array
     * @throws \Exception
     */
    public function updateResponse(TicketResponseRequest $request, $ticket_id, $response_id)
    {
        try {
            $ticket = Ticket::findOrFail($ticket_id);
            $response = Conversation::findOrFail($response_id);
            $agent = Helper::getAgentByUserId();

            if (!PermissionManager::currentUserCan('fst_manage_other_tickets') && $ticket->agent_id !== $agent->id) {
                throw new \Exception(
                    esc_html__('Sorry, you do not have permission to update this response.', 'fluent-support')
                );
            }

            $response->content = wp_unslash(wp_kses_post($request->getSafe('content', 'wp_kses_post')));

            //If updating a draft response by someone other than the author, check approval permission
            if ($response->conversation_type == 'draft_response' && $response->person_id != $agent->id) {
                if (!PermissionManager::currentUserCan('fst_approve_draft_reply')) {
                    throw new \Exception(
                        esc_html__('Sorry, You do not have permission to approve this draft response', 'fluent-support')
                    );
                }
                $response->conversation_type = 'response';
            }

            $response->save();

            return [
                'message'  => __('Selected response has been updated', 'fluent-support'),
                'response' => $response
            ];
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function approveDraftResponse(TicketResponseRequest $request, $ticket_id, $response_id)
    {
        try {
            if (!PermissionManager::currentUserCan('fst_approve_draft_reply')) {
                throw new \Exception(
                    esc_html__('You do not have permission to approve draft responses.', 'fluent-support')
                );
            }

            $ticket = Ticket::findOrFail($ticket_id);
            $response = Conversation::findOrFail($response_id);
            $person = Helper::getAgentByUserId();

            $content = wp_unslash(wp_kses_post($request->getSafe('content', 'wp_kses_post')));
            $resetWaitingSince = apply_filters('fluent_support/reset_waiting_since', true, $content);

            $response->conversation_type = 'response';
            $response->created_at = current_time('mysql');
            $response->save();

            if ($person->person_type == 'agent' && $ticket->status == 'new') {
                $ticket->status = 'active';
                if ($ticket->created_at) {
                    $ticket->first_response_time = strtotime(current_time('mysql')) - strtotime($ticket->created_at);
                } else {
                    $ticket->first_response_time = 300;
                }
            }

            if ($resetWaitingSince) {
                $ticket->last_agent_response = current_time('mysql');
                $ticket->waiting_since = current_time('mysql');
            }

            $ticket->response_count += 1;
            $ticket->save();

            do_action('fluent_support/response_added_by_' . $person->person_type, $response, $ticket, $person);

            return [
                'message'  => __('Draft response has been successfully approved.', 'fluent-support'),
                'response' => $response,
            ];
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
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
        try {
            $ticket = Ticket::findOrFail($ticket_id);
            $ticket->applyTags($request->getSafe('tag_id', 'intval'));

            return [
                'message' => __('Tag has been added to this ticket', 'fluent-support'),
                'tags'    => $ticket->tags
            ];
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * detachTag method will remove all tags from tickets
     * @param $ticket_id
     * @param $tag_id
     * @return array
     */
    public function detachTag($ticket_id, $tag_id)
    {
        try {
            $ticket = Ticket::findOrFail($ticket_id);
            $ticket->detachTags($tag_id);

            return [
                'message' => __('Tag has been removed from this ticket', 'fluent-support'),
                'tags'    => $ticket->tags
            ];
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
        }
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
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
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
            'tags'       => $request->get('tags', null)
        ];

        // Sanitize tags array if it's an array
        if (is_array($data['tags'])) {
            $data['tags'] = array_map('intval', $data['tags']);
        }

        try {
            return $fluentCRMServices->syncCrmTags($data);
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
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
            'lists'      => $request->get('lists', null, [])
        ];

        // Sanitize lists array if it's an array
        if (is_array($data['lists'])) {
            $data['lists'] = array_map('intval', $data['lists']);
        }

        try {
            return $fluentCRMServices->syncCrmLists($data);
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
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
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
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

    public function fetchLabelSearch()
    {
        try {
            $agent_id = get_current_user_id();
            return TicketHelper::getLabelSearch($agent_id);
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function storeOrUpdateLabelSearch(Request $request)
    {
        try {
            $agent_id = get_current_user_id();
            $searchData = $request->get('query', null, []);
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
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function deleteLabelSearch(Request $request, $search_id)
    {
        try {
            $agent_id = get_current_user_id();
            return TicketHelper::deleteSavedSearch($search_id);
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
        }
    }
}

