<?php
declare(strict_types=1);
namespace OCA\ExternalShare\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IConfig;
use OCP\IRequest;
use OCA\ExternalShare\Settings\AdminSettings;

class AdminController extends Controller {
    private IConfig $config;

    /** Maximum length for upload URL */
    private const MAX_URL_LENGTH = 2048;

    /** Maximum length for custom headers */
    private const MAX_HEADERS_LENGTH = 4096;

    /** Maximum length for auth token */
    private const MAX_TOKEN_LENGTH = 512;

    public function __construct(string $appName, IRequest $request, IConfig $config) {
        parent::__construct($appName, $request);
        $this->config = $config;
    }

    /**
     * Save admin settings for external share service
     *
     * @return DataResponse
     */
    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function saveSettings(): DataResponse {
        $uploadUrl = $this->request->getParam('upload_url', '');
        $authToken = $this->request->getParam('auth_token', '');
        $customHeaders = $this->request->getParam('custom_headers', '');
        $httpMethod = $this->request->getParam('http_method', 'PUT');

        // Validate and sanitize upload URL
        $uploadUrl = trim($uploadUrl);
        if (empty($uploadUrl)) {
            return new DataResponse([
                'status' => 'error',
                'message' => 'Upload URL is required.'
            ], Http::STATUS_BAD_REQUEST);
        }

        if (strlen($uploadUrl) > self::MAX_URL_LENGTH) {
            return new DataResponse([
                'status' => 'error',
                'message' => sprintf('Upload URL too long. Maximum %d characters.', self::MAX_URL_LENGTH)
            ], Http::STATUS_BAD_REQUEST);
        }

        // Validate URL format
        if (!filter_var($uploadUrl, FILTER_VALIDATE_URL)) {
            return new DataResponse([
                'status' => 'error',
                'message' => 'Invalid URL format. Please enter a valid HTTP/HTTPS URL.'
            ], Http::STATUS_BAD_REQUEST);
        }

        // Ensure URL uses HTTPS or HTTP
        $scheme = parse_url($uploadUrl, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return new DataResponse([
                'status' => 'error',
                'message' => 'URL must use HTTP or HTTPS protocol.'
            ], Http::STATUS_BAD_REQUEST);
        }

        // Validate auth token
        $authToken = trim($authToken);
        if (strlen($authToken) > self::MAX_TOKEN_LENGTH) {
            return new DataResponse([
                'status' => 'error',
                'message' => sprintf('Auth token too long. Maximum %d characters.', self::MAX_TOKEN_LENGTH)
            ], Http::STATUS_BAD_REQUEST);
        }

        // Sanitize and validate custom headers
        $customHeaders = trim($customHeaders);
        if (strlen($customHeaders) > self::MAX_HEADERS_LENGTH) {
            return new DataResponse([
                'status' => 'error',
                'message' => sprintf('Custom headers too long. Maximum %d characters.', self::MAX_HEADERS_LENGTH)
            ], Http::STATUS_BAD_REQUEST);
        }

        // Validate header format if provided
        if (!empty($customHeaders)) {
            $headerLines = explode("\n", $customHeaders);
            $validatedHeaders = [];

            foreach ($headerLines as $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                // Each header must contain a colon
                if (strpos($line, ':') === false) {
                    return new DataResponse([
                        'status' => 'error',
                        'message' => 'Invalid header format. Each header must be in format "Header-Name: value".'
                    ], Http::STATUS_BAD_REQUEST);
                }

                // Remove any control characters that could cause header injection
                $line = preg_replace('/[\r\n\0]/', '', $line);

                // Validate header name (before colon)
                [$headerName] = explode(':', $line, 2);
                $headerName = trim($headerName);

                if (!preg_match('/^[a-zA-Z0-9-]+$/', $headerName)) {
                    return new DataResponse([
                        'status' => 'error',
                        'message' => sprintf('Invalid header name: "%s". Only letters, numbers and hyphens allowed.', htmlspecialchars($headerName))
                    ], Http::STATUS_BAD_REQUEST);
                }

                $validatedHeaders[] = $line;
            }

            $customHeaders = implode("\n", $validatedHeaders);
        }

        // Validate HTTP method
        if (!in_array($httpMethod, ['POST', 'PUT'], true)) {
            return new DataResponse([
                'status' => 'error',
                'message' => 'Invalid HTTP method. Must be POST or PUT.'
            ], Http::STATUS_BAD_REQUEST);
        }

        // Save settings
        $this->config->setAppValue('externalshare', 'upload_url', $uploadUrl);
        $this->config->setAppValue('externalshare', 'auth_token', $authToken);
        $this->config->setAppValue('externalshare', 'custom_headers', $customHeaders);
        $this->config->setAppValue('externalshare', 'http_method', $httpMethod);

        return new DataResponse([
            'status' => 'success',
            'message' => 'Settings saved successfully.'
        ], Http::STATUS_OK);
    }
}
