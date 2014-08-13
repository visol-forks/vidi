<?php
namespace TYPO3\CMS\Vidi\Persistence;
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2013 Fabien Udriot <fabien.udriot@typo3.org>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Vidi\Module\ModuleLoader;
use TYPO3\CMS\Vidi\Tca\TcaService;

/**
 * Factory class related to Matcher object.
 */
class MatcherObjectFactory implements SingletonInterface {

	/**
	 * Gets a singleton instance of this class.
	 *
	 * @return \TYPO3\CMS\Vidi\Persistence\MatcherObjectFactory
	 */
	static public function getInstance() {
		return GeneralUtility::makeInstance('TYPO3\CMS\Vidi\Persistence\MatcherObjectFactory');
	}

	/**
	 * Returns a matcher object.
	 *
	 * @param array $matches
	 * @param string $dataType
	 * @return Matcher
	 */
	public function getMatcher($matches = array(), $dataType = '') {

		if (empty($dataType)) {
			$dataType = $this->getModuleLoader()->getDataType();
		}

		/** @var $matcher Matcher */
		$matcher = GeneralUtility::makeInstance('TYPO3\CMS\Vidi\Persistence\Matcher', array(), $dataType);

		$matcher = $this->applyCriteriaFromDataTables($matcher, $dataType);
		$matcher = $this->applyCriteriaFromMatchesArgument($matcher, $matches);
		$matcher = $this->applyCriteriaFromUrl($matcher);

		// Trigger signal for post processing Matcher Object.
		$this->emitPostProcessMatcherObjectSignal($matcher);

		return $matcher;
	}

	/**
	 * Apply criteria given by some parameter in the URL.
	 *
	 * @param Matcher $matcher
	 * @return Matcher $matcher
	 */
	protected function applyCriteriaFromUrl(Matcher $matcher) {
		if (GeneralUtility::_GP('id')) {
			$matcher->equals('pid', GeneralUtility::_GP('id'));
		}
		return $matcher;
	}

	/**
	 * Apply criteria specific to jQuery plugin Datatable.
	 *
	 * @param Matcher $matcher
	 * @param array $matches
	 * @return Matcher $matcher
	 */
	protected function applyCriteriaFromMatchesArgument(Matcher $matcher, $matches) {

		foreach ($matches as $propertyName => $value) {
			// CSV values should be considered as "in" operator in Query, otherwise "equals".
			$explodedValues = GeneralUtility::trimExplode(',', $value, TRUE);
			if (count($explodedValues) > 1) {
				$matcher->in($propertyName, $explodedValues);
			} else {
				$matcher->equals($propertyName, $explodedValues[0]);
			}
		}

		return $matcher;
	}

	/**
	 * Apply criteria specific to jQuery plugin DataTable.
	 *
	 * @param Matcher $matcher
	 * @param string $dataType
	 * @return Matcher $matcher
	 */
	protected function applyCriteriaFromDataTables(Matcher $matcher, $dataType) {

		// Special case for Grid in the BE using jQuery DataTables plugin.
		// Retrieve a possible search term from GP.
		$searchTerm = GeneralUtility::_GP('sSearch');

		if (strlen($searchTerm) > 0) {

			// Parse the json query coming from the Visual Search.
			$searchTerm = rawurldecode($searchTerm);
			$terms = json_decode($searchTerm, TRUE);

			if (is_array($terms)) {
				foreach ($terms as $term) {
					$fieldNameAndPath = key($term);

					$resolvedDataType = $this->getFieldPathResolver()->getDataType($fieldNameAndPath);
					$fieldName = $this->getFieldPathResolver()->stripFieldPath($fieldNameAndPath);

					// Only process if field really exists.
					if (TcaService::table($resolvedDataType)->hasField($fieldName)) {
						$value = current($term);
						if ($fieldNameAndPath === 'text') {
							$matcher->setSearchTerm($value);
						} elseif ($this->isOperatorEquals($fieldNameAndPath, $dataType, $value)) {
							$matcher->equals($fieldNameAndPath, $value);
						} else {
							$matcher->likes($fieldNameAndPath, $value);
						}
					}
				}
			} else {
				$matcher->setSearchTerm($searchTerm);
			}
		}
		return $matcher;
	}

	/**
	 * Tell whether the operator should be equals instead of like for a search, e.g. if the value is numerical.
	 *
	 * @param string $fieldName
	 * @param string $dataType
	 * @param string $value
	 * @return bool
	 */
	protected function isOperatorEquals($fieldName, $dataType, $value) {
		return (TcaService::table($dataType)->field($fieldName)->hasRelation() && MathUtility::canBeInterpretedAsInteger($value))
			|| TcaService::table($dataType)->field($fieldName)->isNumerical();
	}

	/**
	 * Signal that is called for post-processing a matcher object.
	 *
	 * @param Matcher $matcher
	 * @signal
	 */
	protected function emitPostProcessMatcherObjectSignal(Matcher $matcher) {

		if (strlen($matcher->getDataType()) <= 0) {

			/** @var ModuleLoader $moduleLoader */
			$moduleLoader = $this->getObjectManager()->get('TYPO3\CMS\Vidi\Module\ModuleLoader');
			$matcher->setDataType($moduleLoader->getDataType());
		}

		$this->getSignalSlotDispatcher()->dispatch('TYPO3\CMS\Vidi\Controller\Backend\ContentController', 'postProcessMatcherObject', array($matcher, $matcher->getDataType()));
	}

	/**
	 * Get the SignalSlot dispatcher
	 *
	 * @return \TYPO3\CMS\Extbase\SignalSlot\Dispatcher
	 */
	protected function getSignalSlotDispatcher() {
		return $this->getObjectManager()->get('TYPO3\\CMS\\Extbase\\SignalSlot\\Dispatcher');
	}

	/**
	 * @return \TYPO3\CMS\Extbase\Object\ObjectManager
	 */
	protected function getObjectManager() {
		return GeneralUtility::makeInstance('TYPO3\CMS\Extbase\Object\ObjectManager');
	}

	/**
	 * Get the Vidi Module Loader.
	 *
	 * @return \TYPO3\CMS\Vidi\Module\ModuleLoader
	 */
	protected function getModuleLoader() {
		return GeneralUtility::makeInstance('TYPO3\CMS\Vidi\Module\ModuleLoader');
	}

	/**
	 * @return \TYPO3\CMS\Vidi\Resolver\FieldPathResolver
	 */
	protected function getFieldPathResolver () {
		return GeneralUtility::makeInstance('TYPO3\CMS\Vidi\Resolver\FieldPathResolver');
	}

}