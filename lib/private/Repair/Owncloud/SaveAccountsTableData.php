<?php
/**
 * @copyright Copyright (c) 2017 Joas Schilling <coding@schilljs.com>
 *
 * @author Joas Schilling <coding@schilljs.com>
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

namespace OC\Repair\Owncloud;

use Doctrine\DBAL\Exception\InvalidFieldNameException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\PreConditionNotMetException;

/**
 * Copies the email address from the accounts table to the preference table,
 * before the data structure is changed and the information is gone
 */
class SaveAccountsTableData implements IRepairStep {

	const BATCH_SIZE = 75;

	/** @var IDBConnection */
	protected $db;

	/** @var IConfig */
	protected $config;

	/**
	 * @param IDBConnection $db
	 * @param IConfig $config
	 */
	public function __construct(IDBConnection $db, IConfig $config) {
		$this->db = $db;
		$this->config = $config;
	}

	/**
	 * @return string
	 */
	public function getName() {
		return 'Copy data from accounts table when migrating from ownCloud';
	}

	/**
	 * @param IOutput $output
	 */
	public function run(IOutput $output) {
		if (!$this->shouldRun()) {
			return;
		}

		$offset = 0;
		$numUsers = $this->runStep($offset);

		while ($numUsers === self::BATCH_SIZE) {
			$offset += $numUsers;
			$numUsers = $this->runStep($offset);
		}

		// Clear the table
		$query = $this->db->getQueryBuilder();
		$query->delete('accounts');
		$query->execute();
	}

	/**
	 * @return bool
	 */
	protected function shouldRun() {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('accounts')
			->where($query->expr()->isNotNull('user_id'))
			->setMaxResults(1);

		try {
			$query->execute();
			return true;
		} catch (InvalidFieldNameException $e) {
			return false;
		}
	}

	/**
	 * @param int $offset
	 * @return int Number of copied users
	 */
	protected function runStep($offset) {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('accounts')
			->orderBy('id')
			->setMaxResults(self::BATCH_SIZE);

		if ($offset > 0) {
			$query->setFirstResult($offset);
		}

		$result = $query->execute();

		$update = $this->db->getQueryBuilder();
		$update->update('users')
			->set('displayname', $update->createParameter('displayname'))
			->where($update->expr()->eq('uid', $update->createParameter('userid')));

		$updatedUsers = 0;
		while ($row = $result->fetch()) {
			try {
				$this->migrateUserInfo($update, $row);
			} catch (PreConditionNotMetException $e) {
				// Ignore and continue
			} catch (\UnexpectedValueException $e) {
				// Ignore and continue
			}
			$updatedUsers++;
		}
		$result->closeCursor();

		return $updatedUsers;
	}

	/**
	 * @param IQueryBuilder $update
	 * @param array $userdata
	 * @throws PreConditionNotMetException
	 * @throws \UnexpectedValueException
	 */
	protected function migrateUserInfo(IQueryBuilder $update, $userdata) {
		if ($userdata['state'] === '3') {
			// Deleted user, ignore
			return;
		}

		if ($userdata['email'] !== null) {
			$this->config->setUserValue($userdata['user_id'], 'settings', 'email', $userdata['email']);
		}
		if ($userdata['quota'] !== null) {
			$this->config->setUserValue($userdata['user_id'], 'files', 'quota', $userdata['quota']);
		}
		if ($userdata['last_login'] !== null) {
			$this->config->setUserValue($userdata['user_id'], 'login', 'lastLogin', $userdata['last_login']);
		}
		if ($userdata['state'] === '1') {
			$this->config->setUserValue($userdata['user_id'], 'core', 'enabled', 'true');
		}
		if ($userdata['state'] === '2') {
			$this->config->setUserValue($userdata['user_id'], 'core', 'enabled', 'false');
		}

		if ($userdata['display_name'] !== null) {
			$update->setParameter('displayname', $userdata['display_name'])
				->setParameter('userid', $userdata['user_id']);
			$update->execute();
		}

	}
}

