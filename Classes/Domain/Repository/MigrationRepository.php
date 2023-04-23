<?php

namespace SBublies\Gridtocontainer\Domain\Repository;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver\Exception;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Extbase\Persistence\Repository;
use Psr\Log\LoggerAwareTrait;
use \TYPO3\CMS\Core\Log\LogManager;
use \TYPO3\CMS\Core\Core\Environment;
use \TYPO3\CMS\Core\Log\Writer\FileWriter;

/**
 * The repository for Migration
 *
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
* gridelements przechowuje konfigurację nazwy pole np. 1-1, 1-2_1-2, 1-3_1-3_1-3*
 * b13/container nie korzysta z tegp pola
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
class MigrationRepository extends Repository
{
    protected string $table = 'tt_content';
    use LoggerAwareTrait;

    public function initializeObject(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'] = [
            \TYPO3\CMS\Core\Log\LogLevel::INFO => [
                FileWriter::class => [
                    'logFile' => Environment::getVarPath() . '/log/migrate-grid-to-container.typo3-package.log'
                ]
            ]
        ];

        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }

    /**
     * @return bool
     * @throws DBALException
     * @throws Exception
     */
    public function updateAllElements(): bool
    {
        $this->logData('Start updateAllElements');

        $configs = $this->getConfigs();
        $gridElements = $this->getGridsContainerElements($configs);
        $contentElements = $this->getGridsContainerContents($gridElements);

        /**
        ['contentList'] => Array
            // key = grid element uid
            [98543] => Array
                [
                    // key = colPos int
                    [1] => Array
                        // contents list from grid element
                        [
                            // content from grid element
                            [0] => Array
                                [
                                    [uid] => 98541
                                    [pid] => 12895
         */

        // update content elements
        foreach ($configs as $config) {
            foreach ($config['colPos'] as $configColPos) {
                foreach ($contentElements['contentList'] as $gridElementUid => $colPosList) {
                    foreach ($colPosList as $contentElementKey => $contentElement) {
                        if ((int)$contentElement['tx_gridelements_columns'] === (int)$configColPos['gridColPos']) {

                            if (isset($contentElements['contentList'][$gridElementUid][$contentElementKey]['done'])
                                && $contentElements['contentList'][$gridElementUid][$contentElementKey]['done'] === true) {
                                continue;
                            }

                            $contentElements['contentList'][$gridElementUid][$contentElementKey]['done'] = true;

                            if ((int)$contentElement['colPos'] === 0) {
                                $colPos = 0;
                            } else if ((int)$contentElement['tx_gridelements_columns'] === $configColPos['gridColPos']) {
                                $colPos = $configColPos['containerColPos'];
                            } else {
                                $colPos = 0;
                            }

                            if ((int)$contentElement['sys_language_uid'] > 0 && $colPos === 0) {
                                $txContainerParent = 0;
                            } else if ((int)$contentElement['sys_language_uid'] > 0 && isset($contentElement['l18n_parent']) && (int)$contentElement['l18n_parent'] > 0) {
                                $txContainerParent = $contentElements['parentsList'][$contentElement['l18n_parent']];
                            } else if ($colPos === 0) {
                                $txContainerParent = $contentElement['tx_gridelements_container'];
                            } else {
                                $txContainerParent = $gridElementUid;
                            }

                            if ($txContainerParent === 0 && $colPos > 0) {
                                continue;
                            }

                            if ($txContainerParent === null) {
                                $this->logData(
                                    'Update Grids Contents ' . $this->table . ' whare UID=' . $contentElement['uid'] . ' is NULL',
                                    $contentElement
                                );
                                continue;
                            }

                            $queryBuilder = $this->getQueryBuilder();
                            $queryBuilder->update($this->table)
                                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($contentElement['uid'])))
                                ->set('colPos', $colPos)
                                ->execute();

                            $queryBuilder = $this->getQueryBuilder();
                            $queryBuilder->update($this->table)
                                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($contentElement['uid'])))
                                ->set('tx_container_parent', $txContainerParent)
                                ->execute();

                            $contentElement['colPos'] = $colPos;
                            $contentElement['tx_container_parent'] = $txContainerParent;

                            $this->logData(
                                'Update Grids Contents ' . $this->table . ' whare UID=' . $contentElement['uid'],
                                $contentElement
                            );
                        }
                    }
                }
            }
        }

        // update grids elements
        foreach ($gridElements as $gridElement) {

            $queryBuilder = $this->getQueryBuilder();
            $queryBuilder->update($this->table)
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($gridElement['uid'])))
                ->set('CType', $gridElement['tx_gridelements_backend_layout'])
                ->execute();

            $queryBuilder = $this->getQueryBuilder();
            $queryBuilder->update($this->table)
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($gridElement['uid'])))
                ->set('pi_flexform', $gridElement['pi_flexform'])
                ->execute();

            $gridElement['CType'] = $gridElement['tx_gridelements_backend_layout'];

            $this->logData(
                'Update Grids Elements ' . $this->table . ' whare UID=' . $gridElement['uid'],
                $gridElement
            );
        }

        $this->logData('End updateAllElements');

        return true;
    }

    /**
     * @param $configs
     * @return array
     * @throws DBALException
     * @throws Exception
     */
    public function getGridsContainerElements($configs): array
    {
        $this->logData('Start getGridsContainerElements');

        $queryBuilder = $this->getQueryBuilder();
        $gridElements = $queryBuilder
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
                $queryBuilder->expr()->like('CType', '"%gridelements_pi%"')
            )
            ->execute()
            ->fetchAllAssociative();

        foreach ($gridElements as $gridElement) {
            $this->logData(
                'Select where CType=gridelements_pi',
                $gridElement
            );
        }

        //$this->logData(print_r($gridElements, true));

        /**
         [
            [0] => Array
                [
                    [uid] => 98535
                    [pid] => 12233
                    [colPos] => 0
                    [CType] => gridelements_pi1
                    [tx_gridelements_backend_layout] => fullwidthcompontent
                    [tx_gridelements_container] => 0
                    [tx_gridelements_columns] => 0
                    [tx_gridelements_children] => 1
                    [tx_container_parent] => 0
                    [l18n_parent] => 0
                    [pi_flexform] => 'string'
                    [sys_language_uid] => 0
                ]

            [1] => Array
                [
                    [uid] => 98543
                    [pid] => 12895
                    [colPos] => 0
                    [backupColPos] => -2
                    [CType] => gridelements_pi1
                    [tx_gridelements_backend_layout] => 1-1
                    [tx_gridelements_container] => 0
                    [tx_gridelements_columns] => 0
                    [tx_gridelements_children] => 2
                    [tx_container_parent] => 0
                    [l18n_parent] => 89961
                    [pi_flexform] => 'string'
                    [sys_language_uid] => 17
                ]
         ]
        **/

        $this->logData('End getGridsContainerElements');

        return $gridElements;
    }

    /**
     * @param $gridElements
     * @return array
     * @throws DBALException
     * @throws Exception
     */
    public function getGridsContainerContents($gridElements): array
    {
        $this->logData('Start getGridsContainerContents');

        $contentElementsResult = [];
        foreach ($gridElements as $gridElement) {
            $queryBuilder = $this->getQueryBuilder();
            $childrenElements = $queryBuilder
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
                    $queryBuilder->expr()->eq('tx_gridelements_container', $gridElement['uid'])
                )
                ->orWhere(
                    $queryBuilder->expr()->eq('l18n_parent', $gridElement['uid'])
                )
                ->execute()
                ->fetchAllAssociative();

            if (empty($childrenElements)) {
                continue;
            }

            foreach ($childrenElements as $childrenElement) {
                if (empty($childrenElement)) {
                    continue;
                }
                $contentElementsResult['contentList'][$gridElement['uid']][] = $childrenElement;
                $contentElementsResult['parentsList'][$childrenElement['uid']] = $childrenElement['tx_gridelements_container'];
            }
        }

        foreach ($contentElementsResult['contentList'] as $gridElementUid => $childrenElements) {
            foreach ($childrenElements as $childrenElement) {
                $this->logData(
                    'Select where tx_gridelements_container=' . $childrenElement['uid'] . ' OR l18n_parent=' . $gridElementUid,
                    $childrenElement
                );
            }
        }

        //$this->logData(print_r($contentElementsResult, true));

        /**
        ['parentsList'] => Array
            [
                // 'content uid' => parent element uid
                '98543' => 12132 (parent element uid)
                '98544' => 12132 (parent element uid)
                '98545' => 12132 (parent element uid)
            ]
        ['contentList'] => Array
            // key = grid element uid
            [98543] => Array
                [
                    // key = colPos int
                    [1] => Array
                        // contents list from grid element
                        [
                            // content from grid element
                            [0] => Array
                                [
                                    [uid] => 98541
                                    [pid] => 12895
                                    [colPos] => -1
                                    [backupColPos] => -2
                                    [CType] => gridelements_pi1
                                    [tx_gridelements_backend_layout] => 1-2_1-2
                                    [tx_gridelements_container] => 98543
                                    [tx_gridelements_columns] => 1
                                    [tx_gridelements_children] => 2
                                    [tx_container_parent] => 0
                                    [l18n_parent] => 89959
                                    [hidden] => 0
                                    [deleted] => 0
                                    [header] =>
                                    [pi_flexform] =>
                                    [sys_language_uid] => 17
                                ]
                            // content from grid element
                            [1] => Array
                                [
                                    [uid] => 98542
                                    [pid] => 12895
                                    [colPos] => -1
                                    [backupColPos] => -2
                                    [CType] => header
                                    [tx_gridelements_backend_layout] => 0
                                    [tx_gridelements_container] => 98543
                                    [tx_gridelements_columns] => 0
                                    [tx_gridelements_children] => 0
                                    [tx_container_parent] => 0
                                    [l18n_parent] => 89960
                                    [hidden] => 0
                                    [deleted] => 0
                                    [header] => LEARNING POWERED BY NAVIO
                                    [pi_flexform] =>
                                    [sys_language_uid] => 17
                                ]
                        ]
                    // key = colPos int
                    [0] => Array
                        [
                            // content from grid element
                            [0] => Array
                                [
                                    [uid] => 98541
                                    [pid] => 12895
                                    [colPos] => -1
                                    [backupColPos] => -2
                                    [CType] => gridelements_pi1
                                    [tx_gridelements_backend_layout] => 1-2_1-2
                                    [tx_gridelements_container] => 98543
                                    [tx_gridelements_columns] => 1
                                    [tx_gridelements_children] => 2
                                    [tx_container_parent] => 0
                                    [l18n_parent] => 89959
                                    [hidden] => 0
                                    [deleted] => 0
                                    [header] =>
                                    [pi_flexform] =>
                                    [sys_language_uid] => 17
                                ]
                            // content from grid element
                            [1] => Array
                                [
                                    [uid] => 98542
                                    [pid] => 12895
                                    [colPos] => -1
                                    [backupColPos] => -2
                                    [CType] => header
                                    [tx_gridelements_backend_layout] => 0
                                    [tx_gridelements_container] => 98543
                                    [tx_gridelements_columns] => 0
                                    [tx_gridelements_children] => 0
                                    [tx_container_parent] => 0
                                    [l18n_parent] => 89960
                                    [hidden] => 0
                                    [deleted] => 0
                                    [header] => LEARNING POWERED BY NAVIO
                                    [pi_flexform] =>
                                    [sys_language_uid] => 17
                                ]
                        ]
                ]
        */

        $this->logData('End getGridsContainerContents');

        return $contentElementsResult;
    }

    /**
     * @return array[]
     */
    public function getConfigs(): array
    {
        return [
            [
                'cType' => 'fullwidthcompontent',   // cType / grid element type / b13/container type
                'colPos' => [
                    [
                        'gridColPos' => 0,         // grid element colPos
                        'containerColPos' => 200,  // b13/container colPos
                    ],
                    [
                        'gridColPos' => 1,         // grid element colPos
                        'containerColPos' => 201,  // b13/container colPos
                    ],
                ]
            ],
            [
                'cType' => '1-2_1-2',
                'colPos' => [
                    [
                        'gridColPos' => 0,
                        'containerColPos' => 200,
                    ],
                    [
                        'gridColPos' => 1,
                        'containerColPos' => 201,
                    ],
                ],
            ],
            [
                'cType' => '1-4_3-4',
                'colPos' => [
                    [
                        'gridColPos' => 0,
                        'containerColPos' => 200,
                    ],
                    [
                        'gridColPos' => 1,
                        'containerColPos' => 201,
                    ],
                ],
            ],
            [
                'cType' => '1-4_1-4_1-2',
                'colPos' => [
                    [
                        'gridColPos' => 0,
                        'containerColPos' => 200,
                    ],
                    [
                        'gridColPos' => 1,
                        'containerColPos' => 201,
                    ],
                    [
                        'gridColPos' => 2,
                        'containerColPos' => 202,
                    ],
                ],
            ],
            [
                'cType' => '1-2_1-4_1-4',
                'colPos' => [
                    [
                        'gridColPos' => 0,
                        'containerColPos' => 200,
                    ],
                    [
                        'gridColPos' => 1,
                        'containerColPos' => 201,
                    ],
                    [
                        'gridColPos' => 2,
                        'containerColPos' => 202,
                    ],
                ],
            ],
            [
                'cType' => '1-1',
                'colPos' => [
                    [
                        'gridColPos' => 0,
                        'containerColPos' => 200,
                    ],
                ],
            ],
            [
                'cType' => '3-4_1-4',
                'colPos' => [
                    [
                        'gridColPos' => 0,
                        'containerColPos' => 200,
                    ],
                    [
                        'gridColPos' => 1,
                        'containerColPos' => 201,
                    ],
                ],
            ],
            [
                'cType' => '1-3_1-3_1-3',
                'colPos' => [
                    [
                        'gridColPos' => 0,
                        'containerColPos' => 200,
                    ],
                    [
                        'gridColPos' => 1,
                        'containerColPos' => 201,
                    ],
                    [
                        'gridColPos' => 2,
                        'containerColPos' => 202,
                    ],
                ],
            ],
            [
                'cType' => '1-4_1-4_1-4_1-4',
                'colPos' => [
                    [
                        'gridColPos' => 0,
                        'containerColPos' => 200,
                    ],
                    [
                        'gridColPos' => 1,
                        'containerColPos' => 201,
                    ],
                    [
                        'gridColPos' => 2,
                        'containerColPos' => 202,
                    ],
                    [
                        'gridColPos' => 3,
                        'containerColPos' => 203,
                    ],
                ],
            ],
            [
                'cType' => '1-3_2-3',
                'colPos' => [
                    [
                        'gridColPos' => 0,
                        'containerColPos' => 200,
                    ],
                    [
                        'gridColPos' => 1,
                        'containerColPos' => 201,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return bool
     * @throws DBALException
     * @throws Exception
     */
    public function fixColPosErrors(): bool
    {
        $this->logger->info('Start fixColPosErrors');
        $this->logColPosErrors();
        //$this->removeColPosErrorsRows();
        //$this->logColPosErrors();
        $this->removeAllColPosErrors();
        $this->logColPosErrors();
        $this->logger->info('End fixColPosErrors');
        return true;
    }

    /**
     * @return bool
     * @throws DBALException
     * @throws Exception
     */
    public function removeAllColPosErrors(): bool
    {
        $this->logger->info('Start - removeAllColPosErrors');

        $queryBuilder = $this->getQueryBuilder();
        $gridElements = $queryBuilder
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
            ->where(
                $queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter(-1)),
                $queryBuilder->expr()->like('CType', '"%gridelements_pi%"')
            )
            ->orWhere(
                $queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter(-2)),
                $queryBuilder->expr()->like('CType', '"%gridelements_pi%"'))
            ->orWhere(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(1)),
                $queryBuilder->expr()->like('CType', '"%gridelements_pi%"')
            )
            ->execute()
            ->fetchAllAssociative();

        $children = [];
        foreach ($gridElements as $gridElement) {
            if ((int)$gridElement['sys_language_uid'] > 0 && isset($gridElement['l18n_parent']) && (int)$gridElement['l18n_parent'] > 0) {
                $queryBuilder = $this->getQueryBuilder();
                $children[$gridElement['uid']] = $queryBuilder
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
                    ->where(
                        $queryBuilder->expr()->eq('l18n_parent', $queryBuilder->createNamedParameter($gridElement['uid'])),
                        $queryBuilder->expr()->gt('sys_language_uid', $queryBuilder->createNamedParameter(0))
                    )
                    ->execute()
                    ->fetchAllAssociative();
            } else {
                $queryBuilder = $this->getQueryBuilder();
                $children[$gridElement['uid']] = $queryBuilder
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
                    ->where(
                        $queryBuilder->expr()->eq('tx_gridelements_columns', $queryBuilder->createNamedParameter($gridElement['uid'])),
                        $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0))
                    )
                    ->execute()
                    ->fetchAllAssociative();
            }


        }

        foreach ($gridElements as $gridElement) {
            $queryBuilder = $this->getQueryBuilder();
            $queryBuilder->delete($this->table)
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($gridElement['uid'])))
                ->execute();

            $this->logData(
                'Remove colPos Errors id=' . $gridElement['uid'],
                $gridElement
            );

            foreach ($children[$gridElement['uid']] as $child) {
                $queryBuilder = $this->getQueryBuilder();
                $queryBuilder->delete($this->table)
                    ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($child['uid'])))
                    ->execute();

                $this->logData(
                    'Remove child colPos Errors id=' . $child['uid'],
                    $child
                );
            }
        }

        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder->delete($this->table)
            ->where($queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter(-1)))
            ->orWhere($queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter(-2)))
            ->execute();

        $this->logger->info('End - removeAllColPosErrors');
        return true;
    }

    /**
     * TODO This method must be checked
     * @return bool
     * @throws DBALException
     * @throws Exception
     */
    public function removeColPosErrorsRows(): bool
    {
        $queryBuilder = $this->getQueryBuilder();
        $elements = $queryBuilder
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
            ->where($queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter(-1)))
            ->orWhere($queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter(-2)))
            ->execute()
            ->fetchAllAssociative();

        $children = [];
        foreach ($elements as $element) {
            $queryBuilder = $this->getQueryBuilder();
            $elementsChild = $queryBuilder
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
                ->where($queryBuilder->expr()->eq('tx_gridelements_container', $queryBuilder->createNamedParameter($element['uid'])))
                ->orWhere($queryBuilder->expr()->eq('l18n_parent', $queryBuilder->createNamedParameter($element['uid'])))
                ->execute()
                ->fetchAllAssociative();

            $children[$element['uid']] = $elementsChild;
        }

        foreach ($elements as $element) {
            $queryBuilder = $this->getQueryBuilder();
            $queryBuilder->delete($this->table)
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($element['uid'])))
                ->execute();

            foreach ($children[$element['uid']] as $child) {
                $queryBuilder = $this->getQueryBuilder();
                $queryBuilder->delete($this->table)
                    ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($child['uid'])))
                    ->execute();
            }
        }
        return true;
    }

    /**
     * @return bool
     * @throws DBALException
     * @throws Exception
     */
    public function updateColPosErrors(): bool
    {
        $queryBuilder = $this->getQueryBuilder();
        $elements = $queryBuilder
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
            ->where($queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter(-1)))
            ->orWhere($queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter(-2)))
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
            foreach ($elements as $elementKey => $element) {
                if ($element['tx_gridelements_columns'] === $oldColPosId) {
                    if ((int)$elements[$elementKey]['colPos'] === 0) {
                        $colPos = 0;
                    } else if ((int)$elements[$elementKey]['tx_gridelements_columns'] === $oldColPosId) {
                        $colPos = $newColPosId;
                    } else {
                        $colPos = 0;
                    }

                    if ((int)$element['sys_language_uid'] > 0 && $colPos === 0) {
                        $txContainerParent = 0;
                    } else if ((int)$element['sys_language_uid'] > 0 && isset($element['l18n_parent']) && (int)$element['l18n_parent'] > 0) {
                        $queryBuilder = $this->getQueryBuilder();
                        $parent = $queryBuilder
                            ->select('tx_gridelements_container')
                            ->from($this->table)
                            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($element['l18n_parent'])))
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

                    $queryBuilder = $this->getQueryBuilder();
                    $queryBuilder->update($this->table)
                        ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($element['uid'])))
                        ->set('colPos', $colPos)
                        ->execute();

                    $queryBuilder->update($this->table)
                        ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($element['uid'])))
                        ->set('tx_container_parent', $txContainerParent)
                        ->execute();

                    $this->logger->info(
                        'Fix ColPos - Update Grids Contents ' . $this->table . ' whare UID=' . $element['uid'],
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
                            'sys_language_uid' => $element['sys_language_uid']
                        ]
                    );
                }
            }
        }
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

        $queryBuilder = $this->getQueryBuilder();
        $elements = $queryBuilder
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
            ->where($queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter(-1)))
            ->orWhere($queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter(-2)))
            ->execute()
            ->fetchAllAssociative();

        if (count($elements) > 0) {
            foreach ($elements as $element) {
                $this->logData('Error data', $element);
            }
        } else {
            $this->logger->info('No rows found with invalid colPos value');
        }

        $this->logger->info('End logColPosErrors');
        return true;
    }

    /**
     * @param $description
     * @param $data
     * @return void
     */
    public function logData($description, $data = null): void
    {
        if ($data !== null) {
            $this->logger->info(
                $description,
                [
                    'uid' => $data['uid'],
                    'pid' => $data['pid'],
                    'colPos' => $data['colPos'],
                    'backupColPos' => $data['backupColPos'],
                    'CType' => $data['CType'],
                    'tx_gridelements_backend_layout' => $data['tx_gridelements_backend_layout'],
                    'tx_gridelements_container' => $data['tx_gridelements_container'],
                    'tx_gridelements_columns' => $data['tx_gridelements_columns'],
                    'tx_gridelements_children' => $data['tx_gridelements_children'],
                    'tx_container_parent' => $data['tx_container_parent'],
                    'l18n_parent' => $data['l18n_parent'],
                    'sys_language_uid' => $data['sys_language_uid'],
                ]
            );
        } else {
            $this->logger->info(
                $description
            );
        }
    }

    /**
     * @return QueryBuilder
     */
    protected function getQueryBuilder(): QueryBuilder
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder;
    }
}
