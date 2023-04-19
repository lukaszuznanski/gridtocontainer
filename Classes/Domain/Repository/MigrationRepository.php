<?php

namespace SBublies\Gridtocontainer\Domain\Repository;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver\Exception;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * The repository for Migration
 */
class MigrationRepository extends Repository implements \Psr\Log\LoggerAwareInterface
{
    protected string $table = 'tt_content';
    protected QueryBuilder $queryBuilder;
    use LoggerAwareTrait;

    /**
     * @return void
     */
    public function initializeObject(): void
    {
        $this->queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($this->table);
        $this->queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'] = [
            \TYPO3\CMS\Core\Log\LogLevel::INFO => [
                \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
                    'logFile' => \TYPO3\CMS\Core\Core\Environment::getVarPath() . '/log/migrate-grid-to-container.typo3-package.log'
                ]
            ]
        ];

        $this->logger = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)->getLogger(__CLASS__);
    }

    /**
     * @param $elementsArray
     * @return bool
     * @throws DBALException
     * @throws Exception
     */
    public function updateAllElements($elementsArray): bool
    {
        $this->logger->info('Start updateAllElements');

        foreach ($elementsArray as $grididentifier => $elements) {
            if ($elementsArray[$grididentifier]['active'] === 1) {
                $elementsArray[$grididentifier]['contentelements'] = $this->queryBuilder
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
                        $this->queryBuilder->expr()->like(
                            'CType',
                            $this->queryBuilder->createNamedParameter('%' . $this->queryBuilder->escapeLikeWildcards('gridelements_pi') . '%')
                        ),
                        $this->queryBuilder->expr()->like(
                            'tx_gridelements_backend_layout',
                            $this->queryBuilder->createNamedParameter('%' . $this->queryBuilder->escapeLikeWildcards($grididentifier) . '%')
                        )
                    )
                    ->execute()
                    ->fetchAllAssociative();

                foreach ($elementsArray[$grididentifier]['contentelements'] as $contentElement) {
                    $this->logger->info(
                        'Select where CType=gridelements_pi && tx_gridelements_backend_layout='.$grididentifier,
                        [
                            'uid' => $contentElement['uid'],
                            'pid' => $contentElement['pid'],
                            'colPos' => $contentElement['colPos'],
                            'backupColPos' => $contentElement['backupColPos'],
                            'CType' => $contentElement['CType'],
                            'tx_gridelements_backend_layout' => $contentElement['tx_gridelements_backend_layout'],
                            'tx_gridelements_container' => $contentElement['tx_gridelements_container'],
                            'tx_gridelements_columns' => $contentElement['tx_gridelements_columns'],
                            'tx_gridelements_children' => $contentElement['tx_gridelements_children'],
                            'tx_container_parent' => $contentElement['tx_container_parent'],
                            'l18n_parent' => $contentElement['l18n_parent'],
                            'sys_language_uid' => $contentElement['sys_language_uid'],
                        ]
                    );
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
                        $contentElements = $this->queryBuilder
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
                                $this->queryBuilder->expr()->eq('tx_gridelements_container', $element['uid'])
                            )
                            ->orWhere(
                                $this->queryBuilder->expr()->eq('l18n_parent', $element['uid'])
                            )
                            ->execute()
                            ->fetchAllAssociative();

                        foreach ($contentElements as $contentElement) {
                            $contentElementResults['parents'][$contentElement['uid']] = $contentElement['tx_gridelements_container'];
                            $this->logger->info(
                                'Select where tx_gridelements_container='.$element['uid'],
                                [
                                    'uid' => $contentElement['uid'],
                                    'pid' => $contentElement['pid'],
                                    'colPos' => $contentElement['colPos'],
                                    'backupColPos' => $contentElement['backupColPos'],
                                    'CType' => $contentElement['CType'],
                                    'tx_gridelements_backend_layout' => $contentElement['tx_gridelements_backend_layout'],
                                    'tx_gridelements_container' => $contentElement['tx_gridelements_container'],
                                    'tx_gridelements_columns' => $contentElement['tx_gridelements_columns'],
                                    'tx_gridelements_children' => $contentElement['tx_gridelements_children'],
                                    'tx_container_parent' => $contentElement['tx_container_parent'],
                                    'l18n_parent' => $contentElement['l18n_parent'],
                                    'sys_language_uid' => $contentElement['sys_language_uid'],
                                ]
                            );
                        }
                        $contentElementResults[$grididentifier]['elements'][$element['uid']] = $contentElements;
                        $contentElementResults[$grididentifier]['columns'] = $results['columns'];
                    }
                }
            }
        }

        /*
         * Struktura danych tabeli tt_content
         *
         * colPos
         * przechowuje ID columny w której jest osadzony rekord
         * gridelement ustawia -1 jeżeli:
         * gridelement ustawia -2 jeżeli:
         * jeżeli rekort jest osadzony w b13/container
         * colPos = id kolumny z konfiguracji np. 200, 201, 202, 203
         *
         * tx_gridelements_container
         * pole gridelements, przechowuje ID columny parent konfiguracji np. 0, 1, 2, 3
         * domyślna migracja: tx_gridelements_container * 100 => tx_container_parent
         *
         * tx_gridelements_columns
         * pole pakietu gridelements, przechowuje ID columny z konfiguracji np. 0, 1, 2, 3
         * domyślna migracja: tx_gridelements_columns * 100 => colPos
         *
         * tx_gridelements_children (nie używane w czasie migracji)
         * pole pakietu gridelements, przechowuje id columny children
         *
         * tx_gridelements_backend_layout (używane w czasie migracji elementów grid, nie używane w czasie migracji contentu)
         * pole b13/container, przechowuje konfigurację nazwy pole np. 1-1, 1-2_1-2, 1-3_1-3_1-3
         *
         * tx_container_parent
         * pole pakietu b13/container - przechowuje ID columny parent z konfiguracji pakietu
         * domyślna migracja: tx_gridelements_container * 100 => tx_container_parent
         *
         * sys_language_uid
         * pole z ID języka, jeżeli sys_language_uid > 0 to rekord jest tłumaczeniem
         * jeżeli rekord jest tłumaczeniem parent id jest przechowane w polu l18n_parent / l10n_parent
         *
         * l18n_parent / l10n_parent
         * służy do lokalizacji. Zawsze zawiera identyfikator rekordu w języku domyślnym
         * (nawet jeśli rekord został przetłumaczony z rekordu w języku innym niż domyślny)
         *
         * l10n_source
         * zawiera identyfikator ID rekordu używanego jako źródło tłumaczenia
         * niezależnie od tego, czy rekord został przetłumaczony w trybie wolnym, czy połączonym
         *
         * t3_origuid - wypełniane podczas kopiowania lub tłumaczenia rekordu i zawiera identyfikator rekordu źródłowego
         */

        foreach ($contentElementResults as $gridIdentifier) {
            foreach ($gridIdentifier as $key => $contents) {
                if ($key === 'columns') {
                    foreach ($gridIdentifier[$key] as $oldColumnId => $newColumnId) {
                        foreach ($gridIdentifier['elements'] as $uidElements => $elements) {
                            foreach ($elements as $elementKey => $element) {
                                if ($element['tx_gridelements_columns'] === $oldColumnId) {
                                    if ((int)$elements[$elementKey]['colPos'] === 0) {
                                        $colPos = 0;
                                    } else if ($elements[$elementKey]['tx_gridelements_columns'] === $oldColumnId) {
                                        $colPos = (int)$newColumnId['columnid'];
                                    } else {
                                        $colPos = 0;
                                    }

                                    if ((int)$element['sys_language_uid'] > 0 && $colPos === 0) {
                                        $txContainerParent = 0;
                                    } else if ((int)$element['sys_language_uid'] > 0 && isset($element['l18n_parent']) && (int)$element['l18n_parent'] > 0) {
                                        $txContainerParent = (int)$contentElementResults['parents'][$element['l18n_parent']];
                                    } else if ($colPos === 0) {
                                        $txContainerParent = (int)$element['tx_gridelements_container'];
                                    } else {
                                        $txContainerParent = (int)$uidElements;
                                    }

                                    if ($txContainerParent === 0 && $colPos > 0) {
                                        continue;
                                    }

                                    $this->queryBuilder->update($this->table)
                                        ->where($this->queryBuilder->expr()->eq('uid', $this->queryBuilder->createNamedParameter($element['uid'])))
                                        ->set('colPos', $colPos)
                                        ->execute();

                                    $this->queryBuilder->update($this->table)
                                        ->where($this->queryBuilder->expr()->eq('uid', $this->queryBuilder->createNamedParameter($element['uid'])))
                                        ->set('tx_container_parent', $txContainerParent)
                                        ->execute();

                                    $this->logger->info(
                                        'Update Grids Contents '.$this->table.' whare UID='.$element['uid'],
                                        [
                                            'uid' => $element['uid'],
                                            'pid' => $element['pid'],
                                            'colPos' => $colPos,
                                            'backupColPos' => $element['backupColPos'],
                                            'CType' => $gridIdentifier['containername'],
                                            'tx_gridelements_backend_layout' => $element['tx_gridelements_backend_layout'],
                                            'tx_gridelements_container' => $element['tx_gridelements_container'],
                                            'tx_gridelements_columns' => $element['tx_gridelements_columns'],
                                            'tx_gridelements_children' => $element['tx_gridelements_children'],
                                            'tx_container_parent' => $txContainerParent,
                                            'l18n_parent' => $element['l18n_parent'],
                                            'sys_language_uid' => $element['sys_language_uid'],
                                        ]
                                    );
                                }
                            }
                        }
                    }
                }
            }
        }

        foreach ($elementsArray as $gridIdentifier => $gridsElements) {
            foreach ($gridsElements as $dataType => $elements) {
                if ($dataType === 'contentelements') {
                    foreach ($gridsElements[$dataType] as $gridElement) {
                        if ((int)$gridElement['colPos'] === 0) {
                            $colPos = 0;
                        } else if (isset($gridElement['tx_gridelements_columns']) && (string)$gridElement['tx_gridelements_columns'] !== '') {
                            $colPos = (int)$elementsArray[$gridIdentifier]['columns'][(int)$gridElement['tx_gridelements_columns']]['columnid'];
                        } else {
                            $colPos = 0;
                        }

                        if ((int)$gridElement['sys_language_uid'] > 0 && $colPos === 0) {
                            $txContainerParent = 0;
                        } else if ((int)$gridElement['sys_language_uid'] > 0 && isset($gridElement['l18n_parent']) && (int)$gridElement['l18n_parent'] > 0) {
                            $txContainerParent = (int)$contentElementResults['parents'][$gridElement['l18n_parent']];
                        } else if ((int)$gridElement['sys_language_uid'] > 0 && isset($gridElement['l10n_parent']) && (int)$gridElement['l10n_parent'] > 0) {
                            $txContainerParent = (int)$contentElementResults['parents'][$gridElement['l10n_parent']];
                        } else {
                            $txContainerParent = (int)$gridElement['tx_gridelements_container'];
                        }

                        $this->queryBuilder->update($this->table)
                            ->where($this->queryBuilder->expr()->eq('uid', $this->queryBuilder->createNamedParameter($gridElement['uid'])))
                            ->set('CType', $gridIdentifier)
                            ->execute();

                        $this->queryBuilder->update($this->table)
                            ->where($this->queryBuilder->expr()->eq('uid', $this->queryBuilder->createNamedParameter($gridElement['uid'])))
                            ->set('pi_flexform', $element['pi_flexform'])
                            ->execute();

                        $this->logger->info(
                            'Update Grids Elements '.$this->table.' whare UID='.$gridElement['uid'],
                            [
                                'uid' => $gridElement['uid'],
                                'pid' => $gridElement['pid'],
                                'colPos' => $colPos,
                                'backupColPos' => $gridElement['backupColPos'],
                                'CType' => $gridIdentifier,
                                'tx_gridelements_backend_layout' => $gridElement['tx_gridelements_backend_layout'],
                                'tx_gridelements_container' => $gridElement['tx_gridelements_container'],
                                'tx_gridelements_columns' => $gridElement['tx_gridelements_columns'],
                                'tx_gridelements_children' => $gridElement['tx_gridelements_children'],
                                'tx_container_parent' => $txContainerParent,
                                'l18n_parent' => $gridElement['l18n_parent'],
                                'sys_language_uid' => $gridElement['sys_language_uid'],
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
    public function fixColPosErrors(): bool
    {
        $this->logger->info('Start fixColPosErrors');

        $elements = $this->queryBuilder
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
                'sys_language_uid',
                'hidden',
                'deleted',
                'header',
            )
            ->from($this->table)
            ->where($this->queryBuilder->expr()->eq('colPos', $this->queryBuilder->createNamedParameter(-1)))
            ->orWhere($this->queryBuilder->expr()->eq('colPos', $this->queryBuilder->createNamedParameter(-2)))
            ->execute()
            ->fetchAllAssociative();

        foreach ($elements as $element) {
            $this->logger->info(
                'Fix ColPos - Select where colPos < 0',
                [
                    'uid' => $element['uid'],
                    'pid' => $element['pid'],
                    'colPos' => $element['colPos'],
                    'backupColPos' => $element['backupColPos'],
                    'CType' => $element['CType'],
                    'tx_gridelements_backend_layout' => $element['tx_gridelements_backend_layout'],
                    'tx_gridelements_container' => $element['tx_gridelements_container'],
                    'tx_gridelements_columns' => $element['tx_gridelements_columns'],
                    'tx_gridelements_children' => $element['tx_gridelements_children'],
                    'tx_container_parent' => $element['tx_container_parent'],
                    'l18n_parent' => $element['l18n_parent'],
                    'sys_language_uid' => $element['sys_language_uid'],
                ]
            );
        }

        $colPosMigrationConfig = [
            0 => 200,
            1 => 201,
            2 => 202,
            3 => 203,
        ];

        foreach ($colPosMigrationConfig as $oldColPosId => $newColPosId) {
            foreach ($elements as $element) {
                if ($element['tx_gridelements_columns'] === $oldColPosId) {
                    if ((int)$element['tx_gridelements_columns'] === $oldColPosId) {
                        $colPos = $newColPosId;
                    } else {
                        $colPos = 0;
                    }

                    if ((int)$element['sys_language_uid'] > 0 && $colPos === 0) {
                        $txContainerParent = 0;
                    } else if ((int)$element['sys_language_uid'] > 0 && isset($element['l18n_parent']) && (int)$element['l18n_parent'] > 0) {
                        $parent = $this->queryBuilder->select('tx_gridelements_container')
                            ->from($this->table)
                            ->where($this->queryBuilder->expr()->eq('uid', $this->queryBuilder->createNamedParameter($element['l18n_parent'])))
                            ->execute()
                            ->fetchFirstColumn();

                        if (isset($parent[0])) {
                            $txContainerParent = (int)$parent[0]['tx_gridelements_container'];
                        } else {
                            $txContainerParent = 0;
                        }
                    } else if ($colPos === 0) {
                        $txContainerParent = $element['tx_gridelements_container'];
                    } else if ($element['tx_gridelements_container'] > 0) {
                        $txContainerParent = $element['tx_gridelements_container'];
                    } else {
                        $txContainerParent = 0;
                    }

                    if ($txContainerParent === 0 && $colPos > 0) {
                        continue;
                    }

                    $this->queryBuilder->update($this->table)
                        ->where($this->queryBuilder->expr()->eq('uid', $this->queryBuilder->createNamedParameter($element['uid'])))
                        ->set('colPos', $colPos)
                        ->execute();

                    $this->queryBuilder->update($this->table)
                        ->where($this->queryBuilder->expr()->eq('uid', $this->queryBuilder->createNamedParameter($element['uid'])))
                        ->set('tx_container_parent', $txContainerParent)
                        ->execute();

                    $this->logger->info(
                        'Fix ColPos - Update Grids Contents '.$this->table.' whare UID='.$element['uid'],
                        [
                            'uid' => $element['uid'],
                            'pid' => $element['pid'],
                            'colPos' => $colPos,
                            'backupColPos' => $element['backupColPos'],
                            'CType' => $element['CType'],
                            'tx_gridelements_backend_layout' => $element['tx_gridelements_backend_layout'],
                            'tx_gridelements_container' => $element['tx_gridelements_container'],
                            'tx_gridelements_columns' => $element['tx_gridelements_columns'],
                            'tx_gridelements_children' => $element['tx_gridelements_children'],
                            'tx_container_parent' => $element['tx_container_parent'],
                            'l18n_parent' => $element['l18n_parent'],
                            'sys_language_uid' => $element['sys_language_uid'],
                        ]
                    );
                }
            }
        }

        $this->queryBuilder->delete($this->table)
            ->where($this->queryBuilder->expr()->eq('colPos', $this->queryBuilder->createNamedParameter(-1)))
            ->orWhere($this->queryBuilder->expr()->eq('colPos', $this->queryBuilder->createNamedParameter(-2)))
            ->execute();

        $this->logger->info('End fixColPosErrors');

        return true;
    }

    /**
     * @return bool
     * @throws DBALException
     * @throws Exception
     */
    public function logColPosErrors(): bool
    {
        $this->logger->info('Start logColPosErrors');
        $elements = $this->queryBuilder
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
            ->where($this->queryBuilder->expr()->eq('colPos', $this->queryBuilder->createNamedParameter(-1)))
            ->orWhere($this->queryBuilder->expr()->eq('colPos', $this->queryBuilder->createNamedParameter(-2)))
            ->execute()
            ->fetchAllAssociative();

        foreach ($elements as $element) {
            $this->logger->info('Error rows in tt_content: ', $element);
        }

        $this->logger->info('End logColPosErrors');
        return true;
    }
}
