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
     * @return true
     * @throws DBALException
     * @throws Exception
     */
    public function removeUnusedElements(): bool
    {
        $this->logger->info('Start - fixGridElements');

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
                    'deleted',
                )
                ->from($this->tableContent)
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($page['uid']))
                )
                ->execute()
                ->fetchAllAssociative();
        }

        $contentsToRemove = [];

        foreach ($pages as $page) {
            foreach ($page['contents'] as $content) {

                // check for broken grid element row
                $contentIsBroken = false;
                if (!empty($content['tx_gridelements_backend_layout']) && !str_contains($content['CType'], 'gridelements_pi')) {
                    $contentIsBroken = true;
                    $contentsToRemove[] = $content;
                }

                if ((int)$content['colPos'] === 0
                    && (int)$content['tx_gridelements_container'] === 0
                    && (int)$content['deleted'] === 0
                    && !$contentIsBroken) {
                    continue;
                }

                $parentElementKey = $this->searchElement($page['contents'], 'uid', $content['tx_gridelements_container']);

                if ($parentElementKey !== false && !$contentIsBroken) {
                    continue;
                }

                $contentsToRemove[] = $content;

                // check if content is grid element
                if (str_contains($content['CType'], 'gridelements_pi')) {

                    $childElementKeys = $this->searchElement($page['contents'], 'tx_gridelements_container', $content['uid']);

                    if ($childElementKeys === false && !$contentIsBroken) {
                        continue;
                    }

                    foreach ($childElementKeys as $childElementKey) {
                        $contentsToRemove[] = $page['contents'][$childElementKey];
                    }
                }
            }
        }

        foreach ($contentsToRemove as $contentToRemove) {
            $queryBuilder = $this->getQueryBuilder();
            $queryBuilder->delete($this->tableContent)
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($contentToRemove['uid'])))
                ->execute();

            $this->logData(
                'Content removed',
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
                'CType',
                'tx_container_parent',
                'tx_gridelements_columns',
                'tx_gridelements_container',
                'tx_gridelements_backend_layout',
                'tx_gridelements_children',
                'l18n_parent',
                'sys_language_uid',
                'deleted',
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
                'deleted',
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

        $contentElementsResult = [
            'contentList' => [],
            'parentsList' => [],
        ];

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
                    'deleted',
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
     * @return array[]
     */
    protected function getConfigs(): array
    {
        return [
            [
                'CType' => 'fullwidthcompontent',   // CType / grid element type / b13/container type
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
                'CType' => '1-2_1-2',
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
                'CType' => '1-4_3-4',
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
                'CType' => '1-4_1-4_1-2',
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
                'CType' => '1-2_1-4_1-4',
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
                'CType' => '1-1',
                'colPos' => [
                    [
                        'gridColPos' => 0,
                        'containerColPos' => 200,
                    ],
                ],
            ],
            [
                'CType' => '3-4_1-4',
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
                'CType' => '1-3_1-3_1-3',
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
                'CType' => '1-4_1-4_1-4_1-4',
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
                'CType' => '1-3_2-3',
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
            if ((string)$element[$column] === (string)$value && (int)$element['deleted'] === 0) {
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
