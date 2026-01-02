<?php
declare(strict_types=1);
namespace OCA\ExternalShare\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use Psr\Log\LoggerInterface;

class ShareController extends Controller {
    private IRootFolder $rootFolder;
    private IUserSession $userSession;
    private IConfig $config;
    private LoggerInterface $logger;
    private IMailer $mailer;
    private IL10N $l;

    /** Maximum file size for upload (100MB) */
    private const MAX_FILE_SIZE = 104857600;

    /** Maximum length for email addresses */
    private const MAX_EMAIL_LENGTH = 254;

    public function __construct(
        string $appName,
        IRequest $request,
        IRootFolder $rootFolder,
        IUserSession $userSession,
        IConfig $config,
        LoggerInterface $logger,
        IMailer $mailer,
        IL10N $l
    ) {
        parent::__construct($appName, $request);
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
        $this->config = $config;
        $this->logger = $logger;
        $this->mailer = $mailer;
        $this->l = $l;
    }

    /**
     * Upload a file to an external service
     *
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function upload(): DataResponse {
        $filePath = $this->request->getParam('filePath');
        $uploadUrl = $this->config->getAppValue('externalshare', 'upload_url', '');

        // Validate upload URL is configured
        if (empty($uploadUrl)) {
            return new DataResponse([
                'success' => false,
                'message' => 'Upload service not configured. Please contact your administrator.',
                'error_code' => 'CONFIG_MISSING'
            ], Http::STATUS_BAD_REQUEST);
        }

        // Validate file path is provided
        if (empty($filePath)) {
            return new DataResponse([
                'success' => false,
                'message' => 'No file specified. Please select a file to upload.',
                'error_code' => 'FILE_NOT_SPECIFIED'
            ], Http::STATUS_BAD_REQUEST);
        }

        // Validate and sanitize file path
        $sanitizedPath = $this->validateAndSanitizePath($filePath);
        if ($sanitizedPath === null) {
            return new DataResponse([
                'success' => false,
                'message' => 'Invalid file path. Path contains invalid characters or references.',
                'error_code' => 'INVALID_PATH'
            ], Http::STATUS_BAD_REQUEST);
        }

        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new DataResponse([
                    'success' => false,
                    'message' => 'User not authenticated. Please log in and try again.',
                    'error_code' => 'NOT_AUTHENTICATED'
                ], Http::STATUS_UNAUTHORIZED);
            }

            $userFolder = $this->rootFolder->getUserFolder($user->getUID());

            // Get file with proper exception handling
            try {
                $file = $userFolder->get($sanitizedPath);
            } catch (NotFoundException $e) {
                return new DataResponse([
                    'success' => false,
                    'message' => 'File not found. It may have been moved or deleted.',
                    'error_code' => 'FILE_NOT_FOUND'
                ], Http::STATUS_NOT_FOUND);
            }

            // Verify it's a file, not a directory
            if ($file->getType() !== \OCP\Files\FileInfo::TYPE_FILE) {
                return new DataResponse([
                    'success' => false,
                    'message' => 'Cannot upload directories. Please select a file.',
                    'error_code' => 'NOT_A_FILE'
                ], Http::STATUS_BAD_REQUEST);
            }

            // Check file is readable
            if (!$file->isReadable()) {
                return new DataResponse([
                    'success' => false,
                    'message' => 'File cannot be read. Check file permissions.',
                    'error_code' => 'FILE_NOT_READABLE'
                ], Http::STATUS_FORBIDDEN);
            }

            // Check file size
            $fileSize = $file->getSize();
            if ($fileSize > self::MAX_FILE_SIZE) {
                return new DataResponse([
                    'success' => false,
                    'message' => sprintf('File too large. Maximum size is %dMB.', self::MAX_FILE_SIZE / 1048576),
                    'error_code' => 'FILE_TOO_LARGE'
                ], Http::STATUS_BAD_REQUEST);
            }

            if ($fileSize === 0) {
                return new DataResponse([
                    'success' => false,
                    'message' => 'Cannot upload empty file.',
                    'error_code' => 'FILE_EMPTY'
                ], Http::STATUS_BAD_REQUEST);
            }

            // Execute upload with proper cleanup
            $result = $this->executeHttpUpload($file, $uploadUrl);

            if ($result['success']) {
                return new DataResponse([
                    'success' => true,
                    'message' => 'File uploaded successfully.',
                    'shareLink' => $result['shareLink']
                ], Http::STATUS_OK);
            } else {
                return new DataResponse([
                    'success' => false,
                    'message' => $result['message'],
                    'error_code' => $result['error_code'] ?? 'UPLOAD_FAILED'
                ], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

        } catch (NotPermittedException $e) {
            $this->logger->warning('Permission denied accessing file', [
                'app' => 'externalshare',
                'exception' => $e
            ]);
            return new DataResponse([
                'success' => false,
                'message' => 'Access denied. You do not have permission to access this file.',
                'error_code' => 'PERMISSION_DENIED'
            ], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error during file upload', [
                'app' => 'externalshare',
                'exception' => $e
            ]);
            return new DataResponse([
                'success' => false,
                'message' => 'An unexpected error occurred. Please try again.',
                'error_code' => 'INTERNAL_ERROR'
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Send a share link via email
     *
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function sendMail(): DataResponse {
        $recipientEmail = $this->request->getParam('email');
        $shareLink = $this->request->getParam('shareLink');
        $fileName = $this->request->getParam('fileName');
        $customMessage = $this->request->getParam('message', '');

        // Validate required parameters
        if (empty($recipientEmail)) {
            return new DataResponse([
                'success' => false,
                'message' => 'Email address is required.',
                'error_code' => 'EMAIL_REQUIRED'
            ], Http::STATUS_BAD_REQUEST);
        }

        if (empty($shareLink)) {
            return new DataResponse([
                'success' => false,
                'message' => 'Share link is required.',
                'error_code' => 'LINK_REQUIRED'
            ], Http::STATUS_BAD_REQUEST);
        }

        // Validate email format and length
        $recipientEmail = trim($recipientEmail);
        if (strlen($recipientEmail) > self::MAX_EMAIL_LENGTH) {
            return new DataResponse([
                'success' => false,
                'message' => 'Email address is too long.',
                'error_code' => 'EMAIL_TOO_LONG'
            ], Http::STATUS_BAD_REQUEST);
        }

        if (!$this->mailer->validateMailAddress($recipientEmail)) {
            return new DataResponse([
                'success' => false,
                'message' => 'Invalid email address format.',
                'error_code' => 'INVALID_EMAIL'
            ], Http::STATUS_BAD_REQUEST);
        }

        // Validate share link is a valid URL
        if (!filter_var($shareLink, FILTER_VALIDATE_URL)) {
            return new DataResponse([
                'success' => false,
                'message' => 'Invalid share link format.',
                'error_code' => 'INVALID_LINK'
            ], Http::STATUS_BAD_REQUEST);
        }

        // Get current user
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse([
                'success' => false,
                'message' => 'User not authenticated.',
                'error_code' => 'NOT_AUTHENTICATED'
            ], Http::STATUS_UNAUTHORIZED);
        }

        $senderName = $user->getDisplayName();
        $senderEmail = $user->getEMailAddress();

        // Sanitize file name for display
        $fileName = htmlspecialchars($fileName ?? 'file', ENT_QUOTES, 'UTF-8');
        $customMessage = htmlspecialchars(trim($customMessage), ENT_QUOTES, 'UTF-8');

        try {
            $message = $this->mailer->createMessage();

            // Set subject
            $subject = $this->l->t('%s shared a file with you', [$senderName]);
            $message->setSubject($subject);

            // Set recipient
            $message->setTo([$recipientEmail]);

            // Set sender (use system default if user has no email)
            if (!empty($senderEmail)) {
                $message->setReplyTo([$senderEmail => $senderName]);
            }

            // Build email body
            $bodyText = $this->buildEmailBody($senderName, $fileName, $shareLink, $customMessage, false);
            $bodyHtml = $this->buildEmailBody($senderName, $fileName, $shareLink, $customMessage, true);

            $message->setPlainBody($bodyText);
            $message->setHtmlBody($bodyHtml);

            // Send the email
            $failedRecipients = $this->mailer->send($message);

            if (!empty($failedRecipients)) {
                $this->logger->warning('Failed to send email to some recipients', [
                    'app' => 'externalshare',
                    'failed_recipients' => $failedRecipients
                ]);
                return new DataResponse([
                    'success' => false,
                    'message' => 'Failed to send email. Please check the email address.',
                    'error_code' => 'SEND_FAILED'
                ], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            $this->logger->info('Share link sent via email', [
                'app' => 'externalshare',
                'recipient' => $recipientEmail,
                'sender' => $senderEmail
            ]);

            return new DataResponse([
                'success' => true,
                'message' => $this->l->t('Email sent successfully to %s', [$recipientEmail])
            ], Http::STATUS_OK);

        } catch (\Exception $e) {
            $this->logger->error('Failed to send share link email', [
                'app' => 'externalshare',
                'exception' => $e
            ]);
            return new DataResponse([
                'success' => false,
                'message' => 'Failed to send email. Please try again later.',
                'error_code' => 'MAIL_ERROR'
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Build email body for share link notification
     *
     * @param string $senderName Name of the sender
     * @param string $fileName Name of the shared file
     * @param string $shareLink The share link URL
     * @param string $customMessage Optional custom message from sender
     * @param bool $html Whether to generate HTML or plain text
     * @return string Email body
     */
    private function buildEmailBody(string $senderName, string $fileName, string $shareLink, string $customMessage, bool $html): string {
        if ($html) {
            $body = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">';
            $body .= '<h2 style="color: #0082c9;">' . htmlspecialchars($this->l->t('File Shared With You')) . '</h2>';
            $body .= '<p>' . htmlspecialchars($this->l->t('%s has shared a file with you:', [$senderName])) . '</p>';
            $body .= '<p style="background: #f5f5f5; padding: 15px; border-radius: 5px;">';
            $body .= '<strong>' . htmlspecialchars($fileName) . '</strong></p>';

            if (!empty($customMessage)) {
                $body .= '<p style="border-left: 3px solid #0082c9; padding-left: 15px; margin: 20px 0;">';
                $body .= '<em>' . nl2br(htmlspecialchars($customMessage)) . '</em></p>';
            }

            $body .= '<p><a href="' . htmlspecialchars($shareLink) . '" ';
            $body .= 'style="display: inline-block; background: #0082c9; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 10px 0;">';
            $body .= htmlspecialchars($this->l->t('Download File')) . '</a></p>';
            $body .= '<p style="color: #666; font-size: 12px;">' . htmlspecialchars($this->l->t('Or copy this link:')) . '<br>';
            $body .= '<a href="' . htmlspecialchars($shareLink) . '">' . htmlspecialchars($shareLink) . '</a></p>';
            $body .= '</div>';
        } else {
            $body = $this->l->t('%s has shared a file with you:', [$senderName]) . "\n\n";
            $body .= $fileName . "\n\n";

            if (!empty($customMessage)) {
                $body .= $this->l->t('Message:') . "\n";
                $body .= $customMessage . "\n\n";
            }

            $body .= $this->l->t('Download link:') . "\n";
            $body .= $shareLink . "\n";
        }

        return $body;
    }

    /**
     * Validate and sanitize file path to prevent path traversal attacks
     *
     * @param string $path User-provided file path
     * @return string|null Sanitized path or null if invalid
     */
    private function validateAndSanitizePath(string $path): ?string {
        // Remove null bytes
        if (strpos($path, "\0") !== false) {
            return null;
        }

        // Normalize path separators
        $path = str_replace('\\', '/', $path);

        // Remove multiple consecutive slashes
        $path = preg_replace('#/+#', '/', $path);

        // Check for path traversal attempts
        if (strpos($path, '../') !== false || strpos($path, '/..') !== false) {
            return null;
        }

        // Don't allow absolute paths (should be relative to user folder)
        if (str_starts_with($path, '/')) {
            $path = substr($path, 1);
        }

        // Trim whitespace
        $path = trim($path);

        // Ensure path is not empty after sanitization
        if (empty($path)) {
            return null;
        }

        return $path;
    }

    /**
     * Execute HTTP upload using PHP cURL extension (secure alternative to exec)
     *
     * @param \OCP\Files\File $file File to upload
     * @param string $uploadUrl Base upload URL
     * @return array{success: bool, message: string, shareLink?: string, error_code?: string}
     */
    private function executeHttpUpload($file, string $uploadUrl): array {
        $authToken = $this->config->getAppValue('externalshare', 'auth_token', '');
        $customHeaders = $this->config->getAppValue('externalshare', 'custom_headers', '');
        $httpMethod = $this->config->getAppValue('externalshare', 'http_method', 'PUT');

        $fileName = $file->getName();

        // Debug: log the URL being used
        $this->logger->info('Starting upload', [
            'app' => 'externalshare',
            'upload_url' => $uploadUrl,
            'http_method' => $httpMethod,
            'file_name' => $fileName
        ]);

        // Create temporary file with proper cleanup handling
        $tempFile = null;
        $tempFilePath = null;

        try {
            $tempFile = tmpfile();
            if ($tempFile === false) {
                return [
                    'success' => false,
                    'message' => 'Failed to create temporary file for upload.',
                    'error_code' => 'TEMP_FILE_FAILED'
                ];
            }

            $tempFilePath = stream_get_meta_data($tempFile)['uri'];

            // Write file content to temp file
            $content = $file->getContent();
            if (fwrite($tempFile, $content) === false) {
                return [
                    'success' => false,
                    'message' => 'Failed to write file content.',
                    'error_code' => 'WRITE_FAILED'
                ];
            }

            // Rewind for reading
            rewind($tempFile);

            // Determine final URL based on method
            if ($httpMethod === 'PUT') {
                $finalUrl = rtrim($uploadUrl, '/') . '/' . rawurlencode($fileName);
            } else {
                $finalUrl = $uploadUrl;
            }

            // Debug: log the final URL
            $this->logger->info('Calling external service', [
                'app' => 'externalshare',
                'final_url' => $finalUrl,
                'method' => $httpMethod
            ]);

            // Initialize cURL
            $ch = curl_init($finalUrl);
            if ($ch === false) {
                return [
                    'success' => false,
                    'message' => 'Failed to initialize HTTP client.',
                    'error_code' => 'CURL_INIT_FAILED'
                ];
            }

            // Build headers array
            $headers = [];

            // Set cURL options based on HTTP method
            if ($httpMethod === 'PUT') {
                // PUT method with raw file upload
                $fileContent = file_get_contents($tempFilePath);
                curl_setopt_array($ch, [
                    CURLOPT_CUSTOMREQUEST => 'PUT',
                    CURLOPT_POSTFIELDS => $fileContent,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_TIMEOUT => 300, // 5 minutes
                    CURLOPT_CONNECTTIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                ]);
                // Set content length header explicitly
                $headers[] = 'Content-Length: ' . strlen($fileContent);
                $headers[] = 'Content-Type: application/octet-stream';
                // Disable Expect: 100-continue which can cause issues
                $headers[] = 'Expect:';
            } else {
                // POST method with multipart/form-data (more widely supported)
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => [
                        'file' => new \CURLFile($tempFilePath, $file->getMimeType(), $fileName)
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_TIMEOUT => 300, // 5 minutes
                    CURLOPT_CONNECTTIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
            }

            // Add authentication header if configured
            if (!empty($authToken)) {
                $headers[] = 'Authorization: Bearer ' . $authToken;
            }

            // Add custom headers if configured
            if (!empty($customHeaders)) {
                $headerLines = explode("\n", $customHeaders);
                foreach ($headerLines as $header) {
                    $header = trim($header);
                    // Validate header format (must contain colon)
                    if (!empty($header) && strpos($header, ':') !== false) {
                        // Sanitize header to prevent header injection
                        $header = preg_replace('/[\r\n]/', '', $header);
                        $headers[] = $header;
                    }
                }
            }

            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }

            // Execute request
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);

            curl_close($ch);

            // Check for cURL errors
            if ($curlErrno !== 0) {
                $this->logger->error('cURL error during upload', [
                    'app' => 'externalshare',
                    'error' => $curlError,
                    'errno' => $curlErrno
                ]);
                return [
                    'success' => false,
                    'message' => 'Upload failed due to network error. Please try again.',
                    'error_code' => 'NETWORK_ERROR'
                ];
            }

            // Check HTTP response code
            if ($httpCode < 200 || $httpCode >= 300) {
                $this->logger->warning('Upload service returned error', [
                    'app' => 'externalshare',
                    'http_code' => (string)$httpCode,
                    'url' => $finalUrl,
                    'method' => $httpMethod,
                    'response' => substr($response, 0, 500)
                ]);
                return [
                    'success' => false,
                    'message' => sprintf('Upload service returned error (HTTP %d). Please check service configuration.', $httpCode),
                    'error_code' => 'SERVICE_ERROR'
                ];
            }

            // Extract share link from response
            $shareLink = $this->extractShareLink($response !== false ? $response : '', $finalUrl, $uploadUrl);

            return [
                'success' => true,
                'message' => 'File uploaded successfully.',
                'shareLink' => $shareLink
            ];

        } finally {
            // Ensure temp file is always closed
            if ($tempFile !== null && is_resource($tempFile)) {
                fclose($tempFile);
            }
        }
    }

    /**
     * Extract share link from upload service response
     *
     * @param string $response Response body from upload service
     * @param string $defaultUrl Fallback URL if extraction fails
     * @param string $configuredUrl The admin-configured upload URL (used to determine protocol)
     * @return string Share link URL
     */
    private function extractShareLink(string $response, string $defaultUrl, string $configuredUrl): string {
        // Clean up response
        $response = trim($response);

        // Determine if we should enforce HTTPS based on configured URL
        $configuredScheme = parse_url($configuredUrl, PHP_URL_SCHEME);
        $enforceHttps = ($configuredScheme === 'https');

        // If response is a valid URL, use it
        if (filter_var($response, FILTER_VALIDATE_URL)) {
            return $this->ensureCorrectProtocol($response, $enforceHttps);
        }

        // Try to extract URL from response using regex
        if (preg_match('/https?:\/\/[^\s\n\r<>"\']+/', $response, $matches)) {
            $extractedUrl = trim($matches[0]);
            if (filter_var($extractedUrl, FILTER_VALIDATE_URL)) {
                return $this->ensureCorrectProtocol($extractedUrl, $enforceHttps);
            }
        }

        // Fallback to the upload URL
        return $defaultUrl;
    }

    /**
     * Ensure URL uses HTTPS if configured to do so
     *
     * @param string $url The URL to check
     * @param bool $enforceHttps Whether to upgrade HTTP to HTTPS
     * @return string URL with correct protocol
     */
    private function ensureCorrectProtocol(string $url, bool $enforceHttps): string {
        if (!$enforceHttps) {
            return $url;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme === 'http') {
            // Upgrade HTTP to HTTPS
            return preg_replace('/^http:/', 'https:', $url, 1);
        }

        return $url;
    }
}
