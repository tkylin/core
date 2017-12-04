<?php
/**
 * @author Piotr Mrowczynski <piotr@owncloud.com>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\Group;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\GroupInterface;
use OCP\IConfig;
use OCP\ILogger;


/**
 * Class SyncService
 *
 * TODO
 *
 * @package OC\Group
 */
class SyncService {

	/** @var GroupInterface */
	private $backend;
	/** @var GroupMapper */
	private $mapper;
	/** @var IConfig */
	private $config;
	/** @var ILogger */
	private $logger;
	/** @var string */
	private $backendClass;
	/** @var boolean */
	private $prefetched;
	/** @var string[] */
	private $prefetchedGroupIds;

	/**
	 * SyncService constructor.
	 *
	 * @param GroupMapper $mapper
	 * @param GroupInterface $backend
	 * @param IConfig $config
	 * @param ILogger $logger
	 */
	public function __construct(GroupMapper $mapper,
								GroupInterface $backend,
								IConfig $config,
								ILogger $logger) {
		$this->mapper = $mapper;
		$this->backend = $backend;
		$this->backendClass = get_class($backend);
		$this->config = $config;
		$this->logger = $logger;
		$this->prefetched = false;
		$this->prefetchedGroupIds = [];
	}

	/**
	 * Prefetch groups and return number of backend service groups
	 *
	 * @return int count of prefetched groups
	 */
	public function prefetch() {
		$limit = 500;
		$offset = 0;
		$this->prefetchedGroupIds = [];
		do {
			$groups = $this->backend->getGroups('', $limit, $offset);
			$this->prefetchedGroupIds = array_merge($this->prefetchedGroupIds, $groups);
			$offset += $limit;
		} while(count($groups) >= $limit);

		$this->prefetched = true;
		return count($this->prefetchedGroupIds);
	}

	/**
	 * Run group sync service. This function will prefetch groups in case groups were not prefetched before-hands.
	 * Each successful run invalidates all prefetched groups.
	 *
	 * @param \Closure $callback is called for every user to progress display
	 */
	public function run(\Closure $callback) {
		if (!$this->prefetched) {
			$this->prefetch();
		}

		// update existing and insert new users
		foreach ($this->prefetchedGroupIds as $gid) {
			try {
				$group = $this->mapper->getGroup($gid);
				if ($group->getBackend() !== $this->backendClass) {
					$this->logger->warning(
						"Group <$gid> already provided by another backend({$group->getBackend()} != {$this->backendClass}), skipping.",
						['app' => self::class]
					);
					continue;
				}
				$b = $this->setupBackendGroup($group, $gid);
				$this->mapper->update($b);
			} catch(DoesNotExistException $ex) {
				$b = $this->createNewBackendGroup($gid);
				$this->setupBackendGroup($b, $gid);
				$this->mapper->insert($b);
			}

			// call the callback
			$callback($gid);
		}

		$this->prefetched = false;
		$this->prefetchedGroupIds = [];
	}

	/**
	 * @param \Closure $callback is called for every group to allow progress display
	 * @return array
	 */
	public function getNoLongerExistingGroup(\Closure $callback) {
		// detect no longer existing group
		$toBeDeleted = [];
		$this->mapper->callForAllGroups(function (BackendGroup $b) use (&$toBeDeleted, $callback) {
			if ($b->getBackend() == $this->backendClass) {
				if (!$this->backend->groupExists($b->getGroupId())) {
					$toBeDeleted[] = $b->getGroupId();
				}
			}
			$callback($b);
		}, '');

		return $toBeDeleted;
	}

	/**
	 * @param BackendGroup $b
	 * @param string $gid
	 * @return BackendGroup
	 */
	public function setupBackendGroup(BackendGroup $b, $gid) {
		$displayName = $gid;

		if ($this->backend->implementsActions(\OC\Group\Backend::GROUP_DETAILS)) {
			$groupData = $this->backend->getGroupDetails($gid);
			if (is_array($groupData) && isset($groupData['displayName'])) {
				// take the display name from the backend
				$displayName = $groupData['displayName'];
			}
		}
		$b->setDisplayName($displayName);
		return $b;
	}

	private function createNewBackendGroup($gid) {
		$b = new BackendGroup();
		$b->setGroupId($gid);
		$b->setBackend(get_class($this->backend));
		return $b;
	}

}
