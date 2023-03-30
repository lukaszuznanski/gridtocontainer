<?php
defined('TYPO3_MODE') || die('Access denied.');

call_user_func(
	function()
	{   // first commented out because not needed
		//\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile('gridtocontainer', 'Configuration/TypoScript', 'Migration gridelements to container');

		if (TYPO3_MODE === 'BE') {

			\TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerModule(
				'SBublies.Gridtocontainer',
				'tools',
				'migration',
				'',
				[
					\SBublies\Gridtocontainer\Controller\MigrationController::class => 'list,process,migrate,analyse,overview,migrategeneral,migrateprocess',
				],
				[
					'access' => 'user,group',
					'icon'   => 'EXT:gridtocontainer/Resources/Public/Icons/Migration.svg',
					'labels' => 'LLL:EXT:gridtocontainer/Resources/Private/Language/locallang_migration.xlf',
				]
			);

		}

		// Register as a skin
		$GLOBALS['TBE_STYLES']['skins']['gridtocontainer'] = [
			'name' => 'gridtocontainer',
			'stylesheetDirectories' => [
				'css' => 'EXT:gridtocontainer/Resources/Public/Backend/Stylesheets/'
			]
		];
	}
);
