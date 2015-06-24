<?php
namespace Fab\Vidi\Grid;

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

/**
 * Interface dealing with rendering a column in someway.
 *
 * @deprecated use ColumnRendererInterface, will be removed in 0.10 + 2 version
 */
interface GridRendererInterface {

	/**
	 * Render a column in the Grid.
	 *
	 * @return string
	 */
	public function render();

	/**
	 * @param \Fab\Vidi\Domain\Model\Content $object
	 * @return $this
	 */
	public function setObject($object);

	/**
	 * @param string $fieldName
	 * @return $this
	 */
	public function setFieldName($fieldName);

	/**
	 * @param int $index
	 * @return $this
	 */
	public function setRowIndex($index);

	/**
	 * @param array $configuration
	 * @return $this
	 */
	public function setFieldConfiguration($configuration);

	/**
	 * @param array $configuration
	 * @return $this
	 */
	public function setGridRendererConfiguration($configuration);

}
