<?php
declare(strict_types=1);
namespace OCA\ExternalShare\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Util;

class Application extends App implements IBootstrap {
    public const APP_ID = 'externalshare';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // No registration needed for sharing integration
    }

    public function boot(IBootContext $context): void {
        // Only load on files app pages
        $request = $context->getServerContainer()->get(\OCP\IRequest::class);
        $pathInfo = $request->getPathInfo() ?? '';
        // Match /apps/files exactly or /apps/files/ prefix, but not /apps/files_external
        if ($pathInfo === '/apps/files' || str_starts_with($pathInfo, '/apps/files/')) {
            Util::addScript(self::APP_ID, 'externalshare');
            Util::addStyle(self::APP_ID, 'externalshare');
        }
    }
}
