<?php
namespace Lib;

/**
 * 数据库连接类，依赖mysql_pdo扩展
 * 在https://github.com/auraphp/Aura.SqlQuery的基础上修改而成
 */
class DbConnection 
{
    /**
     * SELECT
     * @var array
     */
    protected $union = array();
    
    /**
     * 是否是更新
     * @var bool
     */
    protected $for_update = false;
    
    /**
     * 选择的列
     * @var array
     */
    protected $cols = array();
    
    /**
     * 从哪些表里面SELECT
     * @var array
     */
    protected $from = array();
    
    /**
     * $from 当前的 key
     * @var int
     */
    protected $from_key = -1;
    
    /**
     * GROUP BY 的列 
     * @var array
     */
    protected $group_by = array();
    
    /**
     * HAVING 条件数组.
     * @var array
     */
    protected $having = array();
    
    /**
     * HAVING 语句中绑定的值.
     * @var array
     */
    protected $bind_having = array();
    
    /**
     * 每页多少条记录
     * @var int
     */
    protected $paging = 10;
    
    /**
     * sql中绑定的值
     * @var array
     */
    protected $bind_values = array();
    
    /**
     * WHERE 条件.
     * @var array
     */
    protected $where = array();
    
    /**
     * WHERE语句绑定的值
     * @var array
     */
    protected $bind_where = array();
    
    /**
     * ORDER BY 的列
     * @var array
     */
    protected $order_by = array();
    
    /**
     * SELECT多少记录
     * @var int
     */
    protected $limit = 0;
    
    /**
     * 返回记录的游标
     * @var int
     */
    protected $offset = 0;
    
    /**
     * flags 列表
     * @var array
     */
    protected $flags = array();
    
    /**
     * 操作哪个表
     * @var string
     */
    protected $table;
   
    /**
     * 表.列 和 last-insert-id 映射
     * @var array
     */
    protected $last_insert_id_names = array();
    
    /**
     * INSERT 或者 UPDATE 的列 
     * @param array
     */
    protected $col_values;
    
    /**
     * 返回的列
     * @var array
     */
    protected $returning = array();
    
    /**
     * sql的类型 SELECT INSERT DELETE UPDATE
     * @var string
     */
    protected $type = '';
    
    /**
     * pdo 实例
     * @var pdo
     */
    protected $pdo;
    
    /**
     * PDO statement 实例
     * @var PDO statement
     */
    protected $sQuery;
    
    /**
     * 数据库用户名密码等配置
     * @var array
     */
    protected $settings = array();
    
    /**
     * sql的参数
     * @var array
     */
    protected $parameters = array();
    
    /**
     * 最后一条直行的sql
     * @var string
     */
    protected $lastSql = '';

    /**
     * 选择哪些列
     * @param string/array $cols
     */
    public function select($cols = '*')
    {
        $this->type = 'SELECT';
        if(!is_array($cols))
        {
            $cols = array($cols);
        }
        $this->cols($cols);
        return $this;
    }
    
    /**
     * 从哪个表删除
     * @param string $table
     * @return self
     */
    public function delete($table)
    {
        $this->type = 'DELETE';
        $this->table = $this->quoteName($table);
        $this->fromRaw($this->quoteName($table));
        return $this;
    }

    /**
     * 更新哪个表
     * @param string $table
     */
    public function update($table)
    {
        $this->type = 'UPDATE';
        $this->table = $this->quoteName($table);
        return $this;
    }

    /**
     * 向哪个表插入
     * @param string $table
     */
    public function insert($table)
    {
        $this->type = 'INSERT';
        $this->table = $this->quoteName($table);
        return $this;
    }
    
    /**
     *
     * 设置 SQL_CALC_FOUND_ROWS 标记.
     * @param bool 
     * @return self
     */
    public function calcFoundRows($enable = true)
    {
        $this->setFlag('SQL_CALC_FOUND_ROWS', $enable);
        return $this;
    }

    /**
     * 设置 SQL_CACHE 标记
     * @param bool 
     * @return self
     */
    public function cache($enable = true)
    {
        $this->setFlag('SQL_CACHE', $enable);
        return $this;
    }

    /**
     * 设置 SQL_NO_CACHE 标记
     * @param bool
     * @return self
     */
    public function noCache($enable = true)
    {
        $this->setFlag('SQL_NO_CACHE', $enable);
        return $this;
    }

    /**
     * 设置 STRAIGHT_JOIN 标记.
     * @param bool
     * @return self
     */
    public function straightJoin($enable = true)
    {
        $this->setFlag('STRAIGHT_JOIN', $enable);
        return $this;
    }

    /**
     * 设置 HIGH_PRIORITY 标记
     * @param bool 
     * @return self
     */
    public function highPriority($enable = true)
    {
        $this->setFlag('HIGH_PRIORITY', $enable);
        return $this;
    }

    /**
     * 设置 SQL_SMALL_RESULT 标记
     * @param bool
     * @return self
     */
    public function smallResult($enable = true)
    {
        $this->setFlag('SQL_SMALL_RESULT', $enable);
        return $this;
    }

    /**
     * 设置 SQL_BIG_RESULT 标记
     * @param bool 
     * @return self
     */
    public function bigResult($enable = true)
    {
        $this->setFlag('SQL_BIG_RESULT', $enable);
        return $this;
    }

    /**
     * 设置 SQL_BUFFER_RESULT 标记
     * @param bool 
     * @return self
     */
    public function bufferResult($enable = true)
    {
        $this->setFlag('SQL_BUFFER_RESULT', $enable);
        return $this;
    }
    
    /**
     * 设置 FOR UPDATE 标记
     * @param bool
     * @return self
     */
    public function forUpdate($enable = true)
    {
        $this->for_update = (bool) $enable;
        return $this;
    }
    
    /**
     * 设置 DISTINCT 标记
     * @param bool
     * @return self
     */
    public function distinct($enable = true)
    {
        $this->setFlag('DISTINCT', $enable);
        return $this;
    }
    
    /**
     * 设置 LOW_PRIORITY 标记
     * @param bool $enable
     * @return self
     */
    public function lowPriority($enable = true)
    {
        $this->setFlag('LOW_PRIORITY', $enable);
        return $this;
    }
    
    /**
     * 设置 IGNORE 标记
     * @param bool $enable
     * @return self
     */
    public function ignore($enable = true)
    {
        $this->setFlag('IGNORE', $enable);
        return $this;
    }
    
    /**
     * 设置 QUICK 标记
     * @param bool $enable
     * @return self
     */
    public function quick($enable = true)
    {
        $this->setFlag('QUICK', $enable);
        return $this;
    }
    
    /**
     * 设置 DELAYED 标记
     * @param bool $enable
     * @return self
     */
    public function delayed($enable = true)
    {
        $this->setFlag('DELAYED', $enable);
        return $this;
    }
    
    /**
     * 序列化
     * @return string 
     */
    public function __toString()
    {
        $union = '';
        if ($this->union) {
            $union = implode(' ', $this->union) . ' ';
        }
        return $union . $this->build();
    }
    
    /**
     * 设置每页多少条记录
     * @param int 
     * @return self
     */
    public function setPaging($paging)
    {
        $this->paging = (int) $paging;
        return $this;
    }
    
    /**
     * 获取每页多少条记录
     * @return int 
     */
    public function getPaging()
    {
        return $this->paging;
    }
    
    /**
     * 获取绑定在占位符上的值
     */
    public function getBindValues()
    {
        switch($this->type)
        {
            case 'SELECT':
                return $this->getBindValuesSELECT();
            case 'DELETE':
            case 'UPDATE':
            case 'INSERT':
                return $this->getBindValuesCOMMON();
            default :
                throw new \Exception("type err");
        }
    }
    
    /**
     * 获取绑定在占位符上的值
     * @return array
     */
    public function getBindValuesSELECT()
    {
        $bind_values = $this->bind_values;
        $i = 1;
        foreach ($this->bind_where as $val) {
            $bind_values[$i] = $val;
            $i ++;
        }
        foreach ($this->bind_having as $val) {
            $bind_values[$i] = $val;
            $i ++;
        }
        return $bind_values;
    }
    
    /**
     *
     * SELECT选择哪些列
     * @param mixed
     * @return null
     */
    protected function addColSELECT($key, $val)
    {
        if (is_string($key)) {
            $this->cols[$val] = $key;
        } else {
            $this->addColWithAlias($val);
        }
    }
    
    /**
     * SELECT增加选择的列
     * @param string
     * @return null
     */
    protected function addColWithAlias($spec)
    {
        $parts = explode(' ', $spec);
        $count = count($parts);
        if ($count == 2) {
            $this->cols[$parts[1]] = $parts[0];
        } elseif ($count == 3 && strtoupper($parts[1]) == 'AS') {
            $this->cols[$parts[2]] = $parts[0];
        } else {
            $this->cols[] = $spec;
        }
    }
    
    /**
     * from 哪个表
     * @param string $table
     * @return self
     */
    public function from($table)
    {
        return $this->fromRaw($this->quoteName($table));
    }
    
    /**
     * from的表
     * @param string $table
     * @return self
     */
    public function fromRaw($table)
    {
        $this->from[] = array($table);
        $this->from_key ++;
        return $this;
    }
    /**
     *
     * 子查询
     * @param string $table
     * @param string $name The alias name for the sub-select.
     * @return self
     */
    public function fromSubSelect($table, $name)
    {
        $this->from[] = array( "($table) AS " . $this->quoteName($name));
        $this->from_key ++;
        return $this;
    }
    
    
    /**
     * 增加join语句
     * @param string $join  inner, left, natural
     * @param string $table
     * @param string $cond
     * @return self
     * @throws Exception
     */
    public function join($table, $cond = null, $type = '')
    {
    	return $this->joinInternal($type, $table, $cond);
    }
    
    /**
     * 增加join语句
     * @param string $join  inner, left, natural
     * @param string $table 
     * @param string $cond 
     * @return self
     * @throws Exception
     */
    protected function joinInternal($join, $table, $cond = null)
    {
        if (! $this->from) {
            throw new Exception('Cannot join() without from()');
        }
    
        $join = strtoupper(ltrim("$join JOIN"));
        $table = $this->quoteName($table);
        $cond = $this->fixJoinCondition($cond);
        $this->from[$this->from_key][] = rtrim("$join $table $cond");
        return $this;
    }
    
    /**
     * quote
     * @param string $cond 
     * @return string
     *
     */
    protected function fixJoinCondition($cond)
    {
        if (! $cond) {
            return;
        }
    
        $cond = $this->quoteNamesIn($cond);
    
        if (strtoupper(substr(ltrim($cond), 0, 3)) == 'ON ') {
            return $cond;
        }
    
        if (strtoupper(substr(ltrim($cond), 0, 6)) == 'USING ') {
            return $cond;
        }
    
        return 'ON ' . $cond;
    }
    
    /**
     * inner join
     * @param string $spec
     * @param string $cond 
     * @return self
     * @throws Exception
     */
    public function innerJoin($table, $cond = null)
    {
        return $this->joinInternal('INNER', $table, $cond);
    }
    
    /**
     * left join
     * @param string $table
     * @param string $cond 
     * @return self
     * @throws Exception
     */
    public function leftJoin($table, $cond = null)
    {
        return $this->joinInternal('LEFT', $table, $cond);
    }
    
    /**
     * right join
     * @param string $table
     * @param string $cond
     * @return self
     * @throws Exception
     */
    public function rightJoin($table, $cond = null)
    {
    	return $this->joinInternal('RIGHT', $table, $cond);
    }
    
    /**
     * joinSubSelect
     * @param string $join  inner, left, natural
     * @param string $spec
     * @param string $name sub-select 的别名
     * @param string $cond
     * @return self
     * @throws Exception
     */
    public function joinSubSelect($join, $spec, $name, $cond = null)
    {
        if (! $this->from) {
            throw new Exception('Cannot join() without from() first.');
        }
    
        $join = strtoupper(ltrim("$join JOIN"));
        $name = $this->quoteName($name);
        $cond = $this->fixJoinCondition($cond);
        $this->from[$this->from_key][] = rtrim("$join ($spec) AS $name $cond");
        return $this;
    }
    
    /**
     * group by 语句
     * @param array $cols
     * @return self
     */
    public function groupBy(array $cols)
    {
        foreach ($cols as $col) {
            $this->group_by[] = $this->quoteNamesIn($col);
        }
        return $this;
    }
    
    /**
     * having 语句
     * @param string $cond
     * @return self
     */
    public function having($cond)
    {
        $this->addClauseCondWithBind('having', 'AND', func_get_args());
        return $this;
    }
    
    /**
     * or having 语句
     * @param string $cond The HAVING condition.
     * @return self
     */
    public function orHaving($cond)
    {
        $this->addClauseCondWithBind('having', 'OR', func_get_args());
        return $this;
    }
    
    /**
     * 设置每页的记录数量
     * @param int $page 
     * @return self
     */
    public function page($page)
    {
        $this->limit  = 0;
        $this->offset = 0;
    
        $page = (int) $page;
        if ($page > 0) {
            $this->limit  = $this->paging;
            $this->offset = $this->paging * ($page - 1);
        }
        return $this;
    }
    
    /**
     * union
     * @return self
     */
    public function union()
    {
        $this->union[] = $this->build() . ' UNION';
        $this->reset();
        return $this;
    }
    
    /**
     * unionAll
     * @return self
     */
    public function unionAll()
    {
        $this->union[] = $this->build() . ' UNION ALL';
        $this->reset();
        return $this;
    }
    
    /**
     * 重置
     * @return null
     */
    protected function reset()
    {
        $this->resetFlags();
        $this->cols       = array();
        $this->from       = array();
        $this->from_key   = -1;
        $this->where      = array();
        $this->group_by   = array();
        $this->having     = array();
        $this->order_by   = array();
        $this->limit      = 0;
        $this->offset     = 0;
        $this->for_update = false;
    }
    
    /**
     * 清除所有数据
     * @return void
     */
    protected function resetAll()
    {
        $this->union = array();
        $this->for_update = false;
        $this->cols = array();
        $this->from = array();
        $this->from_key = -1;
        $this->group_by = array();
        $this->having = array();
        $this->bind_having = array();
        $this->paging = 10;
        $this->bind_values = array();
        $this->where = array();
        $this->bind_where = array();
        $this->order_by = array();
        $this->limit = 0;
        $this->offset = 0;
        $this->flags = array();
        $this->table = '';
        $this->last_insert_id_names = array();
        $this->col_values = array();
        $this->returning = array();
        $this->parameters = array();
    }
    
    /**
     * 创建 SELECT SQL
     * @return string
     */
    protected function buildSELECT()
    {
        return 'SELECT'
        . $this->buildFlags()
        . $this->buildCols()
        . $this->buildFrom()
        . $this->buildWhere()
        . $this->buildGroupBy()
        . $this->buildHaving()
        . $this->buildOrderBy()
        . $this->buildLimit()
        . $this->buildForUpdate();
    }
    
    /**
     * 创建DELETE SQL
     */
    protected function buildDELETE()
    {
        return 'DELETE'
            . $this->buildFlags()
            . $this->buildFrom()
            . $this->buildWhere()
            . $this->buildOrderBy()
            . $this->buildLimit()
            . $this->buildReturning();
    }

    /**
     * 生成SELECT列语句
     * @return string
     * @throws Exception
     */
    protected function buildCols()
    {
        if (! $this->cols) {
            throw new Exception('No columns in the SELECT.');
        }
    
        $cols = array();
        foreach ($this->cols as $key => $val) {
            if (is_int($key)) {
                $cols[] = $this->quoteNamesIn($val);
            } else {
                $cols[] = $this->quoteNamesIn("$val AS $key");
            }
        }
    
        return $this->indentCsv($cols);
    }
    
    /**
     * 生成 FROM 语句.
     * @return string
     */
    protected function buildFrom()
    {
        if (! $this->from) {
            return '';
        }
    
        $refs = array();
        foreach ($this->from as $from) {
            $refs[] = implode(' ', $from);
        }
        return ' FROM' . $this->indentCsv($refs);
    }
    
    /**
     * 生成 GROUP BY 语句.
     * @return string
     */
    protected function buildGroupBy()
    {
        if (! $this->group_by) {
            return ''; 
        }
        return ' GROUP BY' . $this->indentCsv($this->group_by);
    }
    
    /**
     * 生成 HAVING 语句.
     * @return string
     */
    protected function buildHaving()
    {
        if (! $this->having) {
            return ''; 
        }
        return ' HAVING' . $this->indent($this->having);
    }
    
    /**
     * 生成 FOR UPDATE 语句
     * @return string
     */
    protected function buildForUpdate()
    {
        if (! $this->for_update) {
            return ''; 
        }
        return ' FOR UPDATE';
    }
    
    /**
     * where
     * @param string/array $cond 
     * @param mixed ...$bind
     * @return self
     */
    public function where($cond)
    {
    	if(is_array($cond))
    	{
    		foreach($cond as $key=>$val)
    		{
    			if(is_string($key))
    			{
    				$this->addWhere('AND', array($key, $val));
    			}
    			else
    			{
    				$this->addWhere('AND', array($val));
    			}
    		}
    	}
    	else 
    	{
        	$this->addWhere('AND', func_get_args());
    	}
        return $this;
    }
    
    /**
     * or where
     * @param string/array $cond 
     * @param mixed ...$bind
     * @return self
     */
    public function orWhere($cond)
    {
    	if(is_array($con))
    	{
    		foreach($con as $key=>$val)
    		{
    			if(is_string($key))
    			{
    				$this->addWhere('OR', array($key, $val));
    			}
    			else
    			{
    				$this->addWhere('OR', array($val));
    			}
    		}
    	}
    	else
    	{
        	$this->addWhere('OR', func_get_args());
    	}
        return $this;
    }
    
    /**
     * limit 
     * @param int $limit
     * @return self
     */
    public function limit($limit)
    {
        $this->limit = (int) $limit;
        return $this;
    }
    
    /**
     * limit offset
     * @param int $offset 
     * @return self
     */
    public function offset($offset)
    {
        $this->offset = (int) $offset;
        return $this;
    }
    
    /**
     * orderby.
     * @param array $cols
     * @return self
     */
    public function orderBy(array $cols)
    {
        return $this->addOrderBy($cols);
    }
    
    // -------------abstractquery----------
    /**
     * 返回逗号分隔的字符串
     * @param array $list
     * @return string
     */
    protected function indentCsv(array $list)
    {
        return ' ' . implode(',', $list);
    }
    
    /**
     * 返回空格分隔的字符串
     * @param array $list
     * @return string
     */
    protected function indent(array $list)
    {
        return ' ' . implode(' ', $list);
    }
    
    /**
     * 批量为占位符绑定值
     * @param array $bind_values
     * @return self
     *
     */
    public function bindValues(array $bind_values)
    {
        foreach ($bind_values as $key => $val) {
            $this->bindValue($key, $val);
        }
        return $this;
    }
    
    /**
     * 单个为占位符绑定值
     * @param string $name 
     * @param mixed $value
     * @return self
     */
    public function bindValue($name, $value)
    {
        $this->bind_values[$name] = $value;
        return $this;
    }
    
    /**
     * 生成flag
     * @return string
     */
    protected function buildFlags()
    {
        if (! $this->flags) {
            return '';
        }
        return ' ' . implode(' ', array_keys($this->flags));
    }
    
    /**
     * 设置 flag.
     * @param string $flag 
     * @param bool $enable
     * @return null
     */
    protected function setFlag($flag, $enable = true)
    {
        if ($enable) {
            $this->flags[$flag] = true;
        } else {
            unset($this->flags[$flag]);
        }
    }
    
    /**
     * 重置flag
     * @return null
     */
    protected function resetFlags()
    {
        $this->flags = array();
    }
    
    /**
     *
     * 添加where语句
     * @param string $andor 'AND' or 'OR
     * @param array $conditions
     * @return self
     *
     */
    protected function addWhere($andor, $conditions)
    {
        $this->addClauseCondWithBind('where', $andor, $conditions);
        return $this;
    }
    
    /**
     * 添加条件和绑定值
     * @param string $clause where 、having等
     * @param string $andor AND、OR等
     * @param array $conditions 
     * @return null
     */
    protected function addClauseCondWithBind($clause, $andor, $conditions)
    {
        $cond = array_shift($conditions);
        $cond = $this->quoteNamesIn($cond);
    
        $bind =& $this->{"bind_{$clause}"};
        foreach ($conditions as $value) {
            $bind[] = $value;
        }
    
        $clause =& $this->$clause;
        if ($clause) {
            $clause[] = "$andor $cond";
        } else {
            $clause[] = $cond;
        }
    }
    
    /**
     * 生成where语句
     * @return string
     */
    protected function buildWhere()
    {
        if (! $this->where) {
            return ''; 
        }
        return ' WHERE' . $this->indent($this->where);
    }
    
    /**
     * 增加order by
     * @param array $spec The columns and direction to order by.
     * @return self
     */
    protected function addOrderBy(array $spec)
    {
        foreach ($spec as $col) {
            $this->order_by[] = $this->quoteNamesIn($col);
        }
        return $this;
    }
    
    /**
     * 生成order by 语句
     * @return string
     */
    protected function buildOrderBy()
    {
        if (! $this->order_by) {
            return ''; 
        }
        return ' ORDER BY' . $this->indentCsv($this->order_by);
    }
    
    /**
     * 生成limit语句
     * @return string
     */
    protected function buildLimit()
    {
        $has_limit = $this->type == 'DELETE' || $this->type == 'UPDATE';
        $has_offset = $this->type == 'SELECT';
    
        if ($has_offset && $this->limit) {
            $clause = " LIMIT {$this->limit}";
            if ($this->offset) {
                $clause .= " OFFSET {$this->offset}";
            }
            return $clause;
        } elseif ($has_limit && $this->limit) {
            return " LIMIT {$this->limit}";
        }
        return ''; 
    }

    /**
     * Quotes 
     * @param string $spec
     * @return string|array 
     */
    public function quoteName($spec)
    {
        $spec = trim($spec);
        $seps = array(' AS ', ' ', '.');
        foreach ($seps as $sep) {
            $pos = strripos($spec, $sep);
            if ($pos) {
                return $this->quoteNameWithSeparator($spec, $sep, $pos);
            }
        }
        return $this->replaceName($spec);
    }

    /**
     * 指定分隔符的Quotes 
     * @param string $spec 
     * @param string $sep 
     * @param string $pos 
     * @return string 
     */
    protected function quoteNameWithSeparator($spec, $sep, $pos)
    {
        $len = strlen($sep);
        $part1 = $this->quoteName(substr($spec, 0, $pos));
        $part2 = $this->replaceName(substr($spec, $pos + $len));
        return "{$part1}{$sep}{$part2}";
    }

    /**
     * Quotes "table.col" 格式的字符串
     * @param string $text 
     * @return string|array 
     */
    public function quoteNamesIn($text)
    {
        $list = $this->getListForQuoteNamesIn($text);
        $last = count($list) - 1;
        $text = null;
        foreach ($list as $key => $val) {
            if (($key+1) % 3) {
                $text .= $this->quoteNamesInLoop($val, $key == $last);
            }
        }
        return $text;
    }

    /**
     * 返回quote元素列表
     * @param string $text 
     * @return array
     */
    protected function getListForQuoteNamesIn($text)
    {
        $apos = "'";
        $quot = '"';
        return preg_split(
            "/(($apos+|$quot+|\\$apos+|\\$quot+).*?\\2)/",
            $text,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );
    }

    /**
     * 循环quote
     * @param string $val 
     * @param bool $is_last
     * @return string 
     */
    protected function quoteNamesInLoop($val, $is_last)
    {
        if ($is_last) {
            return $this->replaceNamesAndAliasIn($val);
        }
        return $this->replaceNamesIn($val);
    }

    /**
     *
     * 替换成别名
     * @param string $val 
     * @return string
     */
    protected function replaceNamesAndAliasIn($val)
    {
        $quoted = $this->replaceNamesIn($val);
        $pos = strripos($quoted, ' AS ');
        if ($pos) {
            $alias = $this->replaceName(substr($quoted, $pos + 4));
            $quoted = substr($quoted, 0, $pos) . " AS $alias";
        }
        return $quoted;
    }

    /**
     * Quotes name 
     * @param string $name 
     * @return string 
     */
    protected function replaceName($name)
    {
        $name = trim($name);
        if ($name == '*') {
            return $name;
        }
        return '`'. $name.'`';
    }

    /**
     * Quotes 
     * @param string $text
     * @return string|array 
     */
    protected function replaceNamesIn($text)
    {
        $is_string_literal = strpos($text, "'") !== false
                        || strpos($text, '"') !== false;
        if ($is_string_literal) {
            return $text;
        }

        $word = "[a-z_][a-z0-9_]+";

        $find = "/(\\b)($word)\\.($word)(\\b)/i";

        $repl = '$1`$2`.`$3`$4';

        $text = preg_replace($find, $repl, $text);

        return $text;
    }
    
     // ---------- insert --------------
    /**
     * 设置 `table.column` 与 last-insert-id 的映射
     * @param array $insert_id_names
     */
    public function setLastInsertIdNames(array $last_insert_id_names)
    {
        $this->last_insert_id_names = $last_insert_id_names;
    }

    /**
     * insert into.
     * @param string $into
     * @return self
     */
    public function into($table)
    {
        $this->table = $this->quoteName($table);
        return $this;
    }

    /**
     * 生成INSERT 语句
     * @return string
     */
    protected function buildINSERT()
    {
        return 'INSERT'
            . $this->buildFlags()
            . $this->buildInto()
            . $this->buildValuesForInsert()
            . $this->buildReturning();
    }

    /**
     * 生成 INTO 语句
     * @return string
     */
    protected function buildInto()
    {
        return " INTO " . $this->table;
    }

    /**
     * PDO::lastInsertId()
     * @param string $col 
     * @return mixed 
     */
    public function getLastInsertIdName($col)
    {
        $key = str_replace('`', '', $this->table) . '.' . $col;
        if (isset($this->last_insert_id_names[$key])) {
            return $this->last_insert_id_names[$key];
        }
    }

    /**
     *
     * 设置一列，如果有第二各参数，则把第二个参数绑定在占位符上
     * @param string $col 
     * @param mixed  $val
     * @return self
     */
    public function col($col)
    {
        return call_user_func_array(array($this, 'addCol'), func_get_args());
    }

    /**
     * 设置多列
     * @param array $cols 
     * @return self
     */
    public function cols(array $cols)
    {
        if($this->type == 'SELECT')
        {
            foreach ($cols as $key => $val) 
            {
                $this->addColSELECT($key, $val);
            }
            return $this;
        }
        return $this->addCols($cols);
    }

    /**
     * 直接设置列的值
     * @param string $col
     * @param string $value
     * @return self
     */
    public function set($col, $value)
    {
        return $this->setCol($col, $value);
    }

    /**
     * 为INSERT语句绑定值
     * @return string
     */
    protected function buildValuesForInsert()
    {
        return ' ('.$this->indentCsv(array_keys($this->col_values)).') VALUES (' . $this->indentCsv(array_values($this->col_values)) . ')';
    }

    // ------update-------
    /**
     * 更新哪个表
     * @param string $table
     * @return self
     */
    public function table($table)
    {
        $this->table = $this->quoteName($table);
        return $this;
    }

    /**
     * 生成完整SQL语句
     * @return string
     */
    protected function build()
    {
        switch($this->type)
        {
           case 'DELETE':
             return $this->buildDELETE();
           case 'INSERT':
             return $this->buildINSERT();
           case 'UPDATE':
             return $this->buildUPDATE();
           case 'SELECT':
             return $this->buildSELECT();
        }
        throw new \Exception("type empty");
    }
    
    /**
     * 生成更新的SQL语句
     */
    protected function buildUPDATE()
    {
        return 'UPDATE'
            . $this->buildFlags()
            . $this->buildTable()
            . $this->buildValuesForUpdate()
            . $this->buildWhere()
            . $this->buildOrderBy()
            . $this->buildLimit()
            . $this->buildReturning();
   
    }

    /**
     * 哪个表
     * @return null
     */
    protected function buildTable()
    {
        return " {$this->table}";
    }

    /**
     * 为更新语句绑定值
     * @return string
     */
    protected function buildValuesForUpdate()
    {
        $values = array();
        foreach ($this->col_values as $col => $value) {
            $values[] = "{$col} = {$value}";
        }
        return ' SET' . $this->indentCsv($values);
    }
   
    // ----------Dml---------------
    /**
     * 获取绑定的值
     * @return array
     */
    public function getBindValuesCOMMON()
    {
        $bind_values = $this->bind_values;
        $i = 1;
        foreach ($this->bind_where as $val) {
            $bind_values[$i] = $val;
            $i ++;
        }
        return $bind_values;
    }

    /**
     * 设置列
     * @param string $col
     * @param mixed $val
     * @return self
     */
    protected function addCol($col)
    {
        $key = $this->quoteName($col);
        $this->col_values[$key] = ":$col";
        $args = func_get_args();
        if (count($args) > 1) {
            $this->bindValue($col, $args[1]);
        }
        return $this;
    }

    /**
     * 设置多个列
     * @param array $cols
     * @return self
     */
    protected function addCols(array $cols)
    {
        foreach ($cols as $key => $val) {
            if (is_int($key)) {
                $this->addCol($val);
            } else {
                $this->addCol($key, $val);
            }
        }
        return $this;
    }

    /**
     * 设置单列的值
     * @param string $col .
     * @param string $value
     * @return self
     */
    protected function setCol($col, $value)
    {
        if ($value === null) {
            $value = 'NULL';
        }

        $key = $this->quoteName($col);
        $value = $this->quoteNamesIn($value);
        $this->col_values[$key] = $value;
        return $this;
    }

    /**
     * 增加返回的列
     * @param array $cols
     * @return self
     *
     */
    protected function addReturning(array $cols)
    {
        foreach ($cols as $col) {
            $this->returning[] = $this->quoteNamesIn($col);
        }
        return $this;
    }

    /**
     * 生成 RETURNING 语句
     * @return string
     */
    protected function buildReturning()
    {
        if (! $this->returning) {
            return '';
        }
        return ' RETURNING' . $this->indentCsv($this->returning);
    }
    
    /**
     * 构造函数
     */
    public function __construct($host, $port, $user, $password, $db_name, $charset = 'utf8')
    {
        $this->settings = array(
            'host'          => $host,
            'port'          => $port,
            'user'          => $user,
            'password' => $password,
            'dbname'  => $db_name,
            'charset'    => $charset,
        );
        $this->connect();
    }
    
    /**
     * 创建pdo实例
     */
    protected function connect()
    {
        $dsn = 'mysql:dbname='.$this->settings["dbname"].';host='.$this->settings["host"].';port='.$this->settings['port'];
        $this->pdo = new \PDO($dsn, $this->settings["user"], $this->settings["password"], array(\PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . (!empty($this->settings['charset']) ? $this->settings['charset'] : 'utf8')));
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }
    /*
    *   关闭连接
    */
    public function closeConnection()
    {
        $this->pdo = null;
    }

    /**
     * 执行
     * @param string $query
     * @param string $parameters
     */
    protected function execute($query,$parameters = "")
    {
        try {
            $this->sQuery = $this->pdo->prepare($query);
            $this->bindMore($parameters);
            if(!empty($this->parameters)) {
                foreach($this->parameters as $param)
                {
                    $parameters = explode("\x7F",$param);
                    $this->sQuery->bindParam($parameters[0],$parameters[1]);
                }
            }
            $this->succes  = $this->sQuery->execute();
        }
        catch(\PDOException $e)
        {
            // 服务端断开时重连一次
            if($e->errorInfo[1] == 2006 || $e->errorInfo[1] == 2013)
            {
                $this->closeConnection();
                $this->connect();
                $this->sQuery = $this->pdo->prepare($query);
                $this->bindMore($parameters);
                if(!empty($this->parameters)) {
                    foreach($this->parameters as $param)
                    {
                        $parameters = explode("\x7F",$param);
                        $this->sQuery->bindParam($parameters[0],$parameters[1]);
                    }
                }
                $this->succes  = $this->sQuery->execute();
            }
            else
            {
                throw $e;
            }
        }
        $this->parameters = array();
    }
    
    /**
    * 绑定
    * @param string $para
    * @param string $value
    */
    public function bind($para, $value)
    {
    	if(is_string($para))
    	{
        	$this->parameters[sizeof($this->parameters)] = ":" . $para . "\x7F" . $value;
    	}
    	else
    	{
    		$this->parameters[sizeof($this->parameters)] = $para . "\x7F" . $value;
    	}
    }
    
    /**
     * 绑定多个
     * @param array $parray
     */
    public function bindMore($parray)
    {
        if(empty($this->parameters) && is_array($parray)) {
            $columns = array_keys($parray);
            foreach($columns as $i => &$column)    {
                $this->bind($column, $parray[$column]);
            }
        }
    }
    
    /**
     * 执行SQL
     * @param  string $query
     * @param  array  $params
     * @param  int    $fetchmode
     * @return mixed
     */
    public function query($query = '',$params = null, $fetchmode = \PDO::FETCH_ASSOC)
    {
        $query = trim($query);
        if(empty($query))
        {
            $query = $this->build();
            if(!$params)
            {
                $params = $this->getBindValues();
            }
        }
        
        $this->resetAll();
        $this->lastSql = $query;
        
        $this->execute($query,$params);
        
        $rawStatement = explode(" ", $query);
            
        $statement = strtolower(trim($rawStatement[0]));
        if ($statement === 'select' || $statement === 'show') {
            return $this->sQuery->fetchAll($fetchmode);
        }
        elseif ( $statement === 'insert' ||  $statement === 'update' || $statement === 'delete' ) {
            return $this->sQuery->rowCount();
        }
        else {
            return NULL;
        }
    }
    
    /**
    * 返回一列
    * @param  string $query
    * @param  array  $params
    * @return array
    */
    public function column($query = '',$params = null)
    {
        $query = trim($query);
        if(empty($query))
        {
            $query = $this->build();
            if(!$params)
            {
                $params = $this->getBindValues();
            }
        }
        
        $this->resetAll();
        $this->lastSql = $query;
        
        $this->execute($query,$params);
        $columns = $this->sQuery->fetchAll(\PDO::FETCH_NUM);
        $column = null;
        foreach($columns as $cells) {
            $column[] = $cells[0];
        }
        return $column;
    }
    
    /**
    * 返回一行
    * @param  string $query
    * @param  array  $params
    * @param  int    $fetchmode
    * @return array
    */
    public function row($query = '',$params = null, $fetchmode = \PDO::FETCH_ASSOC)
    {
        $query = trim($query);
        if(empty($query))
        {
            $query = $this->build();
            if(!$params)
            {
                $params = $this->getBindValues();
            }
        }
        
        $this->resetAll();
        $this->lastSql = $query;
        
        $this->execute($query,$params);
        return $this->sQuery->fetch($fetchmode);
    }
    
    /**
    * 返回单个值
    * @param  string $query
    * @param  array  $params
    * @return string
    */
    public function single($query = '',$params = null)
    {
        $query = trim($query);
        if(empty($query))
        {
            $query = $this->build();
            if(!$params)
            {
                $params = $this->getBindValues();
            }
        }
        
        $this->resetAll();
        $this->lastSql = $query;
        
        $this->execute($query,$params);
        return $this->sQuery->fetchColumn();
    }
    
    /**
     * 返回lastInsertId
     * @return string
     */
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
    
    /**
     * 返回最后一条直行的sql
     * @return  string
     */
    public function lastSQL()
    {
        return $this->lastSql;
    }
}
