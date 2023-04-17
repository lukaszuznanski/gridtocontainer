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

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver\Exception;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\DebugUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;
use Psr\Log\LoggerAwareTrait;

/**
 * The repository for Migration
 */
class MigrationRepository extends Repository
{
    protected string $table = 'tt_content';
    use LoggerAwareTrait;

    /**
     *
     * @return array|QueryResultInterface
     * @throws DBALException
     */
    public function findGridelements(): QueryResultInterface
    {
        /** @var Connection $connection */
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->table);
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $queryBuilder
            ->select('*')
            ->from($this->table)
            ->where(
                $queryBuilder->expr()->like('CType', '"%gridelements_pi%"')
            )
            ->execute()
            ->fetchAll(\Doctrine\DBAL\FetchMode::ASSOCIATIVE);
    }

    /**
     *
     * @return array
     * @throws DBALException
     */
    public function findGridelementsCustom(): array
    {
        /** @var ConnectionPool $connectionPool */
        $connectionPool = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Database\\ConnectionPool');
        $queryBuilder = $connectionPool->getConnectionForTable($this->table)->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $queryBuilder
            ->select('*')
            ->from($this->table)
            ->where(
                $queryBuilder->expr()->like('CType', '"%gridelements_pi%"')
            )
            ->execute()
            ->fetchAll(\Doctrine\DBAL\FetchMode::ASSOCIATIVE);
    }

    /**
     * @param $id
     * @return array
     * @throws DBALException
     */
    public function findContentfromGridElements($id): array
    {
        /** @var Connection $connection */
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->table);
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $queryBuilder
            ->select('*')
            ->from($this->table)
            ->where(
                $queryBuilder->expr()->eq('tx_gridelements_container', $id)
            )
            ->execute()
            ->fetchAll(\Doctrine\DBAL\FetchMode::ASSOCIATIVE);
    }

    /**
     * @param $id
     * @return array
     * @throws DBALException
     */
    public function findById($id): array
    {
        /** @var Connection $connection */
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->table);
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $queryBuilder
            ->select('*')
            ->from($this->table)
            ->where(
                $queryBuilder->expr()->eq('uid', $id)
            )
            ->execute(true)
            ->fetchAll(\Doctrine\DBAL\FetchMode::ASSOCIATIVE);
    }

    /**
     * @param $data
     * @return bool
     */
    public function updateGridElements($data): bool
    {
        /** @var Connection $connection */
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->table);
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
     * @return bool
     */
    public function updateContentElements($data): bool
    {
        /** @var Connection $connection */
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->table);
        $queryBuilder = $connection->createQueryBuilder();

        foreach ($data as $result) {
            if (empty($result['sameCid'])) {
                if (empty($result['columnid'])) {
                    $colPos = 0;
                } else {
                    $colPos = $result['columnid'];
                }
            } else {
                $colPos = $result['sameCid'];
            }
            if (isset($result['l18nParent']) && (int)$result['l18nParent'] > 0) {
                $txContainerParent = $result['l18nParent'];
            } else {
                $txContainerParent = $result['gridUid'];
            }
            $connection->update(
                $this->table,
                [
                    'colPos' => $colPos,
                    'tx_container_parent' => $txContainerParent,
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
     * @param $gridElementsArray
     * @return array
     * @throws DBALException
     */
    public function findContent($gridElementsArray): array
    {
        $contentElements = [];
        foreach ($gridElementsArray as $id) {
            if (empty($id)) {
                continue;
            }

            $contentElements[$id['uid']] = $this->findContentfromGridElements($id['uid']);
        }
        $contentElementsArray = [];
        foreach ($contentElements as $id2 => $contentElement) {
            if (empty($contentElement)) {
                continue;
            }

            foreach ($contentElement as $cElement) {
                $contentElementsArray[$id2][$cElement['tx_gridelements_columns']] = $contentElement;
            }
        }

        return $contentElementsArray;
    }

    /**
     * @param $elementsArray
     * @return bool
     * @throws DBALException
     * @throws Exception
     */
    public function updateAllElements($elementsArray): bool
    {
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'] = [
            // configuration for ERROR level log entries
            \TYPO3\CMS\Core\Log\LogLevel::INFO => [
                // add a FileWriter
                \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
                    // configuration for the writer
                    'logFile' => \TYPO3\CMS\Core\Core\Environment::getVarPath() . '/log/migrate-grid-to-container.typo3-package.log'
                ]
            ]
        ];

        $this->logger = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)->getLogger(__CLASS__);

        $this->logger->info('Start updateAllElements');

        /** @var ConnectionPool $connectionPool */
        $connectionPool = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Database\\ConnectionPool');
        $queryBuilder = $connectionPool->getConnectionForTable($this->table)->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        foreach ($elementsArray as $grididentifier => $elements) {
            if ($elementsArray[$grididentifier]['active'] === 1) {
                $elementsArray[$grididentifier]['contentelements'] = $queryBuilder
                    ->select(
                        'uid',
                        'pid',
                        'colPos',
                        'backupColPos',
                        'CType',
                        'tx_gridelements_backend_layout',
                        'tx_gridelements_container',
                        'tx_gridelements_columns',
                        'tx_gridelements_children',
                        'tx_container_parent',
                        'l18n_parent',
                        'hidden',
                        'deleted',
                        'header',
                        'pi_flexform',
                        'sys_language_uid ',
                    )
                    ->from($this->table)
                    ->where(
                        $queryBuilder->expr()->like('CType', '"%gridelements_pi%"'),
                        $queryBuilder->expr()->eq('tx_gridelements_backend_layout',
                            $queryBuilder->createNamedParameter($grididentifier)
                        )
                    )
                    ->execute()
                    ->fetchAllAssociative();

                foreach ($elementsArray[$grididentifier]['contentelements'] as $_contentElement) {
                    $logData = [
                        'uid' => $_contentElement['uid'],
                        'pid' => $_contentElement['pid'],
                        'colPos' => $_contentElement['colPos'],
                        'backupColPos' => $_contentElement['backupColPos'],
                        'CType' => $_contentElement['CType'],
                        'tx_gridelements_backend_layout' => $_contentElement['tx_gridelements_backend_layout'],
                        'tx_gridelements_container' => $_contentElement['tx_gridelements_container'],
                        'tx_gridelements_columns' => $_contentElement['tx_gridelements_columns'],
                        'tx_gridelements_children' => $_contentElement['tx_gridelements_children'],
                        'l18n_parent' => $_contentElement['l18n_parent'],
                        'sys_language_uid' => $_contentElement['sys_language_uid'],
                    ];

                    $this->logger->info('Select where CType=gridelements_pi && tx_gridelements_backend_layout='.$grididentifier, $logData);
                }

            } else {
                unset($elementsArray[$grididentifier]);
            }
        }

        $contentElementResults = [];
        foreach ($elementsArray as $grididentifier => $results) {
            foreach ($results as $key2 => $elements) {
                if ($key2 === 'contentelements') {
                    foreach ($results[$key2] as $element) {
                        /** @var Connection $connection */
                        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->table);
                        $queryBuilder = $connection->createQueryBuilder();
                        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                        $contentElements = $queryBuilder
                            ->select(
                                'uid',
                                'pid',
                                'colPos',
                                'backupColPos',
                                'CType',
                                'tx_gridelements_backend_layout',
                                'tx_gridelements_container',
                                'tx_gridelements_columns',
                                'tx_gridelements_children',
                                'tx_container_parent',
                                'l18n_parent',
                                'hidden',
                                'deleted',
                                'header',
                                'pi_flexform',
                                'sys_language_uid ',
                            )
                            ->from($this->table)
                            ->where(
                                $queryBuilder->expr()->eq('tx_gridelements_container', $element['uid'])
                            )
                            ->orWhere(
                                $queryBuilder->expr()->eq('l18n_parent', $element['uid'])
                            )
                            ->execute()
                            ->fetchAllAssociative();

                        foreach ($contentElements as $contentElement) {
                            $contentElementResults['parents'][$contentElement['uid']] = $contentElement['tx_gridelements_container'];

                            $logData = [
                                'uid' => $contentElement['uid'],
                                'pid' => $contentElement['pid'],
                                'colPos' => $contentElement['colPos'],
                                'backupColPos' => $contentElement['backupColPos'],
                                'CType' => $contentElement['CType'],
                                'tx_gridelements_backend_layout' => $contentElement['tx_gridelements_backend_layout'],
                                'tx_gridelements_container' => $contentElement['tx_gridelements_container'],
                                'tx_gridelements_columns' => $contentElement['tx_gridelements_columns'],
                                'tx_gridelements_children' => $contentElement['tx_gridelements_children'],
                                'l18n_parent' => $contentElement['l18n_parent'],
                                'sys_language_uid' => $contentElement['sys_language_uid'],
                            ];

                            $this->logger->info('Select where tx_gridelements_container='.$element['uid'], $logData);
                        }
                        $contentElementResults[$grididentifier]['elements'][$element['uid']] = $contentElements;
                        $contentElementResults[$grididentifier]['columns'] = $results['columns'];
                    }
                }
            }
        }

        foreach ($contentElementResults as $grididentifier) {
            foreach ($grididentifier as $key => $contents) {
                if ($key === 'columns') {
                    foreach ($grididentifier[$key] as $oldColumnId => $newColumnId) {
                        foreach ($grididentifier['elements'] as $uidElements => $elements) {
                            foreach ($elements as $element) {
                                if ((int)$element['tx_gridelements_columns'] === (int)$oldColumnId) {

                                    if ($newColumnId['sameCid'] === null) {
                                        if (empty($newColumnId['columnid'])) {
                                            $colPos = 0;
                                        } else {
                                            $colPos = $newColumnId['columnid'];
                                        }
                                    } else {
                                        $colPos = $newColumnId['sameCid'];
                                    }

                                    if (isset($element['l18n_parent']) && (int)$element['l18n_parent'] > 0) {
                                        $txContainerParent = (int)$contentElementResults['parents'][$element['l18n_parent']];
                                        //$txContainerParent = (int)$uidElements;
                                    } else if (isset($contentElementResults['parents'][$element['uid']]) && (int)$contentElementResults['parents'][$element['uid']] > 0) {
                                        $txContainerParent = (int)$contentElementResults['parents'][$element['uid']];
                                    } else {
                                        $txContainerParent = (int)$uidElements;
                                    }
                                    /** @var Connection $connection */
                                    $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->table);

                                    $updateCols = [
                                        'colPos' => $colPos,
                                        'tx_container_parent' => $txContainerParent,
                                        //'tx_gridelements_container' => 0,
                                        //'tx_gridelements_columns' => 0
                                    ];

                                    $this->logger->info('Update ' . $this->table . ' whare UID=' . $element['uid'], $updateCols);

                                    $connection->update(
                                        $this->table,
                                        $updateCols,
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
                if ($key === 'contentelements') {
                    foreach ($results[$key] as $element) {
                        if (empty($results['cleanFlexForm'])) {
                            if ($results['flexFormvalue'] === 1) {
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

        $this->logger->info('End updateAllElements');

        return true;
    }

    /**
     * @return bool
     * @throws DBALException
     * @throws Exception
     */
    public function logColPosErrors(): bool
    {
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'] = [
            // configuration for ERROR level log entries
            \TYPO3\CMS\Core\Log\LogLevel::INFO => [
                // add a FileWriter
                \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
                    // configuration for the writer
                    'logFile' => \TYPO3\CMS\Core\Core\Environment::getVarPath() . '/log/migrate-grid-to-container.typo3-package-errors.log'
                ]
            ]
        ];

        $this->logger = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)->getLogger(__CLASS__);

        $this->logger->info('Start logColPosErrors');

        // select elements
        /** @var ConnectionPool $connectionPool */
        $connectionPool = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Database\\ConnectionPool');
        $queryBuilder = $connectionPool->getConnectionForTable($this->table)->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $dataArray = $queryBuilder
            ->select(
                'uid',
                'pid',
                'colPos',
                'backupColPos',
                'CType',
                'tx_container_parent',
                'tx_gridelements_columns',
                'tx_gridelements_container',
                'tx_gridelements_backend_layout',
                'tx_gridelements_children',
                'l18n_parent',
                'hidden',
                'deleted',
                'header',
            )
            ->from($this->table)
            ->where(
                $queryBuilder->expr()->eq('colPos',
                    $queryBuilder->createNamedParameter(-1)
                )
            )
            ->orWhere(
                $queryBuilder->expr()->eq('colPos',
                    $queryBuilder->createNamedParameter(-2)
                )
            )
            ->execute()
            ->fetchAllAssociative();

        foreach ($dataArray as $dataElement) {
            $this->logger->info('Error data: ', $dataElement);
        }

        $this->logger->info('End logColPosErrors');

        return true;
    }
}
