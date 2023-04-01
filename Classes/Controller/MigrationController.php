<?php

namespace SBublies\Gridtocontainer\Controller;

use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\DebugUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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
class MigrationController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController
{

    /**
     * migrationRepository
     *
     * @var \SBublies\Gridtocontainer\Domain\Repository\MigrationRepository
     *
     */
    protected $migrationRepository = null;

    /**
     * @param \SBublies\Gridtocontainer\Domain\Repository\MigrationRepository|null $migrationRepository
     */
    public function __construct(?\SBublies\Gridtocontainer\Domain\Repository\MigrationRepository $migrationRepository)
    {
        $this->migrationRepository = $migrationRepository;
    }

    /**
     * action list
     *
     * @return void
     */
    public function listAction()
    {
            $gridelementsElements = $this->migrationRepository->findGridelements();
            $this->view->assign('gridelementsElements', $gridelementsElements);
    }

    /**
     * action process
     *
     * @return void
     */
    public function processAction()
    {
        // Form Data
        $arguments = $this->request->getArguments();
        $elementIds = $arguments['migration']['elements'];
        $contentElements = [];
        $gridelementsElements = [];
        foreach($elementIds as $id){
            if(empty($id)){
                continue;
            } else {
                $contentElements[$id] = $this->migrationRepository->findContentfromGridElements($id);
                $gridelementsElements[$id] = $this->migrationRepository->findById($id);
            }
        }
        // Flexform value from database or tca definition
        $flexFormValuePathsFromTca = $GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config']['ds'];
        $flexFormValuesArray = [];
        foreach ($flexFormValuePathsFromTca as $key => $flexFormValue) {
            if (substr_compare('FILE:',$flexFormValue,0,5) OR $flexFormValue == '') {
                $flexFormValuesArray[substr($key,2)] = $flexFormValue;
            } else {
                $flexFormValuesArray[substr($key,2)] = file_get_contents(\TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName(substr($flexFormValue,5)));
            }
        }
        $this->view->assignMultiple(
            array(
                "gridElements" => $gridelementsElements,
                "contentElements" => $contentElements,
                "flexFormValues" => $flexFormValuesArray,
                "arguments" => $arguments
            )
        );
    }

    /**
     * action migrategeneral
     *
     * @return void
     */
    public function migrategeneralAction()
    {
        $gridelementsElements = $this->migrationRepository->findGridelementsCustom();
        $gridElementsArray=[];
        $layoutColumns = [];
        foreach ($gridelementsElements as $gridElement) {
            $columnElement = $this->migrationRepository->findContentfromGridElements($gridElement['uid']);
            if($columnElement) {
                $columnElementFlip = array_fill_keys(array_column($columnElement, 'tx_gridelements_columns'), '1');
                if(!isset($layoutColumns[$gridElement['tx_gridelements_backend_layout']])) $layoutColumns[$gridElement['tx_gridelements_backend_layout']] = [];
                if(array_diff_assoc($columnElementFlip, $layoutColumns[$gridElement['tx_gridelements_backend_layout']])) {
                    $gridElementsArray[$gridElement['tx_gridelements_backend_layout']] = $gridElement;
                    $layoutColumns[$gridElement['tx_gridelements_backend_layout']] += $columnElementFlip;
                }
            }
        }
        // Flexform value from database or tca definition
        $flexFormValuePathsFromTca = $GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config']['ds'];
        $flexFormValuesArray = [];
        foreach ($flexFormValuePathsFromTca as $key => $flexFormValue) {
            if (substr_compare('FILE:',$flexFormValue,0,5) OR $flexFormValue == '') {
                $flexFormValuesArray[substr($key,2)] = $flexFormValue;
            } else {
                $flexFormValuesArray[substr($key,2)] = file_get_contents(\TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName(substr($flexFormValue,5)));
            }
        }
        $this->view->assignMultiple(
            array(
                "gridelementsElements" => $gridElementsArray,
                "flexFormValues" => $flexFormValuesArray,
                "layoutColumns" => $layoutColumns,
            )
        );
    }

    /**
     * action migrateprocess
     *
     * @return void
     */
    public function migrateprocessAction()
    {
        // Form Data
        $arguments = $this->request->getArguments();
        $migrateAllElements = $this->migrationRepository->updateAllElements($arguments['migrategeneral']['elements']);
        $this->view->assignMultiple(
            array(
                "arguments" => $arguments,
                "migrateAllElements" => $migrateAllElements
            )
        );

    }

    /**
     * action migrate
     *
     * @return void
     */
    public function migrateAction()
    {
        // Form Data
        $arguments = $this->request->getArguments();

        $migrateContainerElements = $this->migrationRepository->updateGridElements($arguments['migration']['elements']);
        $migrateContentElements = $this->migrationRepository->updateContentElements($arguments['migration']['contentElements']);

        $this->view->assignMultiple(
            array(
                "arguments" => $arguments,
                "ContainerElementResult" => $migrateContainerElements,
                "ContentElementResult" => $migrateContentElements
            )
        );
    }

    /**
     * action analyse
     *
     * @return void
     */
    public function analyseAction()
    {
        $gridelementsElements = $this->migrationRepository->findGridelementsCustom();
        $gridElementsArray=[];
        foreach ($gridelementsElements as $element) {
            $gridElementsArray[$element['tx_gridelements_backend_layout']] = $element;
        }
        $this->view->assignMultiple(
            array(
                "gridElements" => $gridElementsArray
            )
        );
    }

    /**
     * action overview
     *
     * @return void
     */
    public function overviewAction()
    {
        //Overview
    }
}
