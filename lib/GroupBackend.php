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

namespace OCA\UserBackendSqlRaw;

use OC\User\LazyUser;
use OCP\Group\Backend\ABackend;
use OCP\Group\Backend\IAddToGroupBackend;
use OCP\Group\Backend\ICountUsersBackend;
use OCP\Group\Backend\ICreateNamedGroupBackend;
use OCP\Group\Backend\IDeleteGroupBackend;
use OCP\Group\Backend\IGetDisplayNameBackend;
use OCP\Group\Backend\INamedBackend;
use OCP\Group\Backend\IRemoveFromGroupBackend;
use OCP\Group\Backend\ISearchableGroupBackend;
use OCP\Group\Backend\ISetDisplayNameBackend;
use OCP\GroupInterface;
use OCP\IUserManager;
use OCP\Server;
use Psr\Log\LoggerInterface;

class GroupBackend extends ABackend implements
    INamedBackend,
    IAddToGroupBackend,
    IRemoveFromGroupBackend,
    ICreateNamedGroupBackend,
    IDeleteGroupBackend,
    ICountUsersBackend,
    IGetDisplayNameBackend,
    ISearchableGroupBackend,
    ISetDisplayNameBackend {

    /** @var LoggerInterface */
    private $logger;
    private $config;
    private $db;

    public function __construct(LoggerInterface $logger, Config $config, Db $db)
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->db = $db;
    }

    public function getBackendName(): string
    {
        return 'SQL raw';
    }

    /**
     * Keep dynamic capability checks based on configured SQL queries.
     */
    public function implementsActions($actions): bool
    {
        return (bool)((
                (!empty($this->config->getQueryCreateGroup()) ? GroupInterface::CREATE_GROUP : 0)
                | (!empty($this->config->getQueryDeleteGroup()) ? GroupInterface::DELETE_GROUP : 0)
                | (!empty($this->config->getQueryAddUserToGroup()) ? GroupInterface::ADD_TO_GROUP : 0)
                | (!empty($this->config->getQueryRemoveUserFromGroup()) ? GroupInterface::REMOVE_FROM_GROUP : 0)
                | (!empty($this->config->getQueryCountGroupUsers()) ? GroupInterface::COUNT_USERS : 0)
                | (!empty($this->config->getQueryGetGroupDisplayName()) ? GroupInterface::GROUP_DETAILS : 0)
            ) & $actions);
    }

    public function inGroup($uid, $gid): bool
    {
        return in_array($gid, $this->getUserGroups($uid), true);
    }

    public function getUserGroups($uid): array
    {
        if (empty($this->config->getQueryGetUserGroups())) {
            return [];
        }

        $statement = $this->db->getDbHandle()->prepare($this->config->getQueryGetUserGroups());
        $statement->execute(['user_id' => $uid]);
        $groups = $statement->fetchAll(\PDO::FETCH_COLUMN, 0);
        return array_map('strval', $groups ?: []);
    }

    public function getGroups(string $search = '', int $limit = -1, int $offset = 0): array
    {
        if (empty($this->config->getQueryGetGroups())) {
            return [];
        }

        $searchString = $this->escapePercentAndUnderscore($search);
        $queryFromConfig = $this->config->getQueryGetGroups();

        $limitSegment = ($limit >= 0) ? ' LIMIT :limit' : '';
        $offsetSegment = ($limit >= 0 && $offset > 0) ? ' OFFSET :offset' : '';
        $finalQuery = $queryFromConfig . $limitSegment . $offsetSegment;

        $statement = $this->db->getDbHandle()->prepare($finalQuery);
        $statement->bindValue(':search', '%' . $searchString . '%', \PDO::PARAM_STR);
        if ($limit >= 0) {
            $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
        }
        if ($limit >= 0 && $offset > 0) {
            $statement->bindValue(':offset', $offset, \PDO::PARAM_INT);
        }
        $statement->execute();

        $groups = $statement->fetchAll(\PDO::FETCH_COLUMN, 0);
        return array_map('strval', $groups ?: []);
    }

    public function groupExists($gid): bool
    {
        if (empty($this->config->getQueryGroupExists())) {
            return in_array($gid, $this->getGroups($gid, 1, 0), true);
        }

        $statement = $this->db->getDbHandle()->prepare($this->config->getQueryGroupExists());
        $statement->execute(['group_id' => $gid]);
        return (bool)$statement->fetchColumn();
    }

    public function usersInGroup($gid, $search = '', $limit = -1, $offset = 0): array
    {
        return array_keys($this->searchInGroup($gid, $search, $limit, $offset));
    }

    public function searchInGroup(string $gid, string $search = '', int $limit = -1, int $offset = 0): array
    {
        if (empty($this->config->getQueryGetGroupUsers())) {
            return [];
        }

        $searchString = $this->escapePercentAndUnderscore($search);
        $queryFromConfig = $this->config->getQueryGetGroupUsers();
        $limitSegment = ($limit >= 0) ? ' LIMIT :limit' : '';
        $offsetSegment = ($limit >= 0 && $offset > 0) ? ' OFFSET :offset' : '';
        $finalQuery = $queryFromConfig . $limitSegment . $offsetSegment;

        $statement = $this->db->getDbHandle()->prepare($finalQuery);
        $statement->bindValue(':group_id', $gid, \PDO::PARAM_STR);
        $statement->bindValue(':search', '%' . $searchString . '%', \PDO::PARAM_STR);
        if ($limit >= 0) {
            $statement->bindValue(':limit', intval($limit), \PDO::PARAM_INT);
        }
        if ($limit >= 0 && $offset > 0) {
            $statement->bindValue(':offset', intval($offset), \PDO::PARAM_INT);
        }
        $statement->execute();

        $users = $statement->fetchAll(\PDO::FETCH_COLUMN, 0);
        $userManager = Server::get(IUserManager::class);
        $matchedUsers = [];
        foreach (array_map('strval', $users ?: []) as $uid) {
            $matchedUsers[$uid] = new LazyUser($uid, $userManager);
        }
        return $matchedUsers;
    }

    public function addToGroup(string $uid, string $gid): bool
    {
        if (empty($this->config->getQueryAddUserToGroup())) {
            return false;
        }

        $statement = $this->db->getDbHandle()->prepare($this->config->getQueryAddUserToGroup());
        return (bool)$this->executeOrCatchExceptionAndReturnFalse($statement, [
            ':user_id' => $uid,
            ':group_id' => $gid,
        ]);
    }

    public function removeFromGroup(string $uid, string $gid)
    {
        if (empty($this->config->getQueryRemoveUserFromGroup())) {
            return false;
        }

        $statement = $this->db->getDbHandle()->prepare($this->config->getQueryRemoveUserFromGroup());
        return (bool)$this->executeOrCatchExceptionAndReturnFalse($statement, [
            ':user_id' => $uid,
            ':group_id' => $gid,
        ]);
    }

    public function createGroup(string $name): ?string
    {
        if (empty($this->config->getQueryCreateGroup())) {
            return null;
        }

        $statement = $this->db->getDbHandle()->prepare($this->config->getQueryCreateGroup());
        $groupId = $name;
        $created = (bool)$this->executeOrCatchExceptionAndReturnFalse($statement, [
            ':group_id' => $groupId,
            ':display_name' => $name,
        ]);

        return $created ? $groupId : null;
    }

    public function deleteGroup(string $gid): bool
    {
        if (empty($this->config->getQueryDeleteGroup())) {
            return false;
        }

        $statement = $this->db->getDbHandle()->prepare($this->config->getQueryDeleteGroup());
        return (bool)$this->executeOrCatchExceptionAndReturnFalse($statement, [
            ':group_id' => $gid,
        ]);
    }

    public function countUsersInGroup(string $gid, string $search = ''): int
    {
        if (empty($this->config->getQueryCountGroupUsers())) {
            return 0;
        }

        $statement = $this->db->getDbHandle()->prepare($this->config->getQueryCountGroupUsers());
        $statement->execute([
            'group_id' => $gid,
            'search' => '%' . $this->escapePercentAndUnderscore($search) . '%',
        ]);

        $count = $statement->fetchColumn();
        return ($count === false) ? 0 : (int)$count;
    }

    public function getDisplayName(string $gid): string
    {
        if (empty($this->config->getQueryGetGroupDisplayName())) {
            return '';
        }

        $statement = $this->db->getDbHandle()->prepare($this->config->getQueryGetGroupDisplayName());
        $statement->execute(['group_id' => $gid]);
        $displayName = $statement->fetchColumn();
        return ($displayName === false || is_null($displayName)) ? '' : (string)$displayName;
    }

    public function getGroupDetails(string $gid): array
    {
        if (empty($this->config->getQueryGetGroupDisplayName())) {
            return [];
        }

        $displayName = $this->getDisplayName($gid);
        if ($displayName !== '') {
            return ['displayName' => $displayName];
        }

        return $this->groupExists($gid) ? ['displayName' => $gid] : [];
    }

    public function setDisplayName(string $gid, string $displayName): bool
    {
        if (empty($this->config->getQuerySetGroupDisplayName())) {
            return false;
        }

        $statement = $this->db->getDbHandle()->prepare($this->config->getQuerySetGroupDisplayName());
        return (bool)$this->executeOrCatchExceptionAndReturnFalse($statement, [
            ':group_id' => $gid,
            ':new_display_name' => $displayName,
        ]);
    }

    private function escapePercentAndUnderscore(string $input): string
    {
        return str_replace('%', '\\%', str_replace('_', '\\_', $input));
    }

    /**
     * Helper function that catches SQL exceptions for methods that should return FALSE on failure.
     *
     * @param \PDOStatement $pdoStatement the statement to execute
     * @param array $parameterSubstitutions the substitution parameters
     *
     * @return bool|\PDOStatement
     */
    private function executeOrCatchExceptionAndReturnFalse(\PDOStatement $pdoStatement, array $parameterSubstitutions)
    {
        try {
            $pdoStatement->execute($parameterSubstitutions);
        } catch (\PDOException $exception) {
            $this->logger->error('A SQL error occurred during a user_backend_sql_raw operation. '
                . 'See SQLSTATE exception above for details.', ['exception' => $exception]);
            return false;
        }
        return $pdoStatement;
    }
}
