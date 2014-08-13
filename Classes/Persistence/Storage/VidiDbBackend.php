<?php
namespace TYPO3\CMS\Vidi\Persistence\Storage;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2010-2013 Extbase Team (http://forge.typo3.org/projects/typo3v4-mvc)
 *  Extbase is a backport of TYPO3 Flow. All credits go to the TYPO3 Flow team.
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  A copy is found in the textfile GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/
use TYPO3\CMS\Vidi\Tca\TcaService;

	/**
 * A Storage backend
 */
class VidiDbBackend {

	const OPERATOR_EQUAL_TO_NULL = 'operatorEqualToNull';
	const OPERATOR_NOT_EQUAL_TO_NULL = 'operatorNotEqualToNull';

	/**
	 * The TYPO3 database object
	 *
	 * @var \TYPO3\CMS\Core\Database\DatabaseConnection
	 */
	protected $databaseHandle;

	/**
	 * The TYPO3 page repository. Used for language and workspace overlay
	 *
	 * @var \TYPO3\CMS\Frontend\Page\PageRepository
	 */
	protected $pageRepository;

	/**
	 * A first-level TypoScript configuration cache
	 *
	 * @var array
	 */
	protected $pageTSConfigCache = array();

	/**
	 * @var \TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface
	 * @inject
	 */
	protected $configurationManager;

	/**
	 * @var \TYPO3\CMS\Extbase\Service\CacheService
	 * @inject
	 */
	protected $cacheService;

	/**
	 * @var \TYPO3\CMS\Core\Cache\CacheManager
	 * @inject
	 */
	protected $cacheManager;

	/**
	 * @var \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend
	 */
	protected $tableColumnCache;

	/**
	 * @var \TYPO3\CMS\Extbase\Service\EnvironmentService
	 * @inject
	 */
	protected $environmentService;

	/**
	 * @var \TYPO3\CMS\Vidi\Persistence\Query
	 */
	protected $query;

	/**
	 * The default object type being returned for the Media Object Factory
	 *
	 * @var string
	 */
	protected $objectType = 'TYPO3\CMS\Vidi\Domain\Model\Content';

	/**
	 * Constructor. takes the database handle from $GLOBALS['TYPO3_DB']
	 */
	public function __construct(\TYPO3\CMS\Extbase\Persistence\QueryInterface $query) {
		$this->query = $query;
		$this->databaseHandle = $GLOBALS['TYPO3_DB'];
	}


	/**
	 * Lifecycle method
	 *
	 * @return void
	 */
	public function initializeObject() {
		$this->tableColumnCache = $this->cacheManager->getCache('extbase_typo3dbbackend_tablecolumns');
	}

	/**
	 * @param array $identifier
	 * @return string
	 */
	protected function parseIdentifier(array $identifier) {
		$fieldNames = array_keys($identifier);
		$suffixedFieldNames = array();
		foreach ($fieldNames as $fieldName) {
			$suffixedFieldNames[] = $fieldName . '=?';
		}
		return implode(' AND ', $suffixedFieldNames);
	}

	/**
	 * Returns the result of the query
	 */
	public function fetchResult() {

		$parameters = array();
		$statementParts = $this->parseQuery($this->query, $parameters);

		$sql = $this->buildQuery($statementParts, $parameters);

		$tableName = '';
		if (is_array($statementParts) && !empty($statementParts['tables'][0])) {
			$tableName = $statementParts['tables'][0];
		}
		$this->replacePlaceholders($sql, $parameters, $tableName);
		#print $sql; exit(); // @debug

		$result = $this->databaseHandle->sql_query($sql);
		$this->checkSqlErrors($sql);
		$rows = $this->getRowsFromResult($result);
		$this->databaseHandle->sql_free_result($result);

		// Get language uid from querySettings.
		// Ensure the backend handling is not broken (fallback to Get parameter 'L' if needed)
		# @todo evaluate this code.
		#$rows = $this->doLanguageAndWorkspaceOverlay($this->query->getSource(), $rows, $this->query->getQuerySettings());

		return $rows;
	}

	/**
	 * Returns the number of tuples matching the query.
	 *
	 * @throws \TYPO3\CMS\Extbase\Persistence\Generic\Storage\Exception\BadConstraintException
	 * @return integer The number of matching tuples
	 */
	public function countResult() {

		$parameters = array();
		$statementParts = $this->parseQuery($this->query, $parameters);
		// Reset $statementParts for valid table return
		reset($statementParts);

		// if limit is set, we need to count the rows "manually" as COUNT(*) ignores LIMIT constraints
		if (!empty($statementParts['limit'])) {
			$statement = $this->buildQuery($statementParts, $parameters);
			$this->replacePlaceholders($statement, $parameters, current($statementParts['tables']));
			#print $statement; exit(); // @debug
			$result = $this->databaseHandle->sql_query($statement);
			$this->checkSqlErrors($statement);
			$count = $this->databaseHandle->sql_num_rows($result);
		} else {
			$statementParts['fields'] = array('COUNT(*)');
			// having orderings without grouping is not compatible with non-MySQL DBMS
			$statementParts['orderings'] = array();
			if (isset($statementParts['keywords']['distinct'])) {
				unset($statementParts['keywords']['distinct']);
				$distinctField = $this->query->getDistinct() ? $this->query->getDistinct() : 'uid';
				$statementParts['fields'] = array('COUNT(DISTINCT ' . reset($statementParts['tables']) . '.' . $distinctField . ')');
			}

			$statement = $this->buildQuery($statementParts, $parameters);
			$this->replacePlaceholders($statement, $parameters, current($statementParts['tables']));

			#print $statement; exit(); // @debug
			$result = $this->databaseHandle->sql_query($statement);
			$this->checkSqlErrors($statement);
			$count = 0;
			if ($result) {
				$row = $this->databaseHandle->sql_fetch_assoc($result);
				$count = current($row);
			}
		}
		$this->databaseHandle->sql_free_result($result);
		return (integer) $count;
	}

	/**
	 * Parses the query and returns the SQL statement parts.
	 *
	 * @param \TYPO3\CMS\Extbase\Persistence\QueryInterface $query The query
	 * @param array &$parameters
	 * @return array The SQL statement parts
	 */
	public function parseQuery(\TYPO3\CMS\Extbase\Persistence\QueryInterface $query, array &$parameters) {
		$sql = array();
		$sql['keywords'] = array();
		$sql['tables'] = array();
		$sql['unions'] = array();
		$sql['fields'] = array();
		$sql['where'] = array();
		$sql['additionalWhereClause'] = array();
		$sql['orderings'] = array();
		$sql['limit'] = array();
		$source = $query->getSource();
		$this->parseSource($source, $sql);
		$this->parseConstraint($query->getConstraint(), $source, $sql, $parameters);
		$this->parseOrderings($query->getOrderings(), $source, $sql);
		$this->parseLimitAndOffset($query->getLimit(), $query->getOffset(), $sql);
		$tableNames = array_unique(array_keys($sql['tables'] + $sql['unions']));
		foreach ($tableNames as $tableName) {
			if (is_string($tableName) && strlen($tableName) > 0) {
				$this->addAdditionalWhereClause($query->getQuerySettings(), $tableName, $sql);
			}
		}
		return $sql;
	}

	/**
	 * Returns the statement, ready to be executed.
	 *
	 * @param array $sql The SQL statement parts
	 * @return string The SQL statement
	 */
	public function buildQuery(array $sql) {
		$statement = 'SELECT ' . implode(' ', $sql['keywords']) . ' ' . implode(',', $sql['fields']) . ' FROM ' . implode(' ', $sql['tables']) . ' ' . implode(' ', $sql['unions']);
		if (!empty($sql['where'])) {
			$statement .= ' WHERE ' . implode('', $sql['where']);
			if (!empty($sql['additionalWhereClause'])) {
				$statement .= ' AND ' . implode(' AND ', $sql['additionalWhereClause']);
			}
		} elseif (!empty($sql['additionalWhereClause'])) {
			$statement .= ' WHERE ' . implode(' AND ', $sql['additionalWhereClause']);
		}
		if (!empty($sql['orderings'])) {
			$statement .= ' ORDER BY ' . implode(', ', $sql['orderings']);
		}
		if (!empty($sql['limit'])) {
			$statement .= ' LIMIT ' . $sql['limit'];
		}
		return $statement;
	}

	/**
	 * Transforms a Query Source into SQL and parameter arrays
	 *
	 * @param \TYPO3\CMS\Extbase\Persistence\Generic\Qom\SourceInterface $source The source
	 * @param array &$sql
	 * @return void
	 */
	protected function parseSource(\TYPO3\CMS\Extbase\Persistence\Generic\Qom\SourceInterface $source, array &$sql) {
		if ($source instanceof \TYPO3\CMS\Extbase\Persistence\Generic\Qom\SelectorInterface) {
			$tableName = $source->getNodeTypeName();
			$sql['fields'][$tableName] = $tableName . '.*';
			$sql['tables'][$tableName] = $tableName;
			if ($this->query->getDistinct()) {
				$sql['fields'][$tableName] = $tableName . '.' . $this->query->getDistinct();
				$sql['keywords']['distinct'] = 'DISTINCT';
			}
		} elseif ($source instanceof \TYPO3\CMS\Extbase\Persistence\Generic\Qom\JoinInterface) {
			$this->parseJoin($source, $sql);
		}
	}

	/**
	 * Transforms a Join into SQL and parameter arrays
	 *
	 * @param \TYPO3\CMS\Extbase\Persistence\Generic\Qom\JoinInterface $join The join
	 * @param array &$sql The query parts
	 * @return void
	 */
	protected function parseJoin(\TYPO3\CMS\Extbase\Persistence\Generic\Qom\JoinInterface $join, array &$sql) {
		$leftSource = $join->getLeft();
		$leftTableName = $leftSource->getSelectorName();
		// $sql['fields'][$leftTableName] = $leftTableName . '.*';
		$rightSource = $join->getRight();
		if ($rightSource instanceof \TYPO3\CMS\Extbase\Persistence\Generic\Qom\JoinInterface) {
			$rightTableName = $rightSource->getLeft()->getSelectorName();
		} else {
			$rightTableName = $rightSource->getSelectorName();
			$sql['fields'][$leftTableName] = $rightTableName . '.*';
		}
		$sql['tables'][$leftTableName] = $leftTableName;
		$sql['unions'][$rightTableName] = 'LEFT JOIN ' . $rightTableName;
		$joinCondition = $join->getJoinCondition();
		if ($joinCondition instanceof \TYPO3\CMS\Extbase\Persistence\Generic\Qom\EquiJoinCondition) {
			$column1Name = $joinCondition->getProperty1Name();
			$column2Name = $joinCondition->getProperty2Name();
			$sql['unions'][$rightTableName] .= ' ON ' . $joinCondition->getSelector1Name() . '.' . $column1Name . ' = ' . $joinCondition->getSelector2Name() . '.' . $column2Name;
		}
		if ($rightSource instanceof \TYPO3\CMS\Extbase\Persistence\Generic\Qom\JoinInterface) {
			$this->parseJoin($rightSource, $sql);
		}
	}

	/**
	 * Transforms a constraint into SQL and parameter arrays
	 *
	 * @param \TYPO3\CMS\Extbase\Persistence\Generic\Qom\ConstraintInterface $constraint The constraint
	 * @param \TYPO3\CMS\Extbase\Persistence\Generic\Qom\SourceInterface $source The source
	 * @param array &$sql The query parts
	 * @param array &$parameters The parameters that will replace the markers
	 * @return void
	 */
	protected function parseConstraint(\TYPO3\CMS\Extbase\Persistence\Generic\Qom\ConstraintInterface $constraint = NULL, \TYPO3\CMS\Extbase\Persistence\Generic\Qom\SourceInterface $source, array &$sql, array &$parameters) {
		if ($constraint instanceof \TYPO3\CMS\Extbase\Persistence\Generic\Qom\AndInterface) {
			$sql['where'][] = '(';
			$this->parseConstraint($constraint->getConstraint1(), $source, $sql, $parameters);
			$sql['where'][] = ' AND ';
			$this->parseConstraint($constraint->getConstraint2(), $source, $sql, $parameters);
			$sql['where'][] = ')';
		} elseif ($constraint instanceof \TYPO3\CMS\Extbase\Persistence\Generic\Qom\OrInterface) {
			$sql['where'][] = '(';
			$this->parseConstraint($constraint->getConstraint1(), $source, $sql, $parameters);
			$sql['where'][] = ' OR ';
			$this->parseConstraint($constraint->getConstraint2(), $source, $sql, $parameters);
			$sql['where'][] = ')';
		} elseif ($constraint instanceof \TYPO3\CMS\Extbase\Persistence\Generic\Qom\NotInterface) {
			$sql['where'][] = 'NOT (';
			$this->parseConstraint($constraint->getConstraint(), $source, $sql, $parameters);
			$sql['where'][] = ')';
		} elseif ($constraint instanceof \TYPO3\CMS\Extbase\Persistence\Generic\Qom\ComparisonInterface) {
			$this->parseComparison($constraint, $source, $sql, $parameters);
		}
	}

	/**
	 * Parse a Comparison into SQL and parameter arrays.
	 *
	 * @param \TYPO3\CMS\Extbase\Persistence\Generic\Qom\ComparisonInterface $comparison The comparison to parse
	 * @param \TYPO3\CMS\Extbase\Persistence\Generic\Qom\SourceInterface $source The source
	 * @param array &$sql SQL query parts to add to
	 * @param array &$parameters Parameters to bind to the SQL
	 * @throws \TYPO3\CMS\Extbase\Persistence\Generic\Exception\RepositoryException
	 * @return void
	 */
	protected function parseComparison(\TYPO3\CMS\Extbase\Persistence\Generic\Qom\ComparisonInterface $comparison, \TYPO3\CMS\Extbase\Persistence\Generic\Qom\SourceInterface $source, array &$sql, array &$parameters) {
		$operand1 = $comparison->getOperand1();
		$operator = $comparison->getOperator();
		$operand2 = $comparison->getOperand2();
		if ($operator === \TYPO3\CMS\Extbase\Persistence\QueryInterface::OPERATOR_IN) {
			$items = array();
			$hasValue = FALSE;
			foreach ($operand2 as $value) {
				$value = $this->getPlainValue($value);
				if ($value !== NULL) {
					$items[] = $value;
					$hasValue = TRUE;
				}
			}
			if ($hasValue === FALSE) {
				$sql['where'][] = '1<>1';
			} else {
				$this->parseDynamicOperand($operand1, $operator, $source, $sql, $parameters, NULL, $operand2);
				$parameters[] = $items;
			}
		} elseif ($operator === \TYPO3\CMS\Extbase\Persistence\QueryInterface::OPERATOR_CONTAINS) {
			if ($operand2 === NULL) {
				$sql['where'][] = '1<>1';
			} else {
				// @todo check if this case is really used.
				$tableName = $this->query->getType();
				$propertyName = $operand1->getPropertyName();
				while (strpos($propertyName, '.') !== FALSE) {
					$this->addUnionStatement($tableName, $propertyName, $sql);
				}
				$columnName = $propertyName;
				$columnMap = $propertyName;
				$typeOfRelation = $columnMap instanceof \TYPO3\CMS\Extbase\Persistence\Generic\Mapper\ColumnMap ? $columnMap->getTypeOfRelation() : NULL;
				if ($typeOfRelation === \TYPO3\CMS\Extbase\Persistence\Generic\Mapper\ColumnMap::RELATION_HAS_AND_BELONGS_TO_MANY) {
					$relationTableName = $columnMap->getRelationTableName();
					$sql['where'][] = $tableName . '.uid IN (SELECT ' . $columnMap->getParentKeyFieldName() . ' FROM ' . $relationTableName . ' WHERE ' . $columnMap->getChildKeyFieldName() . '=?)';
					$parameters[] = intval($this->getPlainValue($operand2));
				} elseif ($typeOfRelation === \TYPO3\CMS\Extbase\Persistence\Generic\Mapper\ColumnMap::RELATION_HAS_MANY) {
					$parentKeyFieldName = $columnMap->getParentKeyFieldName();
					if (isset($parentKeyFieldName)) {
						$childTableName = $columnMap->getChildTableName();
						$sql['where'][] = $tableName . '.uid=(SELECT ' . $childTableName . '.' . $parentKeyFieldName . ' FROM ' . $childTableName . ' WHERE ' . $childTableName . '.uid=?)';
						$parameters[] = intval($this->getPlainValue($operand2));
					} else {
						$sql['where'][] = 'FIND_IN_SET(?,' . $tableName . '.' . $columnName . ')';
						$parameters[] = intval($this->getPlainValue($operand2));
					}
				} else {
					throw new \TYPO3\CMS\Extbase\Persistence\Generic\Exception\RepositoryException('Unsupported or non-existing property name "' . $propertyName . '" used in relation matching.', 1327065745);
				}
			}
		} else {
			if ($operand2 === NULL) {
				if ($operator === \TYPO3\CMS\Extbase\Persistence\QueryInterface::OPERATOR_EQUAL_TO) {
					$operator = self::OPERATOR_EQUAL_TO_NULL;
				} elseif ($operator === \TYPO3\CMS\Extbase\Persistence\QueryInterface::OPERATOR_NOT_EQUAL_TO) {
					$operator = self::OPERATOR_NOT_EQUAL_TO_NULL;
				}
			}
			$this->parseDynamicOperand($operand1, $operator, $source, $sql, $parameters);
			$parameters[] = $this->getPlainValue($operand2);
		}
	}

	/**
	 * Returns a plain value, i.e. objects are flattened out if possible.
	 *
	 * @param mixed $input
	 * @throws \TYPO3\CMS\Extbase\Persistence\Generic\Exception\UnexpectedTypeException
	 * @return mixed
	 */
	protected function getPlainValue($input) {
		if (is_array($input)) {
			throw new \TYPO3\CMS\Extbase\Persistence\Generic\Exception\UnexpectedTypeException('An array could not be converted to a plain value.', 1274799932);
		}
		if ($input instanceof \DateTime) {
			return $input->format('U');
		} elseif (is_object($input)) {
			if ($input instanceof \TYPO3\CMS\Extbase\Persistence\Generic\LazyLoadingProxy) {
				$realInput = $input->_loadRealInstance();
			} else {
				$realInput = $input;
			}
			if ($realInput instanceof \TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface) {
				return $realInput->getUid();
			} else {
				throw new \TYPO3\CMS\Extbase\Persistence\Generic\Exception\UnexpectedTypeException('An object of class "' . get_class($realInput) . '" could not be converted to a plain value.', 1274799934);
			}
		} elseif (is_bool($input)) {
			return $input === TRUE ? 1 : 0;
		} else {
			return $input;
		}
	}

	/**
	 * Parse a DynamicOperand into SQL and parameter arrays.
	 *
	 * @param \TYPO3\CMS\Extbase\Persistence\Generic\Qom\DynamicOperandInterface $operand
	 * @param string $operator One of the JCR_OPERATOR_* constants
	 * @param \TYPO3\CMS\Extbase\Persistence\Generic\Qom\SourceInterface $source The source
	 * @param array &$sql The query parts
	 * @param array &$parameters The parameters that will replace the markers
	 * @param string $valueFunction an optional SQL function to apply to the operand value
	 * @param null $operand2
	 * @return void
	 */
	protected function parseDynamicOperand(\TYPO3\CMS\Extbase\Persistence\Generic\Qom\DynamicOperandInterface $operand, $operator, \TYPO3\CMS\Extbase\Persistence\Generic\Qom\SourceInterface $source, array &$sql, array &$parameters, $valueFunction = NULL, $operand2 = NULL) {
		if ($operand instanceof \TYPO3\CMS\Extbase\Persistence\Generic\Qom\LowerCaseInterface) {
			$this->parseDynamicOperand($operand->getOperand(), $operator, $source, $sql, $parameters, 'LOWER');
		} elseif ($operand instanceof \TYPO3\CMS\Extbase\Persistence\Generic\Qom\UpperCaseInterface) {
			$this->parseDynamicOperand($operand->getOperand(), $operator, $source, $sql, $parameters, 'UPPER');
		} elseif ($operand instanceof \TYPO3\CMS\Extbase\Persistence\Generic\Qom\PropertyValueInterface) {
			$propertyName = $operand->getPropertyName();
			if ($source instanceof \TYPO3\CMS\Extbase\Persistence\Generic\Qom\SelectorInterface) {
				// FIXME Only necessary to differ from  Join
				$tableName = $this->query->getType();
				while (strpos($propertyName, '.') !== FALSE) {
					$this->addUnionStatement($tableName, $propertyName, $sql);
				}
			} elseif ($source instanceof \TYPO3\CMS\Extbase\Persistence\Generic\Qom\JoinInterface) {
				$tableName = $source->getJoinCondition()->getSelector1Name();
			}
			$columnName = $propertyName;
			$operator = $this->resolveOperator($operator);
			$constraintSQL = '';
			if ($valueFunction === NULL) {
				$constraintSQL .= (!empty($tableName) ? $tableName . '.' : '') . $columnName . ' ' . $operator . ' ?';
			} else {
				$constraintSQL .= $valueFunction . '(' . (!empty($tableName) ? $tableName . '.' : '') . $columnName . ') ' . $operator . ' ?';
			}
			$sql['where'][] = $constraintSQL;
		}
	}

	/**
	 * @param string &$tableName
	 * @param array &$propertyPath
	 * @param array &$sql
	 * @throws \TYPO3\CMS\Extbase\Persistence\Generic\Exception
	 * @throws \TYPO3\CMS\Extbase\Persistence\Generic\Exception\InvalidRelationConfigurationException
	 * @throws \TYPO3\CMS\Extbase\Persistence\Generic\Exception\MissingColumnMapException
	 */
	protected function addUnionStatement(&$tableName, &$propertyPath, array &$sql) {

		$table = TcaService::table($tableName);

		$explodedPropertyPath = explode('.', $propertyPath, 2);
		$fieldName = $explodedPropertyPath[0];

		// Field of type "group" are special because property path must contain the table name
		// to determine the relation type. Example for sys_category, property path will look like "items.sys_file"
		if ($table->field($fieldName)->isGroup()) {
			$parts = explode('.', $propertyPath, 3);
			$explodedPropertyPath[0] = $parts[0] . '.' . $parts[1];
			$explodedPropertyPath[1] = $parts[2];
			$fieldName = $explodedPropertyPath[0];
		}

		$parentKeyFieldName = $table->field($fieldName)->getForeignField();
		$childTableName = $table->field($fieldName)->getForeignTable();

		if ($childTableName === NULL) {
			throw new \TYPO3\CMS\Extbase\Persistence\Generic\Exception\InvalidRelationConfigurationException('The relation information for property "' . $fieldName . '" of class "' . $tableName . '" is missing.', 1353170925);
		}

		if ($table->field($fieldName)->hasOne()) { // includes relation "one-to-one" and "many-to-one"
			// sometimes the opposite relation is not defined. We don't want to force this config for backward compatibility reasons.
			// $parentKeyFieldName === NULL does the trick somehow. Before condition was if (isset($parentKeyFieldName))
			if ($table->field($fieldName)->hasRelationManyToOne() || $parentKeyFieldName === NULL) {
				$sql['unions'][$childTableName] = 'LEFT JOIN ' . $childTableName . ' ON ' . $tableName . '.' . $fieldName . '=' . $childTableName . '.uid';
			} else {
				$sql['unions'][$childTableName] = 'LEFT JOIN ' . $childTableName . ' ON ' . $tableName . '.uid=' . $childTableName . '.' . $parentKeyFieldName;
			}
		} elseif ($table->field($fieldName)->hasRelationManyToMany()) {
			$relationTableName = $table->field($fieldName)->getManyToManyTable();

			$parentKeyFieldName = $table->field($fieldName)->isOppositeRelation() ? 'uid_foreign' : 'uid_local';
			$childKeyFieldName = !$table->field($fieldName)->isOppositeRelation() ? 'uid_foreign' : 'uid_local';
			$tableNameCondition = $table->field($fieldName)->getAdditionalTableNameCondition();

			$sql['unions'][$relationTableName] = 'LEFT JOIN ' . $relationTableName . ' ON ' . $tableName . '.uid=' . $relationTableName . '.' . $parentKeyFieldName;
			$sql['unions'][$childTableName] = 'LEFT JOIN ' . $childTableName . ' ON ' . $relationTableName . '.' . $childKeyFieldName . '=' . $childTableName . '.uid';

			if ($tableNameCondition) {
				$sql['unions'][$relationTableName] .= ' AND ' . $relationTableName . '.tablenames = \'' . $tableNameCondition . '\'';
				$sql['unions'][$childTableName] .= ' AND ' . $relationTableName . '.tablenames = \'' . $tableNameCondition . '\'';
			}
		} elseif ($table->field($fieldName)->hasMany()) { // includes relations "many-to-one" and "csv" relations
			if (isset($parentKeyFieldName)) {
				$sql['unions'][$childTableName] = 'LEFT JOIN ' . $childTableName . ' ON ' . $tableName . '.uid=' . $childTableName . '.' . $parentKeyFieldName;
			} else {
				$onStatement = '(FIND_IN_SET(' . $childTableName . '.uid, ' . $tableName . '.' . $fieldName . '))';
				$sql['unions'][$childTableName] = 'LEFT JOIN ' . $childTableName . ' ON ' . $onStatement;
			}
		} else {
			throw new \TYPO3\CMS\Extbase\Persistence\Generic\Exception('Could not determine type of relation.', 1252502725);
		}

		// TODO check if there is another solution for this
		$sql['keywords']['distinct'] = 'DISTINCT';
		$propertyPath = $explodedPropertyPath[1];
		$tableName = $childTableName;
	}

	/**
	 * Returns the SQL operator for the given JCR operator type.
	 *
	 * @param string $operator One of the JCR_OPERATOR_* constants
	 * @throws \TYPO3\CMS\Extbase\Persistence\Generic\Exception
	 * @return string an SQL operator
	 */
	protected function resolveOperator($operator) {
		switch ($operator) {
			case self::OPERATOR_EQUAL_TO_NULL:
				$operator = 'IS';
				break;
			case self::OPERATOR_NOT_EQUAL_TO_NULL:
				$operator = 'IS NOT';
				break;
			case \TYPO3\CMS\Extbase\Persistence\QueryInterface::OPERATOR_IN:
				$operator = 'IN';
				break;
			case \TYPO3\CMS\Extbase\Persistence\QueryInterface::OPERATOR_EQUAL_TO:
				$operator = '=';
				break;
			case \TYPO3\CMS\Extbase\Persistence\QueryInterface::OPERATOR_NOT_EQUAL_TO:
				$operator = '!=';
				break;
			case \TYPO3\CMS\Extbase\Persistence\QueryInterface::OPERATOR_LESS_THAN:
				$operator = '<';
				break;
			case \TYPO3\CMS\Extbase\Persistence\QueryInterface::OPERATOR_LESS_THAN_OR_EQUAL_TO:
				$operator = '<=';
				break;
			case \TYPO3\CMS\Extbase\Persistence\QueryInterface::OPERATOR_GREATER_THAN:
				$operator = '>';
				break;
			case \TYPO3\CMS\Extbase\Persistence\QueryInterface::OPERATOR_GREATER_THAN_OR_EQUAL_TO:
				$operator = '>=';
				break;
			case \TYPO3\CMS\Extbase\Persistence\QueryInterface::OPERATOR_LIKE:
				$operator = 'LIKE';
				break;
			default:
				throw new \TYPO3\CMS\Extbase\Persistence\Generic\Exception('Unsupported operator encountered.', 1242816073);
		}
		return $operator;
	}

	/**
	 * Replace query placeholders in a query part by the given
	 * parameters.
	 *
	 * @param string &$sqlString The query part with placeholders
	 * @param array $parameters The parameters
	 * @param string $tableName
	 *
	 * @throws \TYPO3\CMS\Extbase\Persistence\Generic\Exception
	 */
	protected function replacePlaceholders(&$sqlString, array $parameters, $tableName = 'foo') {
		// TODO profile this method again
		if (substr_count($sqlString, '?') !== count($parameters)) {
			throw new \TYPO3\CMS\Extbase\Persistence\Generic\Exception('The number of question marks to replace must be equal to the number of parameters.', 1242816074);
		}
		$offset = 0;
		foreach ($parameters as $parameter) {
			$markPosition = strpos($sqlString, '?', $offset);
			if ($markPosition !== FALSE) {
				if ($parameter === NULL) {
					$parameter = 'NULL';
				} elseif (is_array($parameter) || $parameter instanceof \ArrayAccess || $parameter instanceof \Traversable) {
					$items = array();
					foreach ($parameter as $item) {
						$items[] = $this->databaseHandle->fullQuoteStr($item, $tableName);
					}
					$parameter = '(' . implode(',', $items) . ')';
				} else {
					$parameter = $this->databaseHandle->fullQuoteStr($parameter, $tableName);
				}
				$sqlString = substr($sqlString, 0, $markPosition) . $parameter . substr($sqlString, ($markPosition + 1));
			}
			$offset = $markPosition + strlen($parameter);
		}
	}

	/**
	 * Adds additional WHERE statements according to the query settings.
	 *
	 * @param \TYPO3\CMS\Extbase\Persistence\Generic\QuerySettingsInterface $querySettings The TYPO3 CMS specific query settings
	 * @param string $tableName The table name to add the additional where clause for
	 * @param string &$sql
	 * @return void
	 */
	protected function addAdditionalWhereClause(\TYPO3\CMS\Extbase\Persistence\Generic\QuerySettingsInterface $querySettings, $tableName, &$sql) {
		$this->addVisibilityConstraintStatement($querySettings, $tableName, $sql);
		if ($querySettings->getRespectSysLanguage()) {
			$this->addSysLanguageStatement($tableName, $sql, $querySettings);
		}
		if ($querySettings->getRespectStoragePage()) {
			$this->addPageIdStatement($tableName, $sql, $querySettings->getStoragePageIds());
		}
	}


	/**
	 * Adds enableFields and deletedClause to the query if necessary
	 *
	 * @param \TYPO3\CMS\Extbase\Persistence\Generic\QuerySettingsInterface $querySettings
	 * @param string $tableName The database table name
	 * @param array &$sql The query parts
	 * @return void
	 */
	protected function addVisibilityConstraintStatement(\TYPO3\CMS\Extbase\Persistence\Generic\QuerySettingsInterface $querySettings, $tableName, array &$sql) {
		$statement = '';
		if (is_array($GLOBALS['TCA'][$tableName]['ctrl'])) {
			$ignoreEnableFields = $querySettings->getIgnoreEnableFields();
			$enableFieldsToBeIgnored = $querySettings->getEnableFieldsToBeIgnored();
			$includeDeleted = $querySettings->getIncludeDeleted();
			if ($this->environmentService->isEnvironmentInFrontendMode()) {
				$statement .= $this->getFrontendConstraintStatement($tableName, $ignoreEnableFields, $enableFieldsToBeIgnored, $includeDeleted);
			} else {
				// TYPO3_MODE === 'BE'
				$statement .= $this->getBackendConstraintStatement($tableName, $ignoreEnableFields, $includeDeleted);
			}
			if (!empty($statement)) {
				$statement = strtolower(substr($statement, 1, 3)) === 'and' ? substr($statement, 5) : $statement;
				$sql['additionalWhereClause'][] = $statement;
			}
		}
	}

	/**
	 * Returns constraint statement for frontend context
	 *
	 * @param string $tableName
	 * @param boolean $ignoreEnableFields A flag indicating whether the enable fields should be ignored
	 * @param array $enableFieldsToBeIgnored If $ignoreEnableFields is true, this array specifies enable fields to be ignored. If it is NULL or an empty array (default) all enable fields are ignored.
	 * @param boolean $includeDeleted A flag indicating whether deleted records should be included
	 * @return string
	 * @throws \TYPO3\CMS\Extbase\Persistence\Generic\Exception\InconsistentQuerySettingsException
	 */
	protected function getFrontendConstraintStatement($tableName, $ignoreEnableFields, $enableFieldsToBeIgnored = array(), $includeDeleted) {
		$statement = '';
		if ($ignoreEnableFields && !$includeDeleted) {
			if (count($enableFieldsToBeIgnored)) {
				// array_combine() is necessary because of the way \TYPO3\CMS\Frontend\Page\PageRepository::enableFields() is implemented
				$statement .= $this->getPageRepository()->enableFields($tableName, -1, array_combine($enableFieldsToBeIgnored, $enableFieldsToBeIgnored));
			} else {
				$statement .= $this->getPageRepository()->deleteClause($tableName);
			}
		} elseif (!$ignoreEnableFields && !$includeDeleted) {
			$statement .= $this->getPageRepository()->enableFields($tableName);
		} elseif (!$ignoreEnableFields && $includeDeleted) {
			throw new \TYPO3\CMS\Extbase\Persistence\Generic\Exception\InconsistentQuerySettingsException('Query setting "ignoreEnableFields=FALSE" can not be used together with "includeDeleted=TRUE" in frontend context.', 1327678173);
		}
		return $statement;
	}

	/**
	 * Returns constraint statement for backend context
	 *
	 * @param string $tableName
	 * @param boolean $ignoreEnableFields A flag indicating whether the enable fields should be ignored
	 * @param boolean $includeDeleted A flag indicating whether deleted records should be included
	 * @return string
	 */
	protected function getBackendConstraintStatement($tableName, $ignoreEnableFields, $includeDeleted) {
		$statement = '';
		if (!$ignoreEnableFields) {
			$statement .= \TYPO3\CMS\Backend\Utility\BackendUtility::BEenableFields($tableName);
		}
		if (!$includeDeleted) {
			$statement .= \TYPO3\CMS\Backend\Utility\BackendUtility::deleteClause($tableName);
		}
		return $statement;
	}

	/**
	 * Builds the language field statement
	 *
	 * @param string $tableName The database table name
	 * @param array &$sql The query parts
	 * @param \TYPO3\CMS\Extbase\Persistence\Generic\QuerySettingsInterface $querySettings The TYPO3 CMS specific query settings
	 * @return void
	 */
	protected function addSysLanguageStatement($tableName, array &$sql, $querySettings) {
		if (is_array($GLOBALS['TCA'][$tableName]['ctrl'])) {
			if (!empty($GLOBALS['TCA'][$tableName]['ctrl']['languageField'])) {
				// Select all entries for the current language
				$additionalWhereClause = $tableName . '.' . $GLOBALS['TCA'][$tableName]['ctrl']['languageField'] . ' IN (' . intval($querySettings->getSysLanguageUid()) . ',-1)';
				// If any language is set -> get those entries which are not translated yet
				// They will be removed by t3lib_page::getRecordOverlay if not matching overlay mode
				if (isset($GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'])
					&& $querySettings->getSysLanguageUid() > 0
				) {
					$additionalWhereClause .= ' OR (' . $tableName . '.' . $GLOBALS['TCA'][$tableName]['ctrl']['languageField'] . '=0' .
						' AND ' . $tableName . '.uid NOT IN (SELECT ' . $tableName . '.' . $GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'] .
						' FROM ' . $tableName .
						' WHERE ' . $tableName . '.' . $GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'] . '>0' .
						' AND ' . $tableName . '.' . $GLOBALS['TCA'][$tableName]['ctrl']['languageField'] . '>0';

					// Add delete clause to ensure all entries are loaded
					if (isset($GLOBALS['TCA'][$tableName]['ctrl']['delete'])) {
						$additionalWhereClause .= ' AND ' . $tableName . '.' . $GLOBALS['TCA'][$tableName]['ctrl']['delete'] . '=0';
					}
					$additionalWhereClause .= '))';
				}
				$sql['additionalWhereClause'][] = '(' . $additionalWhereClause . ')';
			}
		}
	}

	/**
	 * Builds the page ID checking statement
	 *
	 * @param string $tableName The database table name
	 * @param array &$sql The query parts
	 * @param array $storagePageIds list of storage page ids
	 * @throws \TYPO3\CMS\Extbase\Persistence\Generic\Exception\InconsistentQuerySettingsException
	 * @return void
	 */
	protected function addPageIdStatement($tableName, array &$sql, array $storagePageIds) {
		$tableColumns = $this->tableColumnCache->get($tableName);
		if ($tableColumns === FALSE) {
			$tableColumns = $this->databaseHandle->admin_get_fields($tableName);
			$this->tableColumnCache->set($tableName, $tableColumns);
		}
		if (is_array($GLOBALS['TCA'][$tableName]['ctrl']) && array_key_exists('pid', $tableColumns)) {
			$rootLevel = (int)$GLOBALS['TCA'][$tableName]['ctrl']['rootLevel'];
			if ($rootLevel) {
				if ($rootLevel === 1) {
					$sql['additionalWhereClause'][] = $tableName . '.pid = 0';
				}
			} else {
				if (empty($storagePageIds)) {
					throw new \TYPO3\CMS\Extbase\Persistence\Generic\Exception\InconsistentQuerySettingsException('Missing storage page ids.', 1365779762);
				}
				$sql['additionalWhereClause'][] = $tableName . '.pid IN (' . implode(', ', $storagePageIds) . ')';
			}
		}
	}

	/**
	 * Transforms orderings into SQL.
	 *
	 * @param array $orderings An array of orderings (Tx_Extbase_Persistence_QOM_Ordering)
	 * @param \TYPO3\CMS\Extbase\Persistence\Generic\Qom\SourceInterface $source The source
	 * @param array &$sql The query parts
	 * @throws \TYPO3\CMS\Extbase\Persistence\Generic\Exception\UnsupportedOrderException
	 * @return void
	 */
	protected function parseOrderings(array $orderings, \TYPO3\CMS\Extbase\Persistence\Generic\Qom\SourceInterface $source, array &$sql) {
		foreach ($orderings as $propertyName => $order) {
			switch ($order) {
				case \TYPO3\CMS\Extbase\Persistence\QueryInterface::ORDER_ASCENDING:
					$order = 'ASC';
					break;
				case \TYPO3\CMS\Extbase\Persistence\QueryInterface::ORDER_DESCENDING:
					$order = 'DESC';
					break;
				default:
					throw new \TYPO3\CMS\Extbase\Persistence\Generic\Exception\UnsupportedOrderException('Unsupported order encountered.', 1242816074);
			}
			$tableName = '';
			if ($source instanceof \TYPO3\CMS\Extbase\Persistence\Generic\Qom\SelectorInterface) {
				$tableName = $this->query->getType();
				while (strpos($propertyName, '.') !== FALSE) {
					$this->addUnionStatement($tableName, $propertyName, $sql);
				}
			} elseif ($source instanceof \TYPO3\CMS\Extbase\Persistence\Generic\Qom\JoinInterface) {
				$tableName = $source->getLeft()->getSelectorName();
			}
			$columnName = $propertyName;
			if (strlen($tableName) > 0) {
				$sql['orderings'][] = $tableName . '.' . $columnName . ' ' . $order;
			} else {
				$sql['orderings'][] = $columnName . ' ' . $order;
			}
		}
	}

	/**
	 * Transforms limit and offset into SQL
	 *
	 * @param integer $limit
	 * @param integer $offset
	 * @param array &$sql
	 * @return void
	 */
	protected function parseLimitAndOffset($limit, $offset, array &$sql) {
		if ($limit !== NULL && $offset !== NULL) {
			$sql['limit'] = intval($offset) . ', ' . intval($limit);
		} elseif ($limit !== NULL) {
			$sql['limit'] = intval($limit);
		}
	}

	/**
	 * Transforms a Resource from a database query to an array of rows.
	 *
	 * @param resource $result The result
	 * @return array The result as an array of rows (tuples)
	 */
	protected function getRowsFromResult($result) {
		$rows = array();
		while ($row = $this->databaseHandle->sql_fetch_assoc($result)) {
			if (is_array($row)) {

				// Get record overlay if needed
				// @todo evaluate this code.
//				if (TYPO3_MODE == 'FE' && $GLOBALS['TSFE']->sys_language_uid > 0) {
//
//					$overlay = \TYPO3\CMS\Vidi\Language\Overlays::getOverlayRecords($this->query->getType(), array($row['uid']), $GLOBALS['TSFE']->sys_language_uid);
//					if (!empty($overlay[$row['uid']])) {
//						$key = key($overlay[$row['uid']]);
//						$row = $overlay[$row['uid']][$key];
//					}
//				}

				if (!$this->query->getQuerySettings()->getReturnRawQueryResult()) {
					$row = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance($this->objectType, $this->query->getType(), $row);
				}

				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Performs workspace and language overlay on the given row array. The language and workspace id is automatically
	 * detected (depending on FE or BE context). You can also explicitly set the language/workspace id.
	 *
	 * @param \TYPO3\CMS\Extbase\Persistence\Generic\Qom\SourceInterface $source The source (selector od join)
	 * @param array $rows
	 * @param \TYPO3\CMS\Extbase\Persistence\Generic\QuerySettingsInterface $querySettings The TYPO3 CMS specific query settings
	 * @param null|integer $workspaceUid
	 * @return array
	 */
	protected function doLanguageAndWorkspaceOverlay(\TYPO3\CMS\Extbase\Persistence\Generic\Qom\SourceInterface $source, array $rows, $querySettings, $workspaceUid = NULL) {
		if ($source instanceof \TYPO3\CMS\Extbase\Persistence\Generic\Qom\SelectorInterface) {
			$tableName = $source->getSelectorName();
		} elseif ($source instanceof \TYPO3\CMS\Extbase\Persistence\Generic\Qom\JoinInterface) {
			$tableName = $source->getRight()->getSelectorName();
		}
		// If we do not have a table name here, we cannot do an overlay and return the original rows instead.
		if (isset($tableName)) {
			$pageRepository = $this->getPageRepository();
			if (is_object($GLOBALS['TSFE'])) {
				$languageMode = $GLOBALS['TSFE']->sys_language_mode;
				if ($workspaceUid !== NULL) {
					$pageRepository->versioningWorkspaceId = $workspaceUid;
				}
			} else {
				$languageMode = '';
				if ($workspaceUid === NULL) {
					$workspaceUid = $GLOBALS['BE_USER']->workspace;
				}
				$pageRepository->versioningWorkspaceId = $workspaceUid;
			}

			$overlayedRows = array();
			foreach ($rows as $row) {
				// If current row is a translation select its parent
				if (isset($tableName) && isset($GLOBALS['TCA'][$tableName])
					&& isset($GLOBALS['TCA'][$tableName]['ctrl']['languageField'])
					&& isset($GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'])
				) {
					if (isset($row[$GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField']])
						&& $row[$GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField']] > 0
					) {
						$row = $this->databaseHandle->exec_SELECTgetSingleRow(
							$tableName . '.*',
							$tableName,
							$tableName . '.uid=' . (integer) $row[$GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField']] .
								' AND ' . $tableName . '.' . $GLOBALS['TCA'][$tableName]['ctrl']['languageField'] . '=0'
						);
					}
				}
				$pageRepository->versionOL($tableName, $row, TRUE);
				if ($pageRepository->versioningPreview && isset($row['_ORIG_uid'])) {
					$row['uid'] = $row['_ORIG_uid'];
				}
				if ($tableName == 'pages') {
					$row = $pageRepository->getPageOverlay($row, $querySettings->getSysLanguageUid());
				} elseif (isset($GLOBALS['TCA'][$tableName]['ctrl']['languageField'])
					&& $GLOBALS['TCA'][$tableName]['ctrl']['languageField'] !== ''
				) {
					if (in_array($row[$GLOBALS['TCA'][$tableName]['ctrl']['languageField']], array(-1, 0))) {
						$overlayMode = $languageMode === 'strict' ? 'hideNonTranslated' : '';
						$row = $pageRepository->getRecordOverlay($tableName, $row, $querySettings->getSysLanguageUid(), $overlayMode);
					}
				}
				if ($row !== NULL && is_array($row)) {
					$overlayedRows[] = $row;
				}
			}
		} else {
			$overlayedRows = $rows;
		}
		return $overlayedRows;
	}

	/**
	 * @return \TYPO3\CMS\Frontend\Page\PageRepository
	 */
	protected function getPageRepository() {
		if (!$this->pageRepository instanceof \TYPO3\CMS\Frontend\Page\PageRepository) {
			if ($this->environmentService->isEnvironmentInFrontendMode() && is_object($GLOBALS['TSFE'])) {
				$this->pageRepository = $GLOBALS['TSFE']->sys_page;
			} else {
				$this->pageRepository = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Frontend\\Page\\PageRepository');
			}
		}

		return $this->pageRepository;
	}

	/**
	 * Checks if there are SQL errors in the last query, and if yes, throw an exception.
	 *
	 * @return void
	 * @param string $sql The SQL statement
	 * @throws \TYPO3\CMS\Extbase\Persistence\Generic\Storage\Exception\SqlErrorException
	 */
	protected function checkSqlErrors($sql = '') {
		$error = $this->databaseHandle->sql_error();
		if ($error !== '') {
			$error .= $sql ? ': ' . $sql : '';
			throw new \TYPO3\CMS\Extbase\Persistence\Generic\Storage\Exception\SqlErrorException($error, 1247602160);
		}
	}
}