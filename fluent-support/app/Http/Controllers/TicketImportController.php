<?php
namespace FluentSupport\App\Http\Controllers;

use FluentSupport\App\Services\Tickets\Importer\MigratorService;
use FluentSupport\Framework\Http\Request\Request;
use FluentSupport\App\Services\Tickets\Importer\BaseImporter;


class TicketImportController extends Controller
{
    public function getStats ( MigratorService $importService )
    {
        $stats = $importService->getStats();
        if(!$stats) {
            return [];
        }
        return $stats;
    }

    public function importTickets(MigratorService $importService, Request $request)
    {
        try {
            $handler = $request->getSafe('handler', 'sanitize_key');
            $query = $request->get('query', null);
            $query = is_array($query) ? [
                'access_token' => isset($query['access_token']) ? sanitize_text_field($query['access_token']) : '',
                'mailbox'      => isset($query['mailbox']) ? intval($query['mailbox']) : 0,
                'domain'       => isset($query['domain']) ? sanitize_text_field($query['domain']) : '',
                'email'        => isset($query['email']) ? sanitize_email($query['email']) : '',
            ] : [];

            return $importService->handleImport( $request->getSafe('page', 'intval'), $handler, $query );
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    public function deleteTickets (MigratorService $importService, Request $request)
    {
        return $importService->deleteTickets($request->getSafe('page', 'intval'), $request->getSafe('handler', 'sanitize_key'));
    }
}
