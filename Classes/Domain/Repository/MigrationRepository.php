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
        foreach ($contentElements as $key => $contentElement) {
            if (empty($contentElement)) {
                continue;
            }

            foreach ($contentElement as $cElement) {
                $contentElementsArray[$key][$cElement['tx_gridelements_columns']] = $contentElement;
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
                    'logFile' => \TYPO3\CMS\Core\Core\Environment::getVarPath() . '/log/typo3_grid_to_container_migration.log'
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
                        'CType',
                        'colPos',
                        'tx_gridelements_backend_layout',
                        'tx_gridelements_container',
                        'tx_gridelements_columns',
                        'tx_container_parent',
                        'pi_flexform'
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
                        'CType' => $_contentElement['CType'],
                        'colPos' => $_contentElement['colPos'],
                        'tx_gridelements_backend_layout' => $_contentElement['tx_gridelements_backend_layout'],
                        'tx_gridelements_container' => $_contentElement['tx_gridelements_container'],
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
                                'CType',
                                'colPos',
                                'tx_gridelements_backend_layout',
                                'tx_gridelements_container',
                                'tx_gridelements_columns',
                                'tx_container_parent',
                                'pi_flexform',
                                //'l18nParent'
                            )
                            ->from($this->table)
                            ->where(
                                $queryBuilder->expr()->eq('tx_gridelements_container', $element['uid'])
                            )
                            ->execute()
                            ->fetchAllAssociative();

                        foreach ($contentElements as $contentElement) {
                            $contentElementResults['parents'][$contentElement['uid']] = $contentElement['tx_gridelements_container'];

                            $logData = [
                                'uid' => $contentElement['uid'],
                                'CType' => $contentElement['CType'],
                                'colPos' => $contentElement['colPos'],
                                'tx_gridelements_backend_layout' => $contentElement['tx_gridelements_backend_layout'],
                                'tx_gridelements_container' => $contentElement['tx_gridelements_container'],
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
                    foreach ($grididentifier[$key] as $key2 => $column) {
                        foreach ($grididentifier['elements'] as $key3 => $elements) {
                            foreach ($elements as $element) {
                                if ($element['tx_gridelements_columns'] === $key2) {

                                    if ($column['sameCid'] === null) {
                                        if (empty($column['columnid'])) {
                                            $colPos = 0;
                                        } else {
                                            $colPos = $column['columnid'];
                                        }
                                    } else {
                                        $colPos = $column['sameCid'];
                                    }

                                    if (isset($element['l18nParent']) && (int)$element['l18nParent'] > 0) {
                                        $txContainerParent = $contentElementResults['parents'][$element['l18n_parent']];
                                    } else {
                                        $txContainerParent = $key3;
                                    }
                                    /** @var Connection $connection */
                                    $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->table);

                                    $updateCols = [
                                        'colPos' => $colPos,
                                        'tx_container_parent' => $txContainerParent,
                                        'tx_gridelements_container' => 0,
                                        'tx_gridelements_columns' => 0
                                    ];

                                    $this->logger->info('Update '.$this->table.' whare UID=: '.$element['uid'], $updateCols);

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
    public function updateContentElementsCommend(): bool
    {
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'] = [
            // configuration for ERROR level log entries
            \TYPO3\CMS\Core\Log\LogLevel::INFO => [
                // add a FileWriter
                \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
                    // configuration for the writer
                    'logFile' => \TYPO3\CMS\Core\Core\Environment::getVarPath() . '/log/typo3_grid_to_container_migration.log'
                ]
            ]
        ];

        $this->logger = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)->getLogger(__CLASS__);

        $this->logger->info('Start updateContentElements');

        // select elements
        /** @var ConnectionPool $connectionPool */
        $connectionPool = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Database\\ConnectionPool');
        $queryBuilder = $connectionPool->getConnectionForTable($this->table)->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $dataArray = $queryBuilder
            ->select(
                'uid',
                'colPos',
                'tx_gridelements_columns',
                'tx_gridelements_container'
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
            $this->logger->info('Select '.$this->table.' whare colPos=-1 OR colPos=-2', $dataElement);
        }

        // update contents (colPos) for content && parent
        foreach ($dataArray as $element) {

            if (empty($element['uid'])) {
                continue;
            }

            if (!empty($element['tx_gridelements_columns']) && $element['tx_gridelements_columns'] > 0) {
                $colPos = $element['tx_gridelements_columns'];
            } else {
                $colPos = 0;
            }

            if (!empty($element['tx_gridelements_container']) && $element['tx_gridelements_container'] > 0) {
                $tx_container_parent = $element['tx_gridelements_container'];
            } else {
                $tx_container_parent = 0;
            }

            /** @var Connection $connection */
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->table);

            $updateData = [
                'colPos' => $colPos,
                'tx_container_parent' =>$tx_container_parent
            ];

            $connection->update(
                $this->table,
                $updateData,
                [
                    'uid' => $element['uid']
                ]
            );

            $this->logger->info('Update '.$this->table.' whare UID=: '.$element['uid'], $updateData);

            $connection->update(
                $this->table,
                [
                    'tx_gridelements_columns' => 0,
                    'tx_gridelements_container' => 0
                ],
                [
                    'uid' => $element['uid']
                ]
            );
        }

        $this->logger->info('End updateContentElements');

        return true;
    }
}
