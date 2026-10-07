<?php

namespace OCA\UserBackendSqlRaw\Tests\Integration;

use OCA\UserBackendSqlRaw\AppInfo\Application;
use OCA\UserBackendSqlRaw\GroupBackend;
use OCA\UserBackendSqlRaw\UserBackend;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use Test\TestCase;

/**
 * @group DB
 */
class ApplicationTest extends TestCase
{
    public function testBootstrapRegistersBothSqlBackends(): void
    {
        $config = Server::get(IConfig::class);
        $previousConfig = $config->getSystemValue(Application::APP_ID, null);
        $userManager = Server::get(IUserManager::class);
        $groupManager = Server::get(IGroupManager::class);
        $previousUserBackends = $userManager->getBackends();
        $previousGroupBackends = $groupManager->getBackends();

        try {
            $config->setSystemValue(Application::APP_ID, ['dsn' => 'sqlite::memory:']);
            $userManager->clearBackends();
            $groupManager->clearBackends();

            $app = new Application();
            $context = $this->createMock(IBootContext::class);
            $context->method('getAppContainer')->willReturn($app->getContainer());
            $app->boot($context);

            $userBackends = array_values($userManager->getBackends());
            $groupBackends = array_values($groupManager->getBackends());
            self::assertCount(1, $userBackends);
            self::assertInstanceOf(UserBackend::class, $userBackends[0]);
            self::assertCount(1, $groupBackends);
            self::assertInstanceOf(GroupBackend::class, $groupBackends[0]);
        } finally {
            $userManager->clearBackends();
            foreach ($previousUserBackends as $backend) {
                $userManager->registerBackend($backend);
            }
            $groupManager->clearBackends();
            foreach ($previousGroupBackends as $backend) {
                $groupManager->addBackend($backend);
            }
            if ($previousConfig === null) {
                $config->deleteSystemValue(Application::APP_ID);
            } else {
                $config->setSystemValue(Application::APP_ID, $previousConfig);
            }
        }
    }
}
