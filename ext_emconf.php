<?php

/***************************************************************
 * Extension Manager/Repository config file for ext: "gridtocontainer"
 *
 *
 * Manual updates:
 * Only the data in the array - anything else is removed by next write.
 * "version" and "dependencies" must not be touched!
 ***************************************************************/

$EM_CONF[$_EXTKEY] = [
	'title' => 'Migration Gridelements to Container',
	'description' => 'EXT:gridtocontainer is a small migration extension with backend module for those who want to switch from EXT:gridelements to EXT:container.',
	'category' => 'backend',
	'author' => 'Stefan Bublies',
	'author_email' => 'project@sbublies.de',
	'state' => 'beta',
	'uploadfolder' => 1,
	'createDirs' => '',
	'clearCacheOnLoad' => 0,
	'version' => '11.4.6',
	'constraints' => [
		'depends' => [
			'typo3' => '11.5.0-11.5.99',
		],
		'conflicts' => [],
		'suggests' => [],
	],
];
