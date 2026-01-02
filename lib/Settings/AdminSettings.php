<?php
declare(strict_types=1);
namespace OCA\ExternalShare\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
    private IConfig $config;

    public function __construct(IConfig $config) {
        $this->config = $config;
    }

    public function getForm(): TemplateResponse {
        $uploadUrl = $this->config->getAppValue('externalshare', 'upload_url', '');
        $authToken = $this->config->getAppValue('externalshare', 'auth_token', '');
        $customHeaders = $this->config->getAppValue('externalshare', 'custom_headers', '');
        $httpMethod = $this->config->getAppValue('externalshare', 'http_method', 'PUT');

        return new TemplateResponse('externalshare', 'admin-settings', [
            'upload_url' => $uploadUrl,
            'auth_token' => $authToken,
            'custom_headers' => $customHeaders,
            'http_method' => $httpMethod,
        ], '');
    }

    public function getSection(): string {
        return 'sharing';
    }

    public function getPriority(): int {
        return 50;
    }
}
