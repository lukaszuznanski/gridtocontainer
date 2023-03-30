<?php
namespace SBublies\Gridtocontainer\Domain\Repository;


/***
 *
 * This file is part of the "Gridtocontainer" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2021 Stefan Bublies <project@sbublies.de>
 *
 ***/

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\DebugUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * The repository for Migration
 */
class MigrationRepository extends \TYPO3\CMS\Extbase\Persistence\Repository
{

		protected $table = 'tt_content';

		/**
		 *
		 * @return array|\TYPO3\CMS\Extbase\Persistence\QueryResultInterface
		 */
		public function findGridelements()
		{
				/** @var Connection $connection */
				$connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->table);
				/** @var QueryBuilder $queryBuilder */
				$queryBuilder = $connection->createQueryBuilder();
				$queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

				$results = $queryBuilder
					->select('*')
					->from($this->table)
					->where(
						$queryBuilder->expr()->like('CType', '"%gridelements_pi%"')
					)
					->execute()
					->fetchAll(\Doctrine\DBAL\FetchMode::ASSOCIATIVE);

				return $results;
		}

		/**
		 * @param $id
		 * @return mixed[]
		 */
		public function findContentfromGridElements($id)
		{
			/** @var Connection $connection */
			$connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->table);
			/** @var QueryBuilder $queryBuilder */
			$queryBuilder = $connection->createQueryBuilder();
			$queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

			$results = $queryBuilder
				->select('*')
				->from($this->table)
				->where(
					$queryBuilder->expr()->eq('tx_gridelements_container', $id)
				)
				->execute()
				->fetchAll(\Doctrine\DBAL\FetchMode::ASSOCIATIVE);

			return $results;
		}

		/**
		 * @param $id
		 * @return mixed[]
		 */
		public function findById($id)
		{
			/** @var Connection $connection */
			$connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->table);
			/** @var QueryBuilder $queryBuilder */
			$queryBuilder = $connection->createQueryBuilder();
			$queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

			$results = $queryBuilder
				->select('*')
				->from($this->table)
				->where(
					$queryBuilder->expr()->eq('uid', $id)
				)
				->execute(true)
				->fetchAll(\Doctrine\DBAL\FetchMode::ASSOCIATIVE);

			return $results;
		}

		/**
		 * @param $data
		 *
		 */
		public function updateGridElements($data)
		{
			/** @var Connection $connection */
			$connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->table);
			/** @var QueryBuilder $queryBuilder */
			$queryBuilder = $connection->createQueryBuilder();

			foreach ($data as $result) {
				$connection->update(
					$this->table,
					[
						'CType' => $result['containername'],
						'pi_flexform' => empty($result['cleanFlexForm']) ? $result['flexFormvalue'] : '',
						'tx_gridelements_backend_layout' => ''
					],
					[
						'uid' => $result['uid']
					]
				);
			}
			return true;
		}

		/**
		 * @param $data
		 *
		 */
		public function updateContentElements($data)
		{
			/** @var Connection $connection */
			$connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->table);
			/** @var QueryBuilder $queryBuilder */
			$queryBuilder = $connection->createQueryBuilder();

			foreach ($data as $result) {
				$connection->update(
					$this->table,
					[
						'colPos' => empty($result['sameCid']) ? ($result['columnid'] ?: 0): $result['sameCid'],
                        'tx_container_parent' => ((int)$result['l18nParent'] > 0 ? $result['l18nParent'] : $result['gridUid']),
						'tx_gridelements_container' => 0,
						'tx_gridelements_columns' => 0
					],
					[
						'uid' => $result['uid']
					]
				);
			}
			return true;
		}

		/**
		 *
		 * @return array|\TYPO3\CMS\Extbase\Persistence\QueryResultInterface
		 */
		public function findGridelementsCustom()
		{
			/** @var \TYPO3\CMS\Core\Database\ConnectionPool $connectionPool */
			$connectionPool = GeneralUtility::makeInstance( "TYPO3\\CMS\\Core\\Database\\ConnectionPool");
			/** @var \TYPO3\CMS\Core\Database\Query\QueryBuilder $queryBuilder */
			$queryBuilder = $connectionPool->getConnectionForTable($this->table)->createQueryBuilder();
			$queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

			$results = $queryBuilder
				->select('*')
				->from($this->table)
				->where(
					$queryBuilder->expr()->like('CType', '"%gridelements_pi%"')
				)
				->execute()
				->fetchAll(\Doctrine\DBAL\FetchMode::ASSOCIATIVE);
			return $results;
		}

		/**
		 * @param $gridElementsArray
		 * @return array
		 */
		public function findContent($gridElementsArray)
		{
			$contentElements = [];
			foreach($gridElementsArray as $id){
				if(empty($id)){
					continue;
				} else {
					$contentElements[$id['uid']] = $this->findContentfromGridElements($id['uid']);
				}
			}
			$contentElementsArray = [];
			foreach($contentElements as $key => $contentElement){
				if(empty($contentElement)){
					continue;
				} else {
					foreach ($contentElement as $cElement) {
						$contentElementsArray[$key][$cElement['tx_gridelements_columns']] = $contentElement;
					}
				}
			}

			return $contentElementsArray;
		}

		/**
		 * @param $elementsArray
		 * @return bool
		 */
		public function updateAllElements($elementsArray)
		{

			/** @var \TYPO3\CMS\Core\Database\ConnectionPool $connectionPool */
			$connectionPool = GeneralUtility::makeInstance( "TYPO3\\CMS\\Core\\Database\\ConnectionPool");
			/** @var \TYPO3\CMS\Core\Database\Query\QueryBuilder $queryBuilder */
			$queryBuilder = $connectionPool->getConnectionForTable($this->table)->createQueryBuilder();
			$queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

			foreach ($elementsArray as $key => $element) {
				if ($elementsArray[$key]['active'] == 1) {
					$elementsArray[$key]['contentelements'] = $queryBuilder
						->select('*')
						->from($this->table)
						->where(
							$queryBuilder->expr()->like('CType', '"%gridelements_pi%"'),
                            $queryBuilder->expr()->eq('tx_gridelements_backend_layout', $queryBuilder->createNamedParameter($key))
						)
						->execute()
						->fetchAll(\Doctrine\DBAL\FetchMode::ASSOCIATIVE);
				} else {
					unset($elementsArray[$key]);
				}
			}

			$contentElementResults = [];
			foreach ($elementsArray as $key => $results) {
				foreach ($results as $key2 => $elements) {
					if ($key2 == 'contentelements') {
						foreach ($results[$key2] as $element) {
							/** @var Connection $connection */
							$connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->table);
                            /** @var QueryBuilder $queryBuilder */
                            $queryBuilder = $connection->createQueryBuilder();
                            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                            $contentElements = $queryBuilder
                                ->select('*')
                                ->from($this->table)
                                ->where(
                                    $queryBuilder->expr()->eq('tx_gridelements_container', $element['uid'])
                                )
                                ->execute()
                                ->fetchAll(\Doctrine\DBAL\FetchMode::ASSOCIATIVE);
                            foreach($contentElements as $contentElement) {
                                $contentElementResults['parents'][$contentElement['uid']] = $contentElement['tx_gridelements_container'];
                            }
                            $contentElementResults[$key]['elements'][$element['uid']] = $contentElements;
							$contentElementResults[$key]['columns'] = $results['columns'];
						}
					}
				}
			}

			foreach ($contentElementResults as $grids) {
				foreach ($grids as $key => $contents) {
					if ($key == 'columns') {
						foreach ($grids[$key] as $key2 => $column) {
							foreach ($grids['elements'] as $key3 => $elements) {
								foreach ($elements as $element) {
									if ($element['tx_gridelements_columns'] == $key2) {
										/** @var Connection $connection */
										$connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->table);
										$connection->update(
											$this->table,
											[
												'colPos' => empty($column['sameCid']) ? ($column['columnid'] ?: 0) : $column['sameCid'],
                                                'tx_container_parent' => ((int)$element['l18n_parent'] > 0 ?  $contentElementResults['parents'][$element['l18n_parent']] : $key3),
                                                'tx_gridelements_container' => 0,
												'tx_gridelements_columns' => 0
											],
											[
												'uid' => $element['uid']
											]
										);
									}
								}
							}
						}
					}
				}
			}

			/** @var Connection $connection */
			$connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->table);
			foreach ($elementsArray as $results) {
				foreach ($results as $key => $elements) {
					if ($key == 'contentelements') {
						foreach ($results[$key] as $element) {
                            if (empty($results['cleanFlexForm'])) {
                                if ($results['flexFormvalue'] == 1) {
                                    $flexformValue = $element['pi_flexform'];
                                } else {
                                    $flexformValue = $results['flexFormvalue'];
                                }
                            } else {
                                $flexformValue = '';
                            }
							$connection->update(
								$this->table,
								[
									'CType' => $results['containername'],
									'pi_flexform' => $flexformValue,
									'tx_gridelements_backend_layout' => ''
								],
								[
									'uid' => $element['uid']
								]
							);
						}
					}
				}
			}

			return true;

		}
}
