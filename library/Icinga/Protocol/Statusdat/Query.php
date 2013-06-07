<?php
// {{{ICINGA_LICENSE_HEADER}}}
// {{{ICINGA_LICENSE_HEADER}}}

namespace Icinga\Protocol\Statusdat;

use Icinga\Protocol;

/**
 * Class Query
 * @package Icinga\Protocol\Statusdat
 */
class Query extends Protocol\AbstractQuery
{
    /**
     * @var array
     */
    public static $VALID_TARGETS = array(
        "hosts" => array("host"),
        "services" => array("service"),
        "downtimes" => array("hostdowntime", "servicedowntime"),
        "hostdowntimes" => array("hostdowntime"),
        "servicedowntimes" => array("servicedowntime"),
        "hostgroups" => array("hostgroup"),
        "servicegroups" => array("servicegroup"),
        "comments" => array("servicecomment", "hostcomment"),
        "hostcomments" => array("hostcomment"),
        "servicecomments" => array("servicecomment")
    );

    /**
     * @var IReader|null
     */
    private $reader = null;

    /**
     * @var string
     */
    private $source = "";

    /**
     * @var array
     */
    private $columns = array();

    /**
     * @var null
     */
    private $limit = null;

    /**
     * @var int
     */
    private $offset = 0;

    /**
     * @var array
     */
    private $order_columns = array();

    /**
     * @var array
     */
    private $groupColumns = array();

    /**
     * @var null
     */
    private $groupByFn = null;

    /**
     * @var array
     */
    private $filter = array();

    /**
     *
     */
    const FN_SCOPE = 0;

    /**
     *
     */
    const FN_NAME = 1;

    /**
     * @return bool
     */
    public function hasOrder()
    {
        return !empty($this->order_columns);
    }

    /**
     * @return bool
     */
    public function hasColumns()
    {
        return !empty($this->columns);
    }

    /**
     * @return array
     */
    public function getColumns()
    {
        return $this->columns;

    }

    /**
     * @return bool
     */
    public function hasLimit()
    {
        return $this->limit !== false;
    }

    /**
     * @return bool
     */
    public function hasOffset()
    {
        return $this->offset !== false;
    }

    /**
     * @return null
     */
    public function getLimit()
    {
        return $this->limit;
    }

    /**
     * @return int|null
     */
    public function getOffset()
    {
        return $this->offset;
    }

    /**
     * @param IReader $reader
     */
    public function __construct(IReader $reader)
    {
        $this->reader = $reader;
    }

    /**
     * @param $key
     * @param null $val
     * @return $this
     */
    public function where($key, $val = null)
    {
        $this->filter[] = array($key, $val);
        return $this;
    }

    /**
     * @param $columns
     * @param null $dir
     * @return $this
     */
    public function order($columns, $dir = null)
    {
        if ($dir && strtolower($dir) == "desc") {
            $dir = self::SORT_DESC;
        } else {
            $dir = self::SORT_ASC;
        }
        if (!is_array($columns)) {
            $columns = array($columns);
        }
        foreach ($columns as $col) {

            if (($pos = strpos($col, ' ')) !== false) {
                $dir = strtoupper(substr($col, $pos + 1));
                if ($dir === 'DESC') {
                    $dir = self::SORT_DESC;
                } else {
                    $dir = self::SORT_ASC;
                }
                $col = substr($col, 0, $pos);
            } else {
                $col = $col;
            }

            $this->order_columns[] = array($col, $dir);
        }
        return $this;
    }

    /**
     * @param null $count
     * @param int $offset
     * @return $this
     * @throws Exception
     */
    public function limit($count = null, $offset = 0)
    {
        if ((is_null($count) || is_integer($count)) && (is_null($offset) || is_integer($offset))) {
            $this->offset = $offset;
            $this->limit = $count;
        } else {
            throw new Exception("Got invalid limit $count, $offset");
        }
        return $this;
    }

    /**
     * @param $table
     * @param null $columns
     * @return $this
     * @throws \Exception
     */
    public function from($table, $columns = null)
    {
        if (isset(self::$VALID_TARGETS[$table])) {
            $this->source = $table;
        } else {
            throw new \Exception("Unknown from target for status.dat :" . $table);
        }
        $this->columns = $columns;
        return $this;
    }

    /**
     *
     * @throws Exception
     */
    private function getFilteredIndices($classType = "\Icinga\Protocol\Statusdat\Query\Group")
    {
        $baseGroup = null;
        if (!empty($this->filter)) {
            $baseGroup = new $classType();

            foreach ($this->filter as $values) {
                $baseGroup->addItem(new $classType($values[0], $values[1]));
            }
        }

        $state = $this->reader->getObjects();
        $result = array();
        foreach (self::$VALID_TARGETS[$this->source] as $target) {
            $indexes = & array_keys($state[$target]);
            if ($baseGroup) {
                $indexes = & $baseGroup->filter($state[$target]);
            }
            if (!isset($result[$target])) {
                $result[$target] = $indexes;
            } else {
                array_merge($result[$target], $indexes);
            }
        }
        return $result;
    }

    /**
     * @param array $indices
     */
    private function orderIndices(array &$indices)
    {
        if (!empty($this->order_columns)) {
            foreach ($indices as $type => &$subindices) {
                $this->currentType = $type; // we're singlethreaded, so let's do it a bit dirty
                usort($subindices, array($this, "orderResult"));
            }
        }
    }

    /**
     * @param $a
     * @param $b
     * @return int
     */
    private function orderResult($a, $b)
    {
        $o1 = & $this->reader->getObjectByName($this->currentType, $a);
        $o2 = & $this->reader->getObjectByName($this->currentType, $b);
        $result = 0;
        foreach ($this->order_columns as $col) {

            $result += $col[1] * strnatcasecmp($o1->{$col[0]}, $o2->{$col[0]});
        }
        if ($result > 0) {
            return 1;
        }
        if ($result < 0) {
            return -1;
        }
        return 0;
    }

    /**
     * @param array $indices
     */
    private function limitIndices(array &$indices)
    {
        foreach ($indices as $type => $subindices) {
            $indices[$type] = array_slice($subindices, $this->offset, $this->limit);
        }
    }

    /**
     * @param $fn
     * @param null $scope
     * @return $this
     */
    public function groupByFunction($fn, $scope = null)
    {
        $this->groupByFn = array($scope ? $scope : $this, $fn);
        return $this;
    }

    /**
     * @param $columns
     * @return $this
     */
    public function groupByColumns($columns)
    {
        if (!is_array($columns)) {
            $columns = array($columns);
        }
        $this->groupColumns = $columns;
        $this->groupByFn = array($this, "columnGroupFn");
        return $this;
    }

    /**
     * @param array $indices
     * @return array
     */
    private function columnGroupFn(array &$indices)
    {
        $cols = $this->groupColumns;
        $result = array();
        foreach ($indices as $type => $subindices) {
            foreach ($subindices as $objectIndex) {
                $r = & $this->reader->getObjectByName($type, $objectIndex);
                $hash = "";
                $cols = array();
                foreach ($this->groupColumns as $col) {
                    $hash = md5($hash . $r->$col);
                    $cols[$col] = $r->$col;
                }
                if (!isset($result[$hash])) {
                    $result[$hash] = (object)array(
                        "columns" => (object)$cols,
                        "count" => 0
                    );
                }
                $result[$hash]->count++;
            }
        }
        return array_values($result);
    }

    /**
     * @return array
     */
    public function getResult()
    {

        $indices = & $this->getFilteredIndices();
        $this->orderIndices($indices);
        if ($this->groupByFn) {
            $scope = $this->groupByFn[self::FN_SCOPE];
            $fn = $this->groupByFn[self::FN_NAME];

            return $scope->$fn($indices);
        }

        $this->limitIndices($indices);

        $result = array();
        $state = & $this->reader->getObjects();
        foreach ($indices as $type => $subindices) {

            foreach ($subindices as $index) {
                $result[] = & $state[$type][$index];
            }
        }
        return $result;
    }
}
