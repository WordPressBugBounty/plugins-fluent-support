<?php

namespace FluentSupport\App\Hooks\Handlers;

use FluentSupport\App\App;
use FluentSupport\App\Models\Customer;
use FluentSupport\App\Models\Product;
use FluentSupport\App\Modules\PermissionManager;
use FluentSupport\App\Services\Blocks\BlockHelper;
use FluentSupport\App\Services\Helper;
use FluentSupport\App\Services\TranslationStrings;
use FluentSupport\Framework\Support\Arr;
use FluentSupportPro\App\Services\ProHelper;

class CustomerPortalHandler
{
    public function renderPortal($args = [])
    {
        /**
         * This hook filter customer portal access permission error message.
         * If a customer has no access to the portal, then the message will be displayed.
         * @param string $invalidPermissionMessage
         * @return string
         * @since 1.6.0
         */
        $invalidPermissionMessage = apply_filters(
            'fluent_support/customer_portal_invalid_permission_message',
            esc_html__('You don\'t have permission to access customer support portal', 'fluent-support')
        );

        $person = Helper::getCurrentCustomer();

        if (!$person && PermissionManager::currentUserPermissions()) {
            $adminPortalUrl = Helper::getPortalAdminBaseUrl();

            /**
             * This hook filter is responsible for generating error message
             * when a support staff try to access customer portal
             * @param string $agentPermissionErrMessage
             * @return string
             * @since 1.6.0
             */
            $msg = __('Customer Portal is only accessible by Customers. Looks like you are a support staff', 'fluent-support');
            $agentPermissionErrMessage = apply_filters(
                'fluent_support/customer_portal_agent_permission_error_message',
                $msg
            );
            return '<div style="text-align: center;"><h3>' . $agentPermissionErrMessage . '</h3><a href="' . $adminPortalUrl . '">' . esc_html__('Go to Support Admin Page', 'fluent-support') . '</a></div>';
        } else if ($this->hasCustomerPortalAccess()) {

            /*
            * Filter customer portal access settings
            *
            * @since v1.0.0
            *
            * @param array $canAccess
            */
            $canAccess = apply_filters('fluent_support/user_portal_access_config', [
                'status' => true,
                'message' => $invalidPermissionMessage
            ]);

            if (empty($canAccess['status'])) {
                $invalidPermissionMessage = Arr::get($canAccess, 'message', $invalidPermissionMessage);
                return '<div id="fluent_support_client_app" style="text-align: center;"><h3 class="fs_customer_restriction">' . $invalidPermissionMessage . '</h3></div>';
            }

            if (!$person) {
                $this->maybeCreateCustomer();
            }

            if (isset($args['attributes']) && !empty($args['attributes'])) {
                BlockHelper::processAttributesAndPrepareStyle($args['attributes']);
            }

            $this->enqueueScripts();
            return '<div id="fluent_support_client_app"><h3 class="fs_loading_text">' . __('Loading Customer Portal. Please wait...', 'fluent-support') . '</h3></div>';
        } else {

            $businessSettings = Helper::getBusinessSettings();
            $loggedInMessage = Arr::get($businessSettings, 'login_message', '');

            $loggedInMessage = str_replace('[fluent_support_portal]', '', $loggedInMessage);

            return do_shortcode($loggedInMessage);
        }
    }

    public function hasCustomerPortalAccess()
    {
        $userId = get_current_user_id();

        if ($userId) {
            return true;
        }

        return $this->isSignedTicketView();
    }

    protected function isSignedTicketView()
    {
        if (!Helper::isPublicSignedTicketEnabled()) {
            return false;
        }

        return isset($_REQUEST['fs_view']) && $_REQUEST['fs_view'] == 'ticket' && isset($_REQUEST['support_hash']) && isset($_REQUEST['ticket_id']);
    }

    private function maybeCreateCustomer()
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return false;
        }

        $person = Helper::getCurrentPerson();
        if ($person) {
            return $person;
        }

        $user = get_user_by('ID', $userId);

        $request = App::request();

        $onBehalf = [
            'user_id' => $user->ID,
            'email' => $user->user_email,
            'last_ip_address' => $request->getIp()
        ];

        $customFields = Helper::getBusinessSettings('custom_registration_form_field');

        if (!empty($customFields)) {
            $onBehalf = $this->processCustomFields($customFields, $onBehalf);
        }

        return Customer::maybeCreateCustomer($onBehalf);
    }

    private function processCustomFields($customFields, $onBehalf)
    {
        $userMeta = get_user_meta(get_current_user_id());
        $customData = [];

        foreach ($customFields as $field) {
            if (isset($userMeta[$field])) {
                $customData[$field] = is_array($userMeta[$field]) ? $userMeta[$field][0] : $userMeta[$field];
            }
        }

        if ($customData) {
            $onBehalf = array_merge($onBehalf, $customData);
        }

        return $onBehalf;
    }

    public function enqueueScripts()
    {
        $app = App::getInstance();

        $ns = $app->config->get('app.rest_namespace');
        $v = $app->config->get('app.rest_version');
        $slug = $app->config->get('app.slug');

        $restInfo = [
            'base_url' => esc_url_raw(rest_url()),
            'url' => rest_url($ns . '/' . $v . '/customer-portal'),
            'nonce' => wp_create_nonce('wp_rest'),
            'namespace' => $ns,
            'version' => $v,
        ];

        $assets = $app['url.assets'];


        $i18ns = TranslationStrings::getPortalStrings();

        $i18ns['allowed_files_and_size'] = Helper::getFileUploadMessage();

        $data = [
            'rest' => $restInfo,
            'nonce' => wp_create_nonce($slug),
            'support_products' => Product::select(['id', 'title'])->get(),
            'product_field_required' => Helper::isProductRequired(),
            'customer_ticket_priorities' => Helper::customerTicketPriorities(),
            'view_tickets_url' => '#/',
            'i18n' => $i18ns,
            'fallback_image' => $assets . 'images/icons/file.svg',
            'has_file_upload' => !!Helper::ticketAcceptedFileMiles(),
            'has_rich_text_editor' => true,
            'customer_status' => static::customerStatus()->status ?? static::customerStatus(),
            'max_file_upload' => Helper::getBusinessSettings('max_file_upload', 3),
            'agent_feedback_rating' => Helper::getBusinessSettings('agent_feedback_rating', 'no'),
        ];

        if ($this->isSignedTicketView()) {
            $data['intended_ticket_hash'] = sanitize_text_field($_REQUEST['support_hash']);
            $data['view_tickets_url'] = Helper::getPortalBaseUrl() . '/#';
        } else {
            add_filter('user_can_richedit', '__return_true');
        }
        /*
         * Filter customer portal localize javascript data
         *
         *  @since v1.0.0
         *
         * @param array $data
         */
        $data = apply_filters('fluent_support/customer_portal_vars', $data);

        if (!empty($data['has_rich_text_editor'])) {
            wp_tinymce_inline_scripts();
            wp_enqueue_editor();
        }

        wp_enqueue_script('dompurify', $assets . 'libs/purify/purify.min.js', [], '2.4.3');
        wp_enqueue_script('fs_tk_customer_portal', $assets . 'portal/js/app.js', ['jquery'], FLUENT_SUPPORT_VERSION, true);

        $rtlSuffix = is_rtl() ? '-rtl' : '';
        $rtlSuffixHandler = $rtlSuffix ? '_rtl' : '';
        wp_enqueue_style('fs_tk_customer_portal' . $rtlSuffixHandler, $assets . 'portal/css/app' . $rtlSuffix . '.css', [], FLUENT_SUPPORT_VERSION);

        wp_localize_script('fs_tk_customer_portal', 'fs_customer_portal', $data);
    }

    protected static function customerStatus()
    {
        $user = get_current_user_id();

        if (!$user && isset($_REQUEST['support_hash']) && isset($_REQUEST['ticket_id']) && isset($_REQUEST['fs_view']) && $_REQUEST['fs_view'] == 'ticket') {
            return true;
        }

        return Customer::where('user_id', $user)->select(['status'])->first();
    }
}
