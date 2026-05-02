<?php
/**
 * @copyright Copyright (c) 2018 Alexey Abel <dev@abelonline.de>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\UserBackendSqlRaw\Tests\Integration;

use OCA\UserBackendSqlRaw\Config;
use OCA\UserBackendSqlRaw\GroupBackend;
use OCA\UserBackendSqlRaw\Tests\Dbs\SqliteMemoryTestDb;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\Group;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * @group DB
 */
#[Group('DB')]
class GroupBackendTest extends TestCase
{
    /** @var ContainerInterface */
    private $container;
    /** @var GroupBackend */
    private $groupBackend;
    /** @var \PDO */
    private $dbHandle;
    /** @var IConfig */
    private $nextcloudConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $app = new \OCA\UserBackendSqlRaw\AppInfo\Application();
        $this->container = $app->getContainer();

        $this->nextcloudConfig = $this->container->get('OCP\IConfig');
        $this->nextcloudConfig->setSystemValue('instanceid', 'abcdefghijkl');

        $this->container->get('OCP\IGroupManager')->clearBackends();
        $this->groupBackend = new GroupBackend($this->getLogStub(), $this->getMockAppConfig(), $this->getMockDb());
    }

    public function testGroupBackendCanBeRegistered(): void
    {
        $groupManager = $this->container->get('OCP\IGroupManager');
        $groupManager->addBackend($this->groupBackend);

        $foundBackend = false;
        foreach ($groupManager->getBackends() as $groupBackend) {
            if ($groupBackend instanceof GroupBackend) {
                $foundBackend = true;
                break;
            }
        }

        self::assertTrue($foundBackend);
    }

    public function testGroupBackendNameIsCorrect(): void
    {
        self::assertSame('SQL raw', $this->groupBackend->getBackendName());
    }

    public function testGroupExistsWorks(): void
    {
        self::assertTrue($this->groupBackend->groupExists('admins'));
        self::assertFalse($this->groupBackend->groupExists('no-such-group'));
    }

    public function testGetGroupsSearchAndPagingWorks(): void
    {
        self::assertSame(['admins', 'editors'], $this->groupBackend->getGroups('', 2, 0));
        self::assertSame(['guests'], $this->groupBackend->getGroups('', 1, 2));
        self::assertSame(['admins'], $this->groupBackend->getGroups('admin'));
    }

    public function testUserMembershipLookupWorks(): void
    {
        self::assertTrue($this->groupBackend->inGroup('alice', 'admins'));
        self::assertFalse($this->groupBackend->inGroup('alice', 'guests'));
        self::assertSame(['admins', 'editors'], $this->groupBackend->getUserGroups('alice'));
    }

    public function testUsersInGroupSearchAndPagingWorks(): void
    {
        self::assertSame(['alice', 'bob'], $this->groupBackend->usersInGroup('admins'));
        self::assertSame(['alice'], $this->groupBackend->usersInGroup('admins', 'ali'));
        self::assertSame(['bob'], $this->groupBackend->usersInGroup('admins', '', 1, 1));
    }

    public function testAddAndRemoveUserFromGroupWorks(): void
    {
        self::assertFalse($this->groupBackend->inGroup('chris', 'admins'));
        self::assertTrue($this->groupBackend->addToGroup('chris', 'admins'));
        self::assertTrue($this->groupBackend->inGroup('chris', 'admins'));
        self::assertTrue((bool)$this->groupBackend->removeFromGroup('chris', 'admins'));
        self::assertFalse($this->groupBackend->inGroup('chris', 'admins'));
    }

    public function testCreateAndDeleteGroupWorks(): void
    {
        self::assertFalse($this->groupBackend->groupExists('support'));
        self::assertTrue($this->groupBackend->createGroup('support'));
        self::assertTrue($this->groupBackend->groupExists('support'));
        self::assertTrue($this->groupBackend->deleteGroup('support'));
        self::assertFalse($this->groupBackend->groupExists('support'));
    }

    public function testCountUsersInGroupWorks(): void
    {
        self::assertSame(2, $this->groupBackend->countUsersInGroup('admins'));
        self::assertSame(1, $this->groupBackend->countUsersInGroup('admins', 'ali'));
    }

    public function testGroupDisplayNameReadAndWriteWorks(): void
    {
        self::assertSame('Administrators', $this->groupBackend->getDisplayName('admins'));
        self::assertTrue($this->groupBackend->setDisplayName('admins', 'Admin Team'));
        self::assertSame('Admin Team', $this->groupBackend->getDisplayName('admins'));
    }

    private function getLogStub()
    {
        return $this->getMockBuilder(LoggerInterface::class)->getMock();
    }

    private function getMockAppConfig(): Config
    {
        $nextcloudConfigStub = $this->getMockBuilder(IConfig::class)->getMock();
        $nextcloudConfigStub->method('getSystemValue')->willReturn($this->getMockAppConfigurationArray());
        return new Config($this->getLogStub(), $nextcloudConfigStub);
    }

    private function getMockAppConfigurationArray(): array
    {
        return [
            'db_user' => 'unused',
            'db_password' => 'unused',
            'queries' => [
                'group_exists' => 'SELECT EXISTS(SELECT 1 FROM groups WHERE gid = :group_id)',
                'get_groups' => 'SELECT gid FROM groups WHERE (gid LIKE :search COLLATE NOCASE) OR (display_name LIKE :search COLLATE NOCASE) ORDER BY gid',
                'get_user_groups' => 'SELECT group_id FROM group_members WHERE user_id = :user_id ORDER BY group_id',
                'get_group_users' => 'SELECT users.username FROM users INNER JOIN group_members ON group_members.user_id = users.username WHERE group_members.group_id = :group_id AND ((users.username LIKE :search COLLATE NOCASE) OR (users.display_name LIKE :search COLLATE NOCASE)) ORDER BY users.username',
                'add_user_to_group' => 'INSERT INTO group_members (group_id, user_id) VALUES (:group_id, :user_id)',
                'remove_user_from_group' => 'DELETE FROM group_members WHERE group_id = :group_id AND user_id = :user_id',
                'create_group' => 'INSERT INTO groups (gid, display_name) VALUES (:group_id, :display_name)',
                'delete_group' => 'DELETE FROM groups WHERE gid = :group_id',
                'count_group_users' => 'SELECT COUNT(*) FROM group_members INNER JOIN users ON group_members.user_id = users.username WHERE group_members.group_id = :group_id AND ((users.username LIKE :search COLLATE NOCASE) OR (users.display_name LIKE :search COLLATE NOCASE))',
                'get_group_display_name' => 'SELECT display_name FROM groups WHERE gid = :group_id',
                'set_group_display_name' => 'UPDATE groups SET display_name = :new_display_name WHERE gid = :group_id',
            ],
        ];
    }

    private function getMockDb(): SqliteMemoryTestDb
    {
        try {
            $mockDb = new SqliteMemoryTestDb($this->getMockAppConfig());
            $this->dbHandle = $mockDb->getDbHandle();
        } catch (\PDOException $e) {
            throw new \PDOException('Could not create sqlite db. You probably need to install the sqlite driver for php.');
        }

        $this->dbHandle->exec('CREATE TABLE users (
            username TEXT COLLATE nocase PRIMARY KEY,
            display_name TEXT COLLATE nocase
        )');

        $this->dbHandle->exec('CREATE TABLE groups (
            gid TEXT COLLATE nocase PRIMARY KEY,
            display_name TEXT COLLATE nocase
        )');

        $this->dbHandle->exec('CREATE TABLE group_members (
            group_id TEXT COLLATE nocase,
            user_id TEXT COLLATE nocase,
            PRIMARY KEY(group_id, user_id)
        )');

        $this->dbHandle->exec("INSERT INTO users (username, display_name) VALUES
            ('alice', 'Alice'),
            ('bob', 'Bob'),
            ('chris', 'Chris')");

        $this->dbHandle->exec("INSERT INTO groups (gid, display_name) VALUES
            ('admins', 'Administrators'),
            ('editors', 'Editors'),
            ('guests', 'Guests')");

        $this->dbHandle->exec("INSERT INTO group_members (group_id, user_id) VALUES
            ('admins', 'alice'),
            ('admins', 'bob'),
            ('editors', 'alice'),
            ('guests', 'chris')");

        return $mockDb;
    }
}
