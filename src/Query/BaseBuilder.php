<?php

namespace Tinderbox\ClickhouseBuilder\Query;

use Closure;
use Illuminate\Support\Collection;
use Tinderbox\Clickhouse\Common\TempTable;
use Tinderbox\ClickhouseBuilder\Exceptions\BuilderException;
use Tinderbox\ClickhouseBuilder\Query\Enums\Format;
use Tinderbox\ClickhouseBuilder\Query\Enums\JoinStrict;
use Tinderbox\ClickhouseBuilder\Query\Enums\JoinType;
use Tinderbox\ClickhouseBuilder\Query\Enums\Operator;
use Tinderbox\ClickhouseBuilder\Query\Enums\OrderDirection;

abstract class BaseBuilder
{
    /**
     * Columns for select.
     *
     * @var Column[]
     */
    public $columns = [];

    /**
     * Table to select from.
     *
     * @var From|null
     */
    public $from = null;

    /**
     * Sample expression.
     *
     * @var float|null
     */
    public $sample;

    /**
     * Join clause.
     *
     * @var JoinClause
     */
    public $join;
    
    /**
     * Array join clause.
     *
     * @var ArrayJoinClause
     */
    public $arrayJoin;

    /**
     * Prewhere statements.
     *
     * @var TwoElementsLogicExpression[]
     */
    public $prewheres = [];

    /**
     * Where statements.
     *
     * @var TwoElementsLogicExpression[]
     */
    public $wheres = [];

    /**
     * Groupings.
     *
     * @var array
     */
    public $groups = [];

    /**
     * Having statements.
     *
     * @var TwoElementsLogicExpression[]
     */
    public $havings = [];

    /**
     * Order statements.
     *
     * @var array
     */
    public $orders = [];

    /**
     * Limit.
     *
     * @var Limit|null
     */
    public $limit;

    /**
     * Limit n by statement.
     *
     * @var Limit|null
     */
    public $limitBy;

    /**
     * Queries to union.
     *
     * @var array
     */
    public $unions = [];

    /**
     * Query format.
     *
     * @var Format|null
     */
    protected $format;

    /**
     * Grammar to build query parts.
     *
     * @var Grammar
     */
    protected $grammar;

    /**
     * Queries which must be run asynchronous.
     *
     * @var array
     */
    public $async = [];
    
    /**
     * Files which should be sent on server to store into temporary table
     *
     * @var array
     */
    protected $files = [];

    protected $withTotal = false;

    /**
     * @return $this
     */
    public function addWithTotal() {
        $this->withTotal = true;
        return $this;
    }

    /**
     * @return bool
     */
    public function getWithTotal() {
        return $this->withTotal;
    }

    /**
     * Set columns for select statement.
     *
     * @param array|mixed $columns
     *
     * @return static|$this
     */
    public function select(...$columns)
    {
        $columns = isset($columns[0]) && is_array($columns[0]) ? $columns[0] : $columns;

        if (empty($columns)) {
            $columns[] = '*';
        }

        $this->columns = $this->processColumns($columns);

        return $this;
    }

    /**
     * Returns query for count total rows without limit
     *
     * @param string $column
     *
     * @return static|$this
     */
    public function getCountQuery($column = '*')
    {
        $without = ['columns' => [], 'limit' => null];

        if (empty($this->groups)) {
            $without['orders'] = [];
        }

        $builder = $this->cloneWithout($without)->select(raw('count('.$column.') as `count`'));

        return $builder;
    }

    /**
     * Clone the query without the given properties.
     *
     * @param  array  $except
     * @return static|$this
     */
    public function cloneWithout(array $except)
    {
        return tap(clone $this, function ($clone) use ($except) {
            foreach ($except as $property => $value) {
                $clone->{$property} = $value;
            }
        });
    }

    /**
     * Add columns to exist select statement.
     *
     * @param array|mixed $columns
     *
     * @return static|$this
     */
    public function addSelect(...$columns)
    {
        $columns = isset($columns[0]) && is_array($columns[0]) ? $columns[0] : $columns;

        if (empty($columns)) {
            $columns[] = '*';
        }

        $this->columns = array_merge($this->columns, $this->processColumns($columns));

        return $this;
    }

    /**
     * @param $column
     * @return static|$this
     */
    public function selectRaw($column) {
        return $this->addSelect(new Expression($column));
    }

    /**
     * @param $column
     * @param null $asColumn
     * @return $this
     */
    public function addSelectSum($column, $asColumn = null) {

        if(is_array($column)) {
            foreach ($column as $nameColumn => $iterateAsColumn) {
                if(is_numeric($nameColumn) && is_string($iterateAsColumn)) {
                    $this->addSelectSum($iterateAsColumn, $iterateAsColumn);
                    continue;
                }

                if(!is_numeric($nameColumn) && is_string($iterateAsColumn)) {
                    $this->addSelectSum($nameColumn, $iterateAsColumn);
                    continue;
                }
            }

            return $this;
        }

        if(!$asColumn) {
            $asColumn = $column;
        }
        return $this->addSelect(function(Column $columnBuilder) use ($column, $asColumn) {
            return $columnBuilder->name($column)->sum()->as($asColumn);
        });
    }

    /**
     * Prepares columns given by user to Column objects.
     *
     * @param array $columns
     * @param bool  $withAliases
     *
     * @return array
     */
    public function processColumns(array $columns, bool $withAliases = true) : array
    {
        $result = [];

        foreach ($columns as $column => $value) {
            if ($value instanceof Closure) {
                $columnName = $column;
                $column = (new Column($this));

                if (!is_int($columnName)) {
                    $column->name($columnName);
                }

                $column = tap($column, $value);

                if ($column->getSubQuery()) {
                    $column->query($column->getSubQuery());
                }
            }

            if ($value instanceof BaseBuilder) {
                $alias = is_string($column) ? $column : null;
                $column = (new Column($this))->query($value);

                if (!is_null($alias) && $withAliases) {
                    $column->as($alias);
                }
            }

            if (is_int($column)) {
                $column = $value;
                $value = null;
            }

            if (!$column instanceof Column) {
                $alias = is_string($value) ? $value : null;

                $column = (new Column($this))->name($column);

                if (!is_null($alias) && $withAliases) {
                    $column->as($alias);
                }
            }

            $result[] = $column;
        }

        return $result;
    }

    /**
     * Sets table to from statement.
     *
     * @param Closure|Builder|string $table
     * @param string                 $alias
     * @param bool                   $isFinal
     *
     * @return static|$this
     */
    public function from($table, string $alias = null, bool $isFinal = null)
    {
        $this->from = new From($this);

        /*
         * If builder instance given, then we assume that from section should contain sub-query
         */
        if ($table instanceof BaseBuilder) {
            $this->from->query($table);
            
            $this->files = array_merge($this->files, $table->getFiles());
        }

        /*
         * If closure given, then we call it and pass From object as argument to
         * set up From object in callback
         */
        if ($table instanceof Closure) {
            $table($this->from);
        }

        /*
         * If given anything that is not builder instance or callback. For example, string,
         * then we assume that table name was given.
         */
        if (!$table instanceof Closure && !$table instanceof BaseBuilder) {
            $this->from->table($table);
        }

        if (!is_null($alias)) {
            $this->from->as($alias);
        }

        if (!is_null($isFinal)) {
            $this->from->final($isFinal);
        }

        /*
         * If subQuery method was executed on From object, then we take subQuery and "execute" it
         */
        if (!is_null($this->from->getSubQuery())) {
            $this->from->query($this->from->getSubQuery());
        }

        return $this;
    }

    /**
     * Alias for from method.
     *
     * @param             $table
     * @param string|null $alias
     * @param bool|null   $isFinal
     *
     * @return static|$this
     */
    public function table($table, string $alias = null, bool $isFinal = null)
    {
        return $this->from($table, $alias, $isFinal);
    }

    /**
     * Set sample expression.
     *
     * @param float $coefficient
     *
     * @return static|$this
     */
    public function sample(float $coefficient)
    {
        $this->sample = $coefficient;

        return $this;
    }

    /**
     * Add queries to union with.
     *
     * @param self|Closure $query
     *
     * @return static|$this
     */
    public function unionAll($query)
    {
        if ($query instanceof Closure) {
            $query = tap($this->newQuery(), $query);
        }

        if ($query instanceof BaseBuilder) {
            $this->unions[] = $query;
        } else {
            throw new \InvalidArgumentException('Argument for unionAll must be closure or builder instance.');
        }

        return $this;
    }

    /**
     * Set alias for table in from statement.
     *
     * @param string $alias
     *
     * @return static|$this
     */
    public function as(string $alias)
    {
        $this->from->as($alias);

        return $this;
    }

    /**
     * As method alias.
     *
     * @param string $alias
     *
     * @return static|$this
     */
    public function alias(string $alias)
    {
        return $this->as($alias);
    }

    /**
     * Sets final option on from statement.
     *
     * @param bool $final
     *
     * @return static|$this
     */
    public function final(bool $final = true)
    {
        $this->from->final($final);

        return $this;
    }
    
    /**
     * Add array join to query.
     *
     * @param string|Expression $arrayIdentifier
     *
     * @return static|$this
     */
    public function arrayJoin($arrayIdentifier)
    {
        $this->arrayJoin = new ArrayJoinClause($this);
        $this->arrayJoin->array($arrayIdentifier);
        
        return $this;
    }

    /**
     * Add join to query.
     *
     * @param string|self|Closure $table  Table to select from, also may be a sub-query
     * @param string|null         $strict All or any
     * @param string|null         $type   Left or inner
     * @param array|null          $using  Columns to use for join
     * @param bool                $global Global distribution for right table
     *
     * @return static|$this
     */
    public function join(
        $table,
        string $strict = null,
        string $type = null,
        array $using = null,
        bool $global = false
    ) {
        $this->join = new JoinClause($this);

        /*
         * If builder instance given, then we assume that sub-query should be used as table in join
         */
        if ($table instanceof BaseBuilder) {
            $this->join->query($table);

            $this->files = array_merge($this->files, $table->getFiles());
        }

        /*
         * If closure given, then we call it and pass From object as argument to
         * set up JoinClause object in callback
         */
        if ($table instanceof Closure) {
            $table($this->join);
        }

        /*
         * If given anything that is not builder instance or callback. For example, string,
         * then we assume that table name was given.
         */
        if (!$table instanceof Closure && !$table instanceof BaseBuilder) {
            $this->join->table($table);
        }

        /*
         * If using was given, then merge it with using given before, in closure
         */
        if (!is_null($using)) {
            $this->join->addUsing($using);
        }

        if (!is_null($strict) && is_null($this->join->getStrict())) {
            $this->join->strict($strict);
        }

        if (!is_null($type) && is_null($this->join->getType())) {
            $this->join->type($type);
        }

        $this->join->distributed($global);

        if (!is_null($this->join->getSubQuery())) {
            $this->join->query($this->join->getSubQuery());
        }

        return $this;
    }

    /**
     * Left join.
     *
     * Alias for join method, but without specified strictness
     *
     * @param string|self|Closure $table
     * @param string|null         $strict
     * @param array|null          $using
     * @param bool                $global
     *
     * @return static|$this
     */
    public function leftJoin($table, string $strict = null, array $using = null, bool $global = false)
    {
        return $this->join($table, $strict ?? JoinStrict::ALL, JoinType::LEFT, $using, $global);
    }

    /**
     * Inner join.
     *
     * Alias for join method, but without specified strictness
     *
     * @param string|self|Closure $table
     * @param string|null         $strict
     * @param array|null          $using
     * @param bool                $global
     *
     * @return static|$this
     */
    public function innerJoin($table, string $strict = null, array $using = null, bool $global = false)
    {
        return $this->join($table, $strict ?? JoinStrict::ALL, JoinType::INNER, $using, $global);
    }

    /**
     * Any left join.
     *
     * Alias for join method, but with specified any strictness
     *
     * @param string|self|Closure $table
     * @param array|null          $using
     * @param bool                $global
     *
     * @return static|$this
     */
    public function anyLeftJoin($table, array $using = null, bool $global = false)
    {
        return $this->join($table, JoinStrict::ANY, JoinType::LEFT, $using, $global);
    }

    /**
     * All left join.
     *
     * Alias for join method, but with specified all strictness.
     *
     * @param string|self|Closure $table
     * @param array|null          $using
     * @param bool                $global
     *
     * @return static|$this
     */
    public function allLeftJoin($table, array $using = null, bool $global = false)
    {
        return $this->join($table, JoinStrict::ALL, JoinType::LEFT, $using, $global);
    }

    /**
     * Any inner join.
     *
     * Alias for join method, but with specified any strictness.
     *
     * @param string|self|Closure $table
     * @param array|null          $using
     * @param bool                $global
     *
     * @return static|$this
     */
    public function anyInnerJoin($table, array $using = null, bool $global = false)
    {
        return $this->join($table, JoinStrict::ANY, JoinType::INNER, $using, $global);
    }

    /**
     * All inner join.
     *
     * Alias for join method, but with specified all strictness.
     *
     * @param string|self|Closure $table
     * @param array|null          $using
     * @param bool                $global
     *
     * @return static|$this
     */
    public function allInnerJoin($table, array $using = null, bool $global = false)
    {
        return $this->join($table, JoinStrict::ALL, JoinType::INNER, $using, $global);
    }

    /**
     * Get two elements logic expression to put it in the right place.
     *
     *
     * Used in where, prewhere and having methods.
     *
     * @param TwoElementsLogicExpression|string|Closure|self $column
     * @param mixed                                          $operator
     * @param mixed                                          $value
     * @param string                                         $concatOperator
     * @param string                                         $section
     *
     * @return TwoElementsLogicExpression
     */
    public function assembleTwoElementsLogicExpression(
        $column,
        $operator,
        $value,
        string $concatOperator,
        string $section
    ) : TwoElementsLogicExpression {
        $expression = new TwoElementsLogicExpression($this);

        /*
         * If user passed TwoElementsLogicExpression as first argument, then we assume that user has set up himself.
         */
        if ($column instanceof TwoElementsLogicExpression && is_null($value)) {
            return $column;
        }

        if ($column instanceof TwoElementsLogicExpression && $value instanceof TwoElementsLogicExpression) {
            $expression->firstElement($column);
            $expression->secondElement($value);
            $expression->operator($operator);
            $expression->concatOperator($concatOperator);

            return $expression;
        }

        /*
         * If closure, then we pass fresh query builder inside and based on their state after evaluating try to assume
         * what user expects to perform.
         * If resulting query builder have elements corresponding to requested section, then we assume that user wanted
         * to just wrap this in parenthesis, otherwise - subquery.
         */
        if ($column instanceof Closure) {
            $query = tap($this->newQuery(), $column);

            if (is_null($query->getFrom()) && empty($query->getColumns())) {
                $expression->firstElement($query->{"get{$section}"}());
            } else {
                $expression->firstElement(new Expression("({$query->toSql()})"));
            }
        }

        /*
         * If as column was passed builder instance, than we perform subquery in first element position.
         */
        if ($column instanceof BaseBuilder) {
            $expression->firstElementQuery($column);
        }

        /*
         * If builder instance given as value, then we assume that sub-query should be used there.
         */
        if ($value instanceof BaseBuilder || $value instanceof Closure) {
            $expression->secondElementQuery($value);
        }

        /*
         * Set up other parameters if none of them was set up before in TwoElementsLogicExpression object
         */
        if (is_null($expression->getFirstElement()) && !is_null($column)) {
            $expression->firstElement(is_string($column) ? new Identifier($column) : $column);
        }

        if (is_null($expression->getSecondElement()) && !is_null($value)) {
            if (is_array($value) && count($value) === 2 && Operator::isValid($operator) && in_array(
                    $operator,
                    [Operator::BETWEEN, Operator::NOT_BETWEEN]
                )
            ) {
                $value = (new TwoElementsLogicExpression($this))
                    ->firstElement($value[0])
                    ->operator(Operator::AND)
                    ->secondElement($value[1])
                    ->concatOperator($concatOperator);
            }

            if (is_array($value) && Operator::isValid($operator) && in_array(
                    $operator,
                    [Operator::IN, Operator::NOT_IN]
                )
            ) {
                $value = new Tuple($value);
            }

            $expression->secondElement($value);
        }

        $expression->concatOperator($concatOperator);

        if (is_string($operator)) {
            $expression->operator($operator);
        }

        return $expression;
    }

    /**
     * Prepare operator for where and prewhere statement.
     *
     * @param mixed  $value
     * @param string $operator
     * @param bool   $useDefault
     *
     * @return array
     */
    public function prepareValueAndOperator($value, $operator, $useDefault = false) : array
    {
        if ($useDefault) {
            $value = $operator;

            if (is_array($value)) {
                $operator = Operator::IN;
            } else {
                $operator = Operator::EQUALS;
            }

            return [$value, $operator];
        }

        return [$value, $operator];
    }

    /**
     * Add prewhere statement.
     *
     * @param TwoElementsLogicExpression|self|Closure|string      $column
     * @param mixed                                               $operator
     * @param TwoElementsLogicExpression|self|Closure|string|null $value
     * @param string                                              $concatOperator
     *
     * @return static|$this
     */
    public function preWhere($column, $operator = null, $value = null, string $concatOperator = Operator::AND)
    {
        list($value, $operator) = $this->prepareValueAndOperator($value, $operator, func_num_args() == 2);

        $this->prewheres[] = $this->assembleTwoElementsLogicExpression(
            $column,
            $operator,
            $value,
            $concatOperator,
            'prewheres'
        );

        return $this;
    }

    /**
     * Add prewhere statement "as is".
     *
     * @param string $expression
     *
     * @return static|$this
     */
    public function preWhereRaw(string $expression)
    {
        return $this->preWhere(new Expression($expression));
    }

    /**
     * Add prewhere statement "as is", but with OR operator.
     *
     * @param string $expression
     *
     * @return static|$this
     */
    public function orPreWhereRaw(string $expression)
    {
        return $this->preWhere(new Expression($expression), null, null, Operator::OR);
    }

    /**
     * Add prewhere statement but with OR operator.
     *
     * @param      $column
     * @param null $operator
     * @param null $value
     *
     * @return static|$this
     */
    public function orPreWhere($column, $operator = null, $value = null)
    {
        list($value, $operator) = $this->prepareValueAndOperator($value, $operator, func_num_args() == 2);

        return $this->prewhere($column, $operator, $value, Operator::OR);
    }

    /**
     * Add prewhere statement with IN operator.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     * @param bool   $not
     *
     * @return static|$this
     */
    public function preWhereIn($column, $values, $boolean = Operator::AND, $not = false)
    {
        $type = $not ? Operator::NOT_IN : Operator::IN;

        if (is_array($values)) {
            $values = new Tuple($values);
        } elseif (is_string($values) && isset($this->files[$values])) {
            $values = new Identifier($values);
        }

        return $this->preWhere($column, $type, $values, $boolean);
    }

    /**
     * Add prewhere statement with IN operator and OR operator.
     *
     * @param $column
     * @param $values
     *
     * @return static|$this
     */
    public function orPreWhereIn($column, $values)
    {
        return $this->preWhereIn($column, $values, Operator::OR);
    }

    /**
     * Add prewhere statement with NOT IN operator.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     *
     * @return static|$this
     */
    public function preWhereNotIn($column, $values, $boolean = Operator::AND)
    {
        return $this->preWhereIn($column, $values, $boolean, true);
    }

    /**
     * Add prewhere statement with NOT IN operator and OR operator.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     *
     * @return static|$this
     */
    public function orPreWhereNotIn($column, $values, $boolean = Operator::OR)
    {
        return $this->preWhereNotIn($column, $values, $boolean);
    }

    /**
     * Add prewhere statement with BETWEEN simulation.
     *
     * @param        $column
     * @param array  $values
     * @param string $boolean
     * @param bool   $not
     *
     * @return static|$this
     */
    public function preWhereBetween($column, array $values, $boolean = Operator::AND, $not = false)
    {
        $type = $not ? Operator::NOT_BETWEEN : Operator::BETWEEN;

        return $this->preWhere($column, $type, [$values[0], $values[1]], $boolean);
    }

    /**
     * Add prewhere statement with BETWEEN simulation, but with column names as value.
     *
     * @param        $column
     * @param array  $values
     * @param string $boolean
     * @param bool   $not
     *
     * @return static|$this
     */
    public function preWhereBetweenColumns($column, array $values, $boolean = Operator::AND, $not = false)
    {
        $type = $not ? Operator::NOT_BETWEEN : Operator::BETWEEN;

        return $this->preWhere($column, $type, [new Identifier($values[0]), new Identifier($values[1])], $boolean);
    }

    /**
     * Add prewhere statement with NOT BETWEEN simulation, but with column names as value.
     *
     * @param        $column
     * @param array  $values
     * @param string $boolean
     *
     * @return static|$this
     */
    public function preWhereNotBetweenColumns($column, array $values, $boolean = Operator::AND)
    {
        return $this->preWhere($column, Operator::NOT_BETWEEN, [new Identifier($values[0]), new Identifier($values[1])], $boolean);
    }

    /**
     * Add prewhere statement with BETWEEN simulation, but with column names as value and OR operator.
     *
     * @param       $column
     * @param array $values
     *
     * @return static|$this
     */
    public function orPreWhereBetweenColumns($column, array $values)
    {
        return $this->preWhereBetweenColumns($column, $values, Operator::OR);
    }

    /**
     * Add prewhere statement with NOT BETWEEN simulation, but with column names as value and OR operator.
     *
     * @param       $column
     * @param array $values
     *
     * @return static|$this
     */
    public function orPreWhereNotBetweenColumns($column, array $values)
    {
        return $this->preWhereNotBetweenColumns($column, $values, Operator::OR);
    }

    /**
     * Add prewhere statement with BETWEEN simulation and OR operator.
     *
     * @param       $column
     * @param array $values
     *
     * @return static|$this
     */
    public function orPreWhereBetween($column, array $values)
    {
        return $this->preWhereBetween($column, $values, Operator::OR);
    }

    /**
     * Add prewhere statement with NOT BETWEEN simulation.
     *
     * @param        $column
     * @param array  $values
     * @param string $boolean
     *
     * @return static|$this
     */
    public function preWhereNotBetween($column, array $values, $boolean = Operator::AND)
    {
        return $this->preWhereBetween($column, $values, $boolean, true);
    }

    /**
     * Add prewhere statement with NOT BETWEEN simulation and OR operator.
     *
     * @param       $column
     * @param array $values
     *
     * @return static|$this
     */
    public function orPreWhereNotBetween($column, array $values)
    {
        return $this->preWhereNotBetween($column, $values, Operator::OR);
    }

    /**
     * Add where statement.
     *
     * @param TwoElementsLogicExpression|string|Closure|self $column
     * @param mixed                                          $operator
     * @param mixed                                          $value
     * @param string                                         $concatOperator
     *
     * @return static|$this
     */
    public function where($column, $operator = null, $value = null, string $concatOperator = Operator::AND)
    {
        list($value, $operator) = $this->prepareValueAndOperator($value, $operator, func_num_args() == 2);

        $this->wheres[] = $this->assembleTwoElementsLogicExpression(
            $column,
            $operator,
            $value,
            $concatOperator,
            'wheres'
        );

        return $this;
    }

    /**
     * @return $this
     */
    public function resetWhere() {
        $this->wheres = [];
        return $this;
    }

    /**
     * @return $this
     */
    public function resetHaving() {
        $this->havings = [];
        return $this;
    }

    /**
     * @return $this
     */
    public function resetOrders() {
        $this->orders = [];
        return $this;
    }

    /**
     * @return $this
     */
    public function resetGroup() {
        $this->groups = [];
        return $this;
    }


    public function shiftSelect() {
        array_shift($this->columns);
        return $this;
    }

    /**
     * Add where statement "as is".
     *
     * @param string $expression
     *
     * @return static|$this
     */
    public function whereRaw(string $expression)
    {
        return $this->where(new Expression($expression));
    }

    public function whereLike($column, $value = null) {
        list($value, $operator) = $this->prepareValueAndOperator('', $value, func_num_args() == 2);

        $value = preg_replace('#(\"|\'|`)#', null, $value);

        return $this->whereRaw("like(`{$column}`, '%{$value}%')");
    }

    /**
     * Add where statement "as is" with OR operator.
     *
     * @param string $expression
     *
     * @return static|$this
     */
    public function orWhereRaw(string $expression)
    {
        return $this->where(new Expression($expression), null, null, Operator::OR);
    }

    /**
     * Add where statement with OR operator.
     *
     * @param      $column
     * @param null $operator
     * @param null $value
     *
     * @return static|$this
     */
    public function orWhere($column, $operator = null, $value = null)
    {
        list($value, $operator) = $this->prepareValueAndOperator($value, $operator, func_num_args() == 2);

        return $this->where($column, $operator, $value, Operator::OR);
    }

    /**
     * Add where statement with IN operator.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     * @param bool   $not
     *
     * @return static|$this
     */
    public function whereIn($column, $values, $boolean = Operator::AND, $not = false)
    {
        $type = $not ? Operator::NOT_IN : Operator::IN;

        if (is_array($values)) {
            $values = new Tuple($values);
        } elseif ($values instanceof Collection) {
            $values = new Tuple($values->toArray());
        } elseif (is_string($values) && isset($this->files[$values])) {
            $values = new Identifier($values);
        }

        return $this->where($column, $type, $values, $boolean);
    }

    /**
     * Add where statement with GLOBAL option and IN operator.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     * @param bool   $not
     *
     * @return static|$this
     */
    public function whereGlobalIn($column, $values, $boolean = Operator::AND, $not = false)
    {
        $type = $not ? Operator::GLOBAL_NOT_IN : Operator::GLOBAL_IN;

        if (is_array($values)) {
            $values = new Tuple($values);
        } elseif (is_string($values) && isset($this->files[$values])) {
            $values = new Identifier($values);
        }

        return $this->where($column, $type, $values, $boolean);
    }

    /**
     * Add where statement with GLOBAL option and IN operator and OR operator.
     *
     * @param $column
     * @param $values
     *
     * @return static|$this
     */
    public function orWhereGlobalIn($column, $values)
    {
        return $this->whereGlobalIn($column, $values, Operator::OR);
    }

    /**
     * Add where statement with GLOBAL option and NOT IN operator.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     *
     * @return static|$this
     */
    public function whereGlobalNotIn($column, $values, $boolean = Operator::AND)
    {
        return $this->whereGlobalIn($column, $values, $boolean, true);
    }

    /**
     * Add where statement with GLOBAL option and NOT IN operator and OR operator.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     *
     * @return static|$this
     */
    public function orWhereGlobalNotIn($column, $values, $boolean = Operator::OR)
    {
        return $this->whereGlobalNotIn($column, $values, $boolean);
    }

    /**
     * Add where statement with IN operator and OR operator.
     *
     * @param $column
     * @param $values
     *
     * @return static|$this
     */
    public function orWhereIn($column, $values)
    {
        return $this->whereIn($column, $values, Operator::OR);
    }

    /**
     * Add where statement with NOT IN operator.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     *
     * @return static|$this
     */
    public function whereNotIn($column, $values, $boolean = Operator::AND)
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    /**
     * Add where statement with NOT IN operator and OR operator.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     *
     * @return static|$this
     */
    public function orWhereNotIn($column, $values, $boolean = Operator::OR)
    {
        return $this->whereNotIn($column, $values, $boolean);
    }

    /**
     * Add where statement with BETWEEN simulation.
     *
     * @param        $column
     * @param array  $values
     * @param string $boolean
     * @param bool   $not
     *
     * @return static|$this
     */
    public function whereBetween($column, array $values, $boolean = Operator::AND, $not = false)
    {
        $operator = $not ? Operator::NOT_BETWEEN : Operator::BETWEEN;

        return $this->where($column, $operator, [$values[0], $values[1]], $boolean);
    }

    /**
     * Add where statement with BETWEEN simulation, but with column names as value.
     *
     * @param        $column
     * @param array  $values
     * @param string $boolean
     * @param bool   $not
     *
     * @return static|$this
     */
    public function whereBetweenColumns($column, array $values, $boolean = Operator::AND, $not = false)
    {
        $type = $not ? Operator::NOT_BETWEEN : Operator::BETWEEN;

        return $this->where($column, $type, [new Identifier($values[0]), new Identifier($values[1])], $boolean);
    }

    /**
     * Add where statement with BETWEEN simulation, but with column names as value and OR operator.
     *
     * @param       $column
     * @param array $values
     *
     * @return static|$this
     */
    public function orWhereBetweenColumns($column, array $values)
    {
        return $this->whereBetweenColumns($column, $values, Operator::OR);
    }

    /**
     * Add where statement with BETWEEN simulation and OR operator.
     *
     * @param       $column
     * @param array $values
     *
     * @return static|$this
     */
    public function orWhereBetween($column, array $values)
    {
        return $this->whereBetween($column, $values, Operator::OR);
    }

    /**
     * Add where statement with NOT BETWEEN simulation.
     *
     * @param        $column
     * @param array  $values
     * @param string $boolean
     *
     * @return static|$this
     */
    public function whereNotBetween($column, array $values, $boolean = Operator::AND)
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    /**
     * Add prewhere statement with NOT BETWEEN simulation and OR operator.
     *
     * @param       $column
     * @param array $values
     *
     * @return static|$this
     */
    public function orWhereNotBetween($column, array $values)
    {
        return $this->whereNotBetween($column, $values, Operator::OR);
    }

    /**
     * Add having statement.
     *
     * @param TwoElementsLogicExpression|string|Closure|self $column
     * @param mixed                                          $operator
     * @param mixed                                          $value
     * @param string                                         $concatOperator
     *
     * @return static|$this
     */
    public function having($column, $operator = null, $value = null, string $concatOperator = Operator::AND)
    {
        list($value, $operator) = $this->prepareValueAndOperator($value, $operator, func_num_args() == 2);

        $this->havings[] = $this->assembleTwoElementsLogicExpression($column, $operator, $value, $concatOperator, 'havings');

        return $this;
    }

    /**
     * Add having statement "as is".
     *
     * @param string $expression
     *
     * @return static|$this
     */
    public function havingRaw(string $expression)
    {
        return $this->having(new Expression($expression));
    }

    /**
     * Add having statement "as is" with OR operator.
     *
     * @param string $expression
     *
     * @return static|$this
     */
    public function orHavingRaw(string $expression)
    {
        return $this->having(new Expression($expression), null, null, Operator::OR);
    }

    /**
     * Add having statement with OR operator.
     *
     * @param      $column
     * @param null $operator
     * @param null $value
     *
     * @return static|$this
     */
    public function orHaving($column, $operator = null, $value = null)
    {
        list($value, $operator) = $this->prepareValueAndOperator($value, $operator, func_num_args() == 2);

        return $this->having($column, $operator, $value, Operator::OR);
    }

    /**
     * Add having statement with IN operator.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     * @param bool   $not
     *
     * @return static|$this
     */
    public function havingIn($column, $values, $boolean = Operator::AND, $not = false)
    {
        $type = $not ? Operator::NOT_IN : Operator::IN;

        if (is_array($values)) {
            $values = new Tuple($values);
        } elseif (is_string($values) && isset($this->files[$values])) {
            $values = new Identifier($values);
        }

        return $this->having($column, $type, $values, $boolean);
    }

    /**
     * Add having statement with IN operator and OR operator.
     *
     * @param $column
     * @param $values
     *
     * @return static|$this
     */
    public function orHavingIn($column, $values)
    {
        return $this->havingIn($column, $values, Operator::OR);
    }

    /**
     * Add having statement with NOT IN operator.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     *
     * @return static|$this
     */
    public function havingNotIn($column, $values, $boolean = Operator::AND)
    {
        return $this->havingIn($column, $values, $boolean, true);
    }

    /**
     * Add having statement with NOT IN operator and OR operator.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     *
     * @return static|$this
     */
    public function orHavingNotIn($column, $values, $boolean = Operator::OR)
    {
        return $this->havingNotIn($column, $values, $boolean);
    }

    /**
     * Add having statement with BETWEEN simulation.
     *
     * @param        $column
     * @param array  $values
     * @param string $boolean
     * @param bool   $not
     *
     * @return static|$this
     */
    public function havingBetween($column, array $values, $boolean = Operator::AND, $not = false)
    {
        $operator = $not ? Operator::NOT_BETWEEN : Operator::BETWEEN;

        return $this->having($column, $operator, [$values[0], $values[1]], $boolean);
    }

    /**
     * Add having statement with BETWEEN simulation, but with column names as value.
     *
     * @param        $column
     * @param array  $values
     * @param string $boolean
     * @param bool   $not
     *
     * @return static|$this
     */
    public function havingBetweenColumns($column, array $values, $boolean = Operator::AND, $not = false)
    {
        $type = $not ? Operator::NOT_BETWEEN : Operator::BETWEEN;

        return $this->having($column, $type, [new Identifier($values[0]), new Identifier($values[1])], $boolean);
    }

    /**
     * Add having statement with BETWEEN simulation, but with column names as value and OR operator.
     *
     * @param       $column
     * @param array $values
     *
     * @return static|$this
     */
    public function orHavingBetweenColumns($column, array $values)
    {
        return $this->havingBetweenColumns($column, $values, Operator::OR);
    }

    /**
     * Add having statement with BETWEEN simulation and OR operator.
     *
     * @param       $column
     * @param array $values
     *
     * @return static|$this
     */
    public function orHavingBetween($column, array $values)
    {
        return $this->havingBetween($column, $values, Operator::OR);
    }

    /**
     * Add having statement with NOT BETWEEN simulation.
     *
     * @param        $column
     * @param array  $values
     * @param string $boolean
     *
     * @return static|$this
     */
    public function havingNotBetween($column, array $values, $boolean = Operator::AND)
    {
        return $this->havingBetween($column, $values, $boolean, true);
    }

    /**
     * Add having statement with NOT BETWEEN simulation and OR operator.
     *
     * @param       $column
     * @param array $values
     *
     * @return static|$this
     */
    public function orHavingNotBetween($column, array $values)
    {
        return $this->havingNotBetween($column, $values, Operator::OR);
    }

    /**
     * Add dictionary value to select statement.
     *
     * @param string       $dict
     * @param string       $attribute
     * @param array|string $key
     * @param string       $as
     *
     * @return static|$this
     */
    public function addSelectDict(string $dict, string $attribute, $key, string $as = null)
    {
        if (is_null($as)) {
            $as = $attribute;
        }

        $id = is_array($key) ? 'tuple('.implode(', ', array_map([$this->grammar, 'wrap'], $key)).')' : $this->grammar->wrap($key);

        return $this
            ->addSelect(new Expression("dictGetString('{$dict}', '{$attribute}', {$id}) as `{$as}`"));
    }

    /**
     * Add where on dictionary value in where statement.
     *
     * @param              $dict
     * @param              $attribute
     * @param array|string $key
     * @param              $operator
     * @param              $value
     * @param string       $concatOperator
     *
     * @return static|$this
     */
    public function whereDict(
        string $dict,
        string $attribute,
        $key,
        $operator = null,
        $value = null,
        string $concatOperator = Operator::AND
    ) {
        $this->addSelectDict($dict, $attribute, $key);

        list($value, $operator) = $this->prepareValueAndOperator($value, $operator, func_num_args() == 4);

        return $this->where($attribute, $operator, $value, $concatOperator);
    }

    /**
     * Add where on dictionary value in where statement and OR operator.
     *
     * @param $dict
     * @param $attribute
     * @param $key
     * @param $operator
     * @param $value
     *
     * @return static|$this
     */
    public function orWhereDict(
        string $dict,
        string $attribute,
        $key,
        $operator = null,
        $value = null
    ) {
        list($value, $operator) = $this->prepareValueAndOperator($value, $operator, func_num_args() == 4);

        return $this->whereDict($dict, $attribute, $key, $operator, $value, Operator::OR);
    }

    /**
     * Add request which must be runned asynchronous.
     *
     * @param Closure|self|null $asyncQueries
     *
     * @return static|$this
     */
    public function asyncWithQuery($asyncQueries = null)
    {
        if (is_null($asyncQueries)) {
            return $this->async[] = $this->newQuery();
        }

        if ($asyncQueries instanceof Closure) {
            $asyncQueries = tap($this->newQuery(), $asyncQueries);
        }

        if ($asyncQueries instanceof BaseBuilder) {
            $this->async[] = $asyncQueries;
        } else {
            throw new \InvalidArgumentException('Argument for async method must be Closure, Builder or nothing');
        }

        return $this;
    }

    /**
     * Add limit statement.
     *
     * @param int      $limit
     * @param int|null $offset
     *
     * @return static|$this
     */
    public function limit(int $limit, int $offset = null)
    {
        $this->limit = new Limit($limit, $offset);

        return $this;
    }

    public function resetLimit() {
        $this->limit = null;
        return $this;
    }

    /**
     * Add limit n by statement.
     *
     * @param int   $count
     * @param array ...$columns
     *
     * @return static|$this
     */
    public function limitBy(int $count, ...$columns)
    {
        $columns = isset($columns[0]) && is_array($columns[0]) ? $columns[0] : $columns;

        $this->limitBy = new Limit($count, null, $this->processColumns($columns, false));

        return $this;
    }

    /**
     * Alias for limit method.
     *
     * @param int      $limit
     * @param int|null $offset
     *
     * @return static|$this
     */
    public function take(int $limit, int $offset = null)
    {
        return $this->limit($limit, $offset);
    }

    /**
     * Alias for limitBy method.
     *
     * @param int   $count
     * @param array ...$columns
     *
     * @return static|$this
     */
    public function takeBy(int $count, ...$columns)
    {
        return $this->limitBy($count, ...$columns);
    }

    /**
     * Add group by statement.
     *
     * @param $columns
     *
     * @return static|$this
     */
    public function groupBy(...$columns)
    {
        $columns = isset($columns[0]) && is_array($columns[0]) ? $columns[0] : $columns;

        if (empty($columns)) {
            $columns[] = '*';
        }

        $this->groups = $this->processColumns($columns, false);

        return $this;
    }

    /**
     * Add group by statement to exist group statements
     *
     * @param $columns
     *
     * @return static|$this
     */
    public function addGroupBy(...$columns)
    {
        $columns = isset($columns[0]) && is_array($columns[0]) ? $columns[0] : $columns;

        if (empty($columns)) {
            $columns[] = '*';
        }

        $this->groups = array_merge($this->groups, $this->processColumns($columns, false));

        return $this;
    }

    public function clearGroupBy() {
        $this->groups = [];
        return $this;
    }

    public function clearOrderBy() {
        $this->orders = [];
        return $this;
    }

    /**
     * Add order by statement.
     *
     * @param string|Closure $column
     * @param string         $direction
     * @param string|null    $collate
     *
     * @return static|$this
     */
    public function orderBy($column, string $direction = 'asc', string $collate = null)
    {
        $column = $this->processColumns([$column], false)[0];

        $direction = new OrderDirection(strtoupper($direction));

        $this->orders[] = [$column, $direction, $collate];

        return $this;
    }

    /**
     * Add order by statement "as is".
     *
     * @param string $expression
     *
     * @return static|$this
     */
    public function orderByRaw(string $expression)
    {
        $column = $this->processColumns([new Expression($expression)], false)[0];
        $this->orders[] = [$column, null, null];

        return $this;
    }

    /**
     * Add ASC order statement.
     *
     * @param             $column
     * @param string|null $collate
     *
     * @return static|$this
     */
    public function orderByAsc($column, string $collate = null)
    {
        return $this->orderBy($column, OrderDirection::ASC, $collate);
    }

    /**
     * Add DESC order statement.
     *
     * @param             $column
     * @param string|null $collate
     *
     * @return static|$this
     */
    public function orderByDesc($column, string $collate = null)
    {
        return $this->orderBy($column, OrderDirection::DESC, $collate);
    }

    /**
     * Set query result format.
     *
     * @param string $format
     *
     * @return static|$this
     */
    public function format(string $format)
    {
        $this->format = new Format(strtoupper($format));

        return $this;
    }

    /**
     * Get the SQL representation of the query.
     *
     * @return string
     */
    public function toSql() : string
    {
        return $this->grammar->compileSelect($this);
    }

    /**
     * Get an array of the SQL queries from all added async builders.
     *
     * @return array
     */
    public function toAsyncSqls() : array
    {
        return array_map(function ($query) {
            return [$query->toSql(), [], $query->getFiles()];
        }, $this->getAsyncQueries());
    }

    /**
     * Get columns for select statement.
     *
     * @return array
     */
    public function getColumns() : array
    {
        return $this->columns;
    }

    /**
     * Get order statements.
     *
     * @return array
     */
    public function getOrders() : array
    {
        return $this->orders;
    }

    /**
     * Get group statements.
     *
     * @return array
     */
    public function getGroups() : array
    {
        return $this->groups;
    }

    /**
     * Get having statements.
     *
     * @return array
     */
    public function getHavings() : array
    {
        return $this->havings;
    }

    /**
     * Get prewhere statements.
     *
     * @return array
     */
    public function getPreWheres() : array
    {
        return $this->prewheres;
    }

    /**
     * Get where statements.
     *
     * @return array
     */
    public function getWheres() : array
    {
        return $this->wheres;
    }

    /**
     * Get From object.
     *
     * @return From|null
     */
    public function getFrom() : ?From
    {
        return $this->from;
    }
    
    /**
     * Get ArrayJoinClause
     *
     * @return null|ArrayJoinClause
     */
    public function getArrayJoin() : ?ArrayJoinClause
    {
        return $this->arrayJoin;
    }

    /**
     * Get JoinClause.
     *
     * @return JoinClause
     */
    public function getJoin() : ?JoinClause
    {
        return $this->join;
    }

    /**
     * Get limit statement.
     *
     * @return Limit
     */
    public function getLimit() : ?Limit
    {
        return $this->limit;
    }

    /**
     * Get limit by statement.
     *
     * @return Limit
     */
    public function getLimitBy() : ?Limit
    {
        return $this->limitBy;
    }

    /**
     * Get sample statement.
     *
     * @return float|null
     */
    public function getSample() : ?float
    {
        return $this->sample;
    }

    /**
     * Get query unions.
     *
     * @return array
     */
    public function getUnions() : array
    {
        return $this->unions;
    }

    /**
     * Get format.
     *
     * @return null|Format
     */
    public function getFormat() : ?Format
    {
        return $this->format;
    }
    
    /**
     * Add file which should be sent on server
     *
     * @param string      $filePath
     * @param string      $tableName
     * @param array       $structure
     * @param string|null $format
     *
     * @return static|$this
     *
     * @throws BuilderException
     */
    public function addFile(string $filePath, string $tableName, array $structure, string $format = Format::CSV)
    {
        if (isset($this->files[$tableName])) {
            throw BuilderException::temporaryTableAlreadyExists($tableName);
        }
        
        $this->files[$tableName] = new TempTable($tableName, $filePath, $structure, $format);
        
        return $this;
    }
    
    /**
     * Returns files which should be sent on server
     *
     * @return array
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * Gather all builders from builder. Including nested in async builders.
     *
     * @return array
     */
    public function getAsyncQueries() : array
    {
        $result = [];

        foreach ($this->async as $query) {
            $result = array_merge($query->getAsyncQueries(), $result);
        }

        return array_merge([$this], $result);
    }
}
