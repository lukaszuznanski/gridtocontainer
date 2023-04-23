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
* pole gridelements, przechowuje ID columny parent konfiguracji np. 0 jeżeli nie ma parent, 234221, 124123 ... 12312 jeżeli ma parent a wartość określa parent uid
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
    protected string $tableContent = 'tt_content';
    protected string $tablePage = 'pages';
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

        /*
        // fix colPos (set to -1)
        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder->update($this->tableContent)
            ->where($queryBuilder->expr()->gt('tx_gridelements_container', $queryBuilder->createNamedParameter(0)))
            ->set('colPos', -1)
            ->execute();
        */
        $configs = $this->getConfigs();
        $gridElements = $this->getGridsContainerElements($configs);
        $contentElements = $this->getGridsContainerContents($gridElements);
        $this->updateContentElements($configs, $contentElements);
        $this->updateGridElements($gridElements);
        $this->logData('End updateAllElements');
        return true;
    }

    /**
     * @param $configs
     * @return array
     * @throws DBALException
     * @throws Exception
     */
    protected function getGridsContainerElements($configs): array
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
            ->from($this->tableContent)
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

        $this->logData('End getGridsContainerElements');
        return $gridElements;
    }

    /**
     * @param $gridElements
     * @return array
     * @throws DBALException
     * @throws Exception
     */
    protected function getGridsContainerContents($gridElements): array
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
                ->from($this->tableContent)
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

        $this->logData('End getGridsContainerContents');
        return $contentElementsResult;
    }

    protected function updateGridElements($gridElements): void
    {
        foreach ($gridElements as $gridElement) {
            $queryBuilder = $this->getQueryBuilder();
            $queryBuilder->update($this->tableContent)
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($gridElement['uid'])))
                ->set('CType', $gridElement['tx_gridelements_backend_layout'])
                ->execute();

            $queryBuilder = $this->getQueryBuilder();
            $queryBuilder->update($this->tableContent)
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($gridElement['uid'])))
                ->set('pi_flexform', $gridElement['pi_flexform'])
                ->execute();

            $gridElement['CType'] = $gridElement['tx_gridelements_backend_layout'];

            $this->logData(
                'Update Grids Elements ' . $this->tableContent . ' whare UID=' . $gridElement['uid'],
                $gridElement
            );
        }
    }

    /**
     * @param $configs
     * @param $contentElements
     * @return void
     * @throws DBALException
     */
    protected function updateContentElements($configs, $contentElements): void
    {
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
                            } else if ((int)$contentElement['sys_language_uid'] > 0 && isset($contentElement['l18n_parent'])
                                && (int)$contentElement['l18n_parent'] > 0) {
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
                                    'Update Grids Contents ' . $this->tableContent . ' whare UID=' . $contentElement['uid'] . ' is NULL',
                                    $contentElement
                                );
                                continue;
                            }

                            $queryBuilder = $this->getQueryBuilder();
                            $queryBuilder->update($this->tableContent)
                                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($contentElement['uid'])))
                                ->set('colPos', $colPos)
                                ->execute();

                            $queryBuilder = $this->getQueryBuilder();
                            $queryBuilder->update($this->tableContent)
                                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($contentElement['uid'])))
                                ->set('tx_container_parent', $txContainerParent)
                                ->execute();

                            $contentElement['colPos'] = $colPos;
                            $contentElement['tx_container_parent'] = $txContainerParent;

                            $this->logData(
                                'Update Grids Contents ' . $this->tableContent . ' whare UID=' . $contentElement['uid'],
                                $contentElement
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * @return true
     * @throws DBALException
     * @throws Exception
     */
    public function removeUnusedElements(): bool
    {
        $this->logger->info('Start - fixGridElements');

        // get all pages
        $queryBuilder = $this->getQueryBuilder();
        $pages = $queryBuilder
            ->select(
                'uid'
            )
            ->from($this->tablePage)
            ->execute()
            ->fetchAllAssociative();

        foreach ($pages as $num => $page) {

            // get all content for page
            $queryBuilder = $this->getQueryBuilder();
            $pages[$num]['contents'] = $queryBuilder
                ->select(
                    'uid',
                    'pid',
                    'colPos',
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
                )
                ->from($this->tableContent)
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($page['uid']))
                )
                ->execute()
                ->fetchAllAssociative();
        }

        // $this->logData(print_r($pages, true));

        $contentsToRemove = [];

        // pages list
        foreach ($pages as $pageKey => $page) {

            // contents list
            // $pages[$pageKey]['uid'] => page id (int)
            // $pages[$pageKey]['contents'] => contents (array)
            foreach ($page['contents'] as $contentKey => $content) {

                // content
                // $pages[$pageKey]['contents'][$contentKey] => content (array)
                // $pages[$pageKey]['contents'][$contentKey]['uid'] => content id (int)
                // $pages[$pageKey]['contents'][$contentKey]['pid'] => page id (int)
                // $pages[$pageKey]['contents'][$contentKey]['colPos'] => page id (int)
                // ...

                // element is not grid element
                // check if element is in root position
                if ((int)$content['colPos'] === 0 && (int)$content['tx_gridelements_container'] === 0) {
                    // element is in root position
                    continue;
                }


                // check if element is translation
                if ((int)$content['sys_language_uid'] === 0) {
                    // element is not translation
                    // check if parent grid element exists
                    if ($this->searchElement($page['contents'], 'uid', $content['tx_gridelements_container']) !== false) {
                        // parent element exists
                        continue;
                    }
                } else {
                    // element is translation
                    // check if parent grid element exists
                    if ($this->searchElement($page['contents'], 'uid', $content['l18n_parent']) !== false) {
                        // parent element exists
                        continue;
                    }
                }

                // parent element not exists
                // add element to list for remove it
                $contentsToRemove[] = $content;

                // check if content is grid element
                if (str_contains($content['cType'], 'gridelements_pi')) {
                    // search children elements to remove it
                    $childElementKeys = $this->searchElement($page['contents'], 'tx_gridelements_container', $content['uid']);

                    // children element not found
                    if ($childElementKeys === false) {
                        continue;
                    }

                    foreach ($childElementKeys as $childElementKey) {
                        // add child element to list for remove it
                        $contentsToRemove[] = $page['contents'][$childElementKey];
                    }
                }
            }
        }

        foreach ($contentsToRemove as $contentToRemove) {
            $this->logData(
                'Content to remove',
                $contentToRemove
            );
        }

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
            ->from($this->tableContent)
            ->where(
                $queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter(-1))
            )
            ->orWhere(
                $queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter(-2))
            )
            ->orWhere(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(1))
            )
            ->execute()
            ->fetchAllAssociative();

        foreach ($elements as $element) {

            if ((int)$element['sys_language_uid'] > 0 && isset($element['l18n_parent']) && (int)$element['l18n_parent'] > 0) {
                $fieldName = 'l18n_parent';
            } else {
                $fieldName = 'tx_gridelements_container';
            }

            $queryBuilder = $this->getQueryBuilder();
            $children = $queryBuilder
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
                ->from($this->tableContent)
                ->where(
                    $queryBuilder->expr()->eq($fieldName, $queryBuilder->createNamedParameter($element['uid']))
                )
                ->execute()
                ->fetchAllAssociative();

            foreach ($children as $child) {
                $elements[] = $child;
            }
        }

        foreach ($elements as $element) {
            $queryBuilder = $this->getQueryBuilder();
            $queryBuilder->delete($this->tableContent)
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($element['uid'])))
                ->execute();

            $this->logData(
                'Remove colPos Errors id=' . $element['uid'],
                $element
            );
        }

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
            ->from($this->tableContent)
            ->where(
                $queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter(-1))
            )
            ->orWhere(
                $queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter(-2))
            )
            ->execute()
            ->fetchAllAssociative();

        foreach ($elements as $element) {
            $queryBuilder = $this->getQueryBuilder();
            $queryBuilder->delete($this->tableContent)
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($element['uid'])))
                ->execute();

            $this->logData(
                'Remove other colPos Errors id=' . $element['uid'],
                $element
            );
        }

        $this->logger->info('End - removeAllColPosErrors');
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
            ->from($this->tableContent)
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
     * @return array[]
     */
    protected function getConfigs(): array
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
     * @param $elements
     * @param $column
     * @param $value
     * @return array|false
     */
    protected function searchElement($elements, $column, $value)
    {
        $foundKeys = [];
        foreach($elements as $key => $element) {
            if (!empty($element[$column]) && (string)$element[$column] === (string)$value) {
                $foundKeys[] = $key;
            }
        }
        if (count($foundKeys) > 0) {
            return $foundKeys;
        }
        return false;
    }

    /**
     * @param $description
     * @param $data
     * @return void
     */
    protected function logData($description, $data = null): void
    {
        if ($data !== null) {
            $this->logger->info(
                $description,
                [
                    'uid' => $data['uid'],
                    'pid' => $data['pid'],
                    'colPos' => $data['colPos'],
                    'CType' => $data['CType'],
                    'tx_gridelements_backend_layout' => $data['tx_gridelements_backend_layout'],
                    'tx_gridelements_container' => $data['tx_gridelements_container'],
                    'tx_gridelements_columns' => $data['tx_gridelements_columns'],
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
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($this->tableContent);
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder;
    }
}
