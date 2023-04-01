<?php

namespace SBublies\Gridtocontainer\Controller;

use Doctrine\DBAL\DBALException;
use SBublies\Gridtocontainer\Domain\Repository\MigrationRepository;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\DebugUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/***
 * This file is part of the "Gridtocontainer" Extension for TYPO3 CMS.
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *  (c) 2021 Stefan Bublies <project@sbublies.de>
 *  (c) 2022 Update by Stefan Bublies <project@sbublies.de>
 ***/


/**
 * MigrationController
 */
class MigrationController extends ActionController
{
    /**
     * migrationRepository
     *
     * @var MigrationRepository|null
     *
     */
    protected ?MigrationRepository $migrationRepository = null;

    /**
     * @param MigrationRepository|null $migrationRepository
     */
    public function __construct(?MigrationRepository $migrationRepository)
    {
        $this->migrationRepository = $migrationRepository;
    }

    /**
     * action list
     *
     * @return void
     */
    public function listAction(): void
    {
        $gridelementsElements = $this->migrationRepository->findGridelements();
        $this->view->assign('gridelementsElements', $gridelementsElements);
    }

    /**
     * action process
     *
     * @return void
     */
    public function processAction(): void
    {
        // Form Data
        $arguments = $this->request->getArguments();
        $elementIds = $arguments['migration']['elements'];
        $contentElements = [];
        $gridelementsElements = [];
        foreach ($elementIds as $id) {
            if (empty($id)) {
                continue;
            }

            $contentElements[$id] = $this->migrationRepository->findContentfromGridElements($id);
            $gridelementsElements[$id] = $this->migrationRepository->findById($id);
        }
        // Flexform value from database or tca definition
        $flexFormValuesArray = $this->getFlexFormValuesArray();
        $this->view->assignMultiple(
            [
                'gridElements' => $gridelementsElements,
                'contentElements' => $contentElements,
                'flexFormValues' => $flexFormValuesArray,
                'arguments' => $arguments
            ]
        );
    }

    /**
     * action migrategeneral
     *
     * @return void
     */
    public function migrategeneralAction(): void
    {
        $gridelementsElements = $this->migrationRepository->findGridelementsCustom();
        $gridElementsArray = [];
        $layoutColumns = [];
        foreach ($gridelementsElements as $gridElement) {
            $columnElement = $this->migrationRepository->findContentfromGridElements($gridElement['uid']);
            if ($columnElement) {
                $columnElementFlip = array_fill_keys(array_column($columnElement, 'tx_gridelements_columns'), '1');
                if (!isset($layoutColumns[$gridElement['tx_gridelements_backend_layout']])) {
                    $layoutColumns[$gridElement['tx_gridelements_backend_layout']] = [];
                }
                if (array_diff_assoc($columnElementFlip, $layoutColumns[$gridElement['tx_gridelements_backend_layout']])) {
                    $gridElementsArray[$gridElement['tx_gridelements_backend_layout']] = $gridElement;
                    $layoutColumns[$gridElement['tx_gridelements_backend_layout']] += $columnElementFlip;
                }
            }
        }
        // Flexform value from database or tca definition
        $flexFormValuesArray = $this->getFlexFormValuesArray();
        $this->view->assignMultiple(
            [
                'gridelementsElements' => $gridElementsArray,
                'flexFormValues' => $flexFormValuesArray,
                'layoutColumns' => $layoutColumns,
            ]
        );
    }

    /**
     * action migrateprocess
     *
     * @return void
     * @throws DBALException
     */
    public function migrateprocessAction(): void
    {
        // Form Data
        $arguments = $this->request->getArguments();
        $migrateAllElements = $this->migrationRepository->updateAllElements($arguments['migrategeneral']['elements']);
        $this->view->assignMultiple(
            [
                'arguments' => $arguments,
                'migrateAllElements' => $migrateAllElements
            ]
        );
    }

    /**
     * action migrate
     *
     * @return void
     */
    public function migrateAction(): void
    {
        // Form Data
        $arguments = $this->request->getArguments();

        $migrateContainerElements = $this->migrationRepository->updateGridElements(
            $arguments['migration']['elements']
        );
        $migrateContentElements = $this->migrationRepository->updateContentElements(
            $arguments['migration']['contentElements']
        );

        $this->view->assignMultiple(
            [
                'arguments' => $arguments,
                'ContainerElementResult' => $migrateContainerElements,
                'ContentElementResult' => $migrateContentElements
            ]
        );
    }

    /**
     * action analyse
     *
     * @return void
     * @throws DBALException
     */
    public function analyseAction(): void
    {
        $gridelementsElements = $this->migrationRepository->findGridelementsCustom();
        $gridElementsArray = [];
        foreach ($gridelementsElements as $element) {
            $gridElementsArray[$element['tx_gridelements_backend_layout']] = $element;
        }
        $this->view->assignMultiple(
            [
                'gridElements' => $gridElementsArray
            ]
        );
    }

    /**
     * action overview
     *
     * @return void
     */
    public function overviewAction(): void
    {
        //Overview
    }

    /**
     * @return array
     */
    public function getFlexFormValuesArray(): array
    {
        $flexFormValuePathsFromTca = $GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config']['ds'];
        $flexFormValuesArray = [];
        foreach ($flexFormValuePathsFromTca as $key => $flexFormValue) {
            if (substr_compare('FILE:', $flexFormValue, 0, 5) || $flexFormValue == '') {
                $flexFormValuesArray[substr($key, 2)] = $flexFormValue;
            } else {
                $flexFormValuesArray[substr($key, 2)] = file_get_contents(
                    \TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName(substr($flexFormValue, 5))
                );
            }
        }
        return $flexFormValuesArray;
    }
}
