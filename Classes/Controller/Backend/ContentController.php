<?php
namespace TYPO3\CMS\Vidi\Controller\Backend;

/**
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Vidi\Behavior\SavingBehavior;
use TYPO3\CMS\Vidi\Domain\Repository\ContentRepositoryFactory;
use TYPO3\CMS\Vidi\Domain\Model\Content;
use TYPO3\CMS\Vidi\Mvc\JsonView;
use TYPO3\CMS\Vidi\Mvc\JsonResult;
use TYPO3\CMS\Vidi\Persistence\MatcherObjectFactory;
use TYPO3\CMS\Vidi\Persistence\OrderObjectFactory;
use TYPO3\CMS\Vidi\Persistence\PagerObjectFactory;
use TYPO3\CMS\Vidi\Signal\ProcessContentDataSignalArguments;
use TYPO3\CMS\Vidi\Tca\TcaService;

/**
 * Controller which handles actions related to Vidi in the Backend.
 */
class ContentController extends ActionController {

	/**
	 * @var \TYPO3\CMS\Core\Page\PageRenderer
	 * @inject
	 */
	protected $pageRenderer;

	/**
	 * Initialize every action.
	 */
	public function initializeAction() {
		$this->pageRenderer->addInlineLanguageLabelFile('EXT:vidi/Resources/Private/Language/locallang.xlf');
	}

	/**
	 * List action for this controller.
	 *
	 * @return void
	 */
	public function indexAction() {
		$this->view->assign('columns', TcaService::grid()->getFields());
	}

	/**
	 * List Row action for this controller. Output a json list of contents
	 *
	 * @param array $columns corresponds to columns to be rendered.
	 * @param array $matches
	 * @validate $columns TYPO3\CMS\Vidi\Domain\Validator\ColumnsValidator
	 * @validate $matches TYPO3\CMS\Vidi\Domain\Validator\MatchesValidator
	 * @return void
	 */
	public function listAction(array $columns = array(), $matches = array()) {

		// Initialize some objects related to the query.
		$matcher = MatcherObjectFactory::getInstance()->getMatcher($matches);
		$order = OrderObjectFactory::getInstance()->getOrder();
		$pager = PagerObjectFactory::getInstance()->getPager();

		// Fetch objects via the Content Service.
		$contentService = $this->getContentService()->findBy($matcher, $order, $pager->getLimit(), $pager->getOffset());
		$pager->setCount($contentService->getNumberOfObjects());

		// Assign values.
		$this->view->assign('columns', $columns);
		$this->view->assign('objects', $contentService->getObjects());
		$this->view->assign('numberOfObjects', $contentService->getNumberOfObjects());
		$this->view->assign('pager', $pager);
		$this->view->assign('response', $this->response);
	}

	/**
	 * Retrieve Content objects first according to matching criteria and then "update" them.
	 * Important to notice the field name can contains a path, e.g. metadata.title and therefore must be analysed.
	 *
	 * Possible values for $matches:
	 * -----------------------------
	 *
	 * $matches = array(uid => 1), will be taken as $query->equals
	 * $matches = array(uid => 1,2,3), will be taken as $query->in
	 * $matches = array(field_name1 => bar, field_name2 => bax), will be separated by AND.
	 *
	 * Possible values for $content:
	 * -----------------------------
	 *
	 * $content = array(field_name => bar)
	 * $content = array(field_name => array(value1, value2)) <-- will be CSV converted by "value1,value2"
	 *
	 * @param string $fieldNameAndPath
	 * @param array $content
	 * @param array $matches
	 * @param string $savingBehavior
	 * @return string
	 */
	public function updateAction($fieldNameAndPath, array $content, array $matches = array(), $savingBehavior = SavingBehavior::REPLACE) {

		// Instantiate the Matcher object according different rules.
		$matcher = MatcherObjectFactory::getInstance()->getMatcher($matches);
		$order = OrderObjectFactory::getInstance()->getOrder();

		// Fetch objects via the Content Service.
		$contentService = $this->getContentService()->findBy($matcher, $order);

		// Get the real field that is going to be updated.
		$updatedFieldName = $this->getFieldPathResolver()->stripFieldPath($fieldNameAndPath);

		// Get result object for storing data along the processing.
		$result = $this->getJsonResult();
		$result->setNumberOfObjects($contentService->getNumberOfObjects());

		foreach ($contentService->getObjects() as $index => $object) {

			$identifier = $this->getContentObjectResolver()->getValue($object, $fieldNameAndPath, 'uid');
			$dataType = $this->getContentObjectResolver()->getDataType($object, $fieldNameAndPath);

			$signalResult = $this->emitProcessContentDataSignal($object, $fieldNameAndPath, $content, $index + 1, $savingBehavior);
			$contentData = $signalResult->getContentData();

			// Add identifier to content data, required by TCEMain.
			$contentData['uid'] = $identifier;

			/** @var Content $dataObject */
			$dataObject = $this->objectManager->get('TYPO3\CMS\Vidi\Domain\Model\Content', $dataType, $contentData);

			// Properly update object.
			ContentRepositoryFactory::getInstance($dataType)->update($dataObject);

			// Get the possible error messages and store them.
			$errorMessage = ContentRepositoryFactory::getInstance()->getErrorMessages();
			$result->addErrorMessages($errorMessage);

			// We only want to see the detail result if there is one object updated.
			// Required for inline editing + it will display some useful info on the GUI in the flash messages.
			if ($contentService->getNumberOfObjects() === 1) {

				// Reload the updated object from repository.
				$updatedObject = ContentRepositoryFactory::getInstance()->findByUid($object->getUid());

				// Re-fetch the updated result.
				$updatedResult = $this->getContentObjectResolver()->getValue($updatedObject, $fieldNameAndPath, $updatedFieldName);
				if (is_array($updatedResult)) {
					$_updatedResult = array(); // reset result set.

					/** @var Content $contentObject */
					foreach ($updatedResult as $contentObject) {
						$labelField = TcaService::table($contentObject)->getLabelField();
						$values = array(
							'uid' => $contentObject->getUid(),
							'name' => $contentObject[$labelField],
						);
						$_updatedResult[] = $values;
					}

					$updatedResult = $_updatedResult;
				}

				$labelField = TcaService::table($dataType)->getLabelField();
				$processedObjectData = array(
					'uid' => $this->getContentObjectResolver()->getValue($object, $fieldNameAndPath, 'uid'),
					'name' => $this->getContentObjectResolver()->getValue($object, $fieldNameAndPath, $labelField),
					'updatedField' => $updatedFieldName,
					'updatedValue' => $updatedResult,
				);
				$result->setProcessedObject($processedObjectData);

			}
		}

		// Set the result and render the JSON view.
		$this->getJsonView()->setResult($result);
		return $this->getJsonView()->render();
	}

	/**
	 * Returns an editing form for a given field name of a Content object.
	 * Argument $fieldNameAndPath corresponds to the field name to be edited.
	 * Important to notice it can contains a path, e.g. metadata.title and therefore must be analysed.
	 *
	 * @param string $fieldNameAndPath
	 * @param array $matches
	 * @throws \Exception
	 */
	public function editAction($fieldNameAndPath, array $matches = array()) {

		// Instantiate the Matcher object according different rules.
		$matcher = MatcherObjectFactory::getInstance()->getMatcher($matches);

		// Fetch objects via the Content Service.
		$contentService = $this->getContentService()->findBy($matcher);

		$dataType = $this->getFieldPathResolver()->getDataType($fieldNameAndPath);
		$fieldName = $this->getFieldPathResolver()->stripFieldPath($fieldNameAndPath);

		$fieldType = TcaService::table($dataType)->field($fieldName)->getType();
		$this->view->assign('fieldType', ucfirst($fieldType));
		$this->view->assign('dataType', $dataType);
		$this->view->assign('fieldName', $fieldName);
		$this->view->assign('matches', $matches);
		$this->view->assign('fieldNameAndPath', $fieldNameAndPath);
		$this->view->assign('numberOfObjects', $contentService->getNumberOfObjects());
		$this->view->assign('editWholeSelection', empty($matches['uid'])); // necessary??

		// Fetch content and its relations.
		if ($fieldType === TcaService::MULTISELECT) {

			$object = ContentRepositoryFactory::getInstance()->findOneBy($matcher);
			$identifier = $this->getContentObjectResolver()->getValue($object, $fieldNameAndPath, 'uid');
			$dataType = $this->getContentObjectResolver()->getDataType($object, $fieldNameAndPath);

			$content = ContentRepositoryFactory::getInstance($dataType)->findByUid($identifier);

			if (!$content) {
				$message = sprintf('I could not retrieved content object of type "%s" with identifier %s.', $dataType, $identifier);
				throw new \Exception($message, 1402350182);
			}

			$relatedDataType = TcaService::table($dataType)->field($fieldName)->getForeignTable();

			// Initialize the matcher object.
			$matcher = MatcherObjectFactory::getInstance()->getMatcher(array(), $relatedDataType);

			// Default ordering for related data type.
			$defaultOrderings = TcaService::table($relatedDataType)->getDefaultOrderings();
			/** @var \TYPO3\CMS\Vidi\Persistence\Order $order */
			$defaultOrder = GeneralUtility::makeInstance('TYPO3\CMS\Vidi\Persistence\Order', $defaultOrderings);

			// Fetch related contents
			$relatedContents = ContentRepositoryFactory::getInstance($relatedDataType)->findBy($matcher, $defaultOrder);

			$this->view->assign('content', $content);
			$this->view->assign('relatedContents', $relatedContents);
			$this->view->assign('relatedDataType', $relatedDataType);
			$this->view->assign('relatedContentTitle', TcaService::table($relatedDataType)->getTitle());
		}
	}

	/**
	 * Retrieve Content objects first according to matching criteria and then "delete" them.
	 *
	 * Possible values for $matches:
	 * -----------------------------
	 *
	 * $matches = array(uid => 1), will be taken as $query->equals
	 * $matches = array(uid => 1,2,3), will be taken as $query->in
	 * $matches = array(field_name1 => bar, field_name2 => bax), will be separated by AND.
	 *
	 * @param array $matches
	 * @return string
	 */
	public function deleteAction(array $matches = array()) {

		$matcher = MatcherObjectFactory::getInstance()->getMatcher($matches);

		// Fetch objects via the Content Service.
		$contentService = $this->getContentService()->findBy($matcher);

		// Compute the label field name of the table.
		$tableTitleField = TcaService::table()->getLabelField();

		// Get result object for storing data along the processing.
		$result = $this->getJsonResult();
		$result->setNumberOfObjects($contentService->getNumberOfObjects());

		foreach ($contentService->getObjects() as $object) {

			// Store the first object, so that the delete message can be more explicit when deleting only one record.
			if ($contentService->getNumberOfObjects() === 1) {
				$tableTitleValue = $object[$tableTitleField];
				$processedObjectData = array(
					'uid' => $object->getUid(),
					'name' => $tableTitleValue,
				);
				$result->setProcessedObject($processedObjectData);
			}

			// Properly delete object.
			ContentRepositoryFactory::getInstance()->remove($object);

			// Get the possible error messages and store them.
			$errorMessages = ContentRepositoryFactory::getInstance()->getErrorMessages();
			$result->addErrorMessages($errorMessages);
		}

		// Set the result and render the JSON view.
		$this->getJsonView()->setResult($result);
		return $this->getJsonView()->render();
	}

	/**
	 * Retrieve Content objects first according to matching criteria and then "move" them.
	 *
	 * Possible values for $matches:
	 * -----------------------------
	 *
	 * $matches = array(uid => 1), will be taken as $query->equals
	 * $matches = array(uid => 1,2,3), will be taken as $query->in
	 * $matches = array(field_name1 => bar, field_name2 => bax), will be separated by AND.
	 *
	 * @param string $target
	 * @param array $matches
	 * @return string
	 */
	public function moveAction($target, array $matches = array()) {

		$matcher = MatcherObjectFactory::getInstance()->getMatcher($matches);

		// Fetch objects via the Content Service.
		$contentService = $this->getContentService()->findBy($matcher);

		// Compute the label field name of the table.
		$tableTitleField = TcaService::table()->getLabelField();

		// Get result object for storing data along the processing.
		$result = $this->getJsonResult();
		$result->setNumberOfObjects($contentService->getNumberOfObjects());

		foreach ($contentService->getObjects() as $object) {

			// Store the first object, so that the "action" message can be more explicit when deleting only one record.
			if ($contentService->getNumberOfObjects() === 1) {
				$tableTitleValue = $object[$tableTitleField];
				$processedObjectData = array(
					'uid' => $object->getUid(),
					'name' => $tableTitleValue,
				);
				$result->setProcessedObject($processedObjectData);
			}

			// Work out the object.
			ContentRepositoryFactory::getInstance()->move($object, $target);

			// Get the possible error messages and store them.
			$errorMessages = ContentRepositoryFactory::getInstance()->getErrorMessages();
			$result->addErrorMessages($errorMessages);
		}

		// Set the result and render the JSON view.
		$this->getJsonView()->setResult($result);
		return $this->getJsonView()->render();
	}

	/**
	 * Retrieve Content objects first according to matching criteria and then "copy" them.
	 *
	 * Possible values for $matches:
	 * -----------------------------
	 *
	 * $matches = array(uid => 1), will be taken as $query->equals
	 * $matches = array(uid => 1,2,3), will be taken as $query->in
	 * $matches = array(field_name1 => bar, field_name2 => bax), will be separated by AND.
	 *
	 * @param string $target
	 * @param array $matches
	 * @throws \Exception
	 * @return string
	 */
	public function copyAction($target, array $matches = array()) {
		// @todo
		throw new \Exception('Not yet implemented', 1410192546);
	}

	/**
	 * Get the Vidi Module Loader.
	 *
	 * @return \TYPO3\CMS\Vidi\Service\ContentService
	 */
	protected function getContentService() {
		return GeneralUtility::makeInstance('TYPO3\CMS\Vidi\Service\ContentService');
	}

	/**
	 * @return \TYPO3\CMS\Vidi\Resolver\ContentObjectResolver
	 */
	protected function getContentObjectResolver() {
		return GeneralUtility::makeInstance('TYPO3\CMS\Vidi\Resolver\ContentObjectResolver');
	}

	/**
	 * @return \TYPO3\CMS\Vidi\Resolver\FieldPathResolver
	 */
	protected function getFieldPathResolver () {
		return GeneralUtility::makeInstance('TYPO3\CMS\Vidi\Resolver\FieldPathResolver');
	}

	/**
	 * Return a special view for handling JSON
	 * Goal is to have this view injected but require more configuration.
	 *
	 * @return JsonView
	 */
	protected function getJsonView() {
		if (!$this->view instanceof JsonView) {
			/** @var JsonView $view */
			$this->view = $this->objectManager->get('TYPO3\CMS\Vidi\Mvc\JsonView');
			$this->view->setResponse($this->response);
		}
		return $this->view;
	}

	/**
	 * @return JsonResult
	 */
	protected function getJsonResult() {
		return GeneralUtility::makeInstance('TYPO3\CMS\Vidi\Mvc\JsonResult');
	}

	/**
	 * Signal that is called for post-processing content data send to the server for update.
	 *
	 * @param Content $contentObject
	 * @param $fieldNameAndPath
	 * @param $contentData
	 * @param $counter
	 * @param $savingBehavior
	 * @return ProcessContentDataSignalArguments
	 * @signal
	 */
	protected function emitProcessContentDataSignal(Content $contentObject, $fieldNameAndPath, $contentData, $counter, $savingBehavior) {

		/** @var \TYPO3\CMS\Vidi\Signal\ProcessContentDataSignalArguments $signalArguments */
		$signalArguments = GeneralUtility::makeInstance('TYPO3\CMS\Vidi\Signal\ProcessContentDataSignalArguments');
		$signalArguments->setContentObject($contentObject)
			->setFieldNameAndPath($fieldNameAndPath)
			->setContentData($contentData)
			->setCounter($counter)
			->setSavingBehavior($savingBehavior);

		$signalResult = $this->getSignalSlotDispatcher()->dispatch('TYPO3\CMS\Vidi\Controller\Backend\ContentController', 'processContentData', array($signalArguments));
		return $signalResult[0];
	}

	/**
	 * Get the SignalSlot dispatcher.
	 *
	 * @return \TYPO3\CMS\Extbase\SignalSlot\Dispatcher
	 */
	protected function getSignalSlotDispatcher() {
		return $this->objectManager->get('TYPO3\\CMS\\Extbase\\SignalSlot\\Dispatcher');
	}
}
