<?php

namespace FluentSupport\App\Http\Controllers;

use FluentSupport\App\Models\Meta;
use FluentSupport\Framework\Request\Request;
use WP_Application_Passwords;
use WP_Error;

class ConnectMobileAppController extends Controller
{
    private const ENCRYPTION_KEY = '32b1f43e89c0a2d4';
    private const META_KEY = 'mobile_app_encryption_data';

    public function connectMobileApp(Request $request)
    {
        $currentUser = wp_get_current_user();
        $appPasswords = WP_Application_Passwords::get_user_application_passwords($currentUser->ID);

        $storedMeta = Meta::where('key', self::META_KEY)->first();
        $storedUuid = $storedMeta ? maybe_unserialize($storedMeta->value)['uuid'] ?? null : null;

        $matchingPassword = $this->findMatchingPassword($appPasswords, $storedUuid);

        if ($matchingPassword) {
            $encryptedPassword = maybe_unserialize($storedMeta->value)['applicationPassword'];
            $password = $encryptedPassword;
        } else {
            $newAppPassword = $this->createNewAppPassword($currentUser->ID);

            if (is_wp_error($newAppPassword)) {
                return new WP_Error('appPasswordCreationFailed', 'Failed to create application password', ['status' => 500]);
            }

            $password = $newAppPassword[0];
            $uuid = $newAppPassword[1]['uuid'];
            $password = $this->encryptPassword($password);

            $this->storePasswordData($storedMeta, $password, $uuid);
        }

        return $this->buildResponse($currentUser->user_login, $password);
    }

    private function findMatchingPassword(array $appPasswords, ?string $storedUuid): ?array
    {
        foreach ($appPasswords as $appPassword) {
            if ($appPassword['uuid'] === $storedUuid) {
                return $appPassword;
            }
        }
        return null;
    }

    private function createNewAppPassword(int $userId)
    {
        return WP_Application_Passwords::create_new_application_password($userId, [
            'name' => 'fluent_support_mobile_app',
            'app_id' => '',
            'rest_api_only' => true,
        ]);
    }

    private function storePasswordData($storedMeta, string $password, string $uuid): void
    {
        $data = [
            'applicationPassword' => $password,
            'uuid' => $uuid,
        ];

        if ($storedMeta) {
            $storedMeta->update(['value' => maybe_serialize($data)]);
        } else {
            Meta::create([
                'object_type' => self::META_KEY,
                'key' => self::META_KEY,
                'value' => maybe_serialize($data),
            ]);
        }
    }

    private function buildResponse(string $userName, string $password): array
    {
        return [
            'user_name' => $userName,
            'password' => $password,
            'rest_url' => get_rest_url(),
        ];
    }

    private function encryptPassword(string $password): string
    {
        $key = substr(self::ENCRYPTION_KEY, 0, 16);
        $iv = openssl_random_pseudo_bytes(16);

        $encrypted = openssl_encrypt($password, 'AES-128-CBC', $key, 0, $iv);
        $encryptedData = $encrypted . '::' . base64_encode($iv);

        return base64_encode($encryptedData);
    }
}
