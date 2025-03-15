<?php

/*
 * Copyright (c) 2025. Lorem ipsum dolor sit amet, consectetur adipiscing elit.
 * Morbi non lorem porttitor neque feugiat blandit. Ut vitae ipsum eget quam lacinia accumsan.
 * Etiam sed turpis ac ipsum condimentum fringilla. Maecenas magna.
 * Proin dapibus sapien vel ante. Aliquam erat volutpat. Pellentesque sagittis ligula eget metus.
 * Vestibulum commodo. Ut rhoncus gravida arcu.
 */

declare(strict_types=1);
/*
 * Copyright (c) 2023. Ankio. All Rights Reserved.
 */

/**
 * Package: nova\plugin\orm\operation
 * Class SelectOperation
 * Created By ankio.
 * Date : 2022/11/16
 * Time : 18:18
 * Description :
 */

namespace nova\plugin\orm\operation;

use Exception;
use nova\plugin\orm\Db;
use nova\plugin\orm\exception\DbFieldError;
use nova\plugin\orm\object\Field;

class SelectOperation extends BaseOperation
{
    public const SORT_DESC = "DESC";
    public const SORT_ASC = "ASC";

    /**
     * 初始化
     * @param  mixed        ...$field 需要的字段
     * @throws DbFieldError
     */
    public function __construct(Db &$db, $m, ...$field)
    {
        parent::__construct($db, $m);
        $this->opt = [];
        $this->opt['type'] = 'select';
        $this->opt['distinct'] = '';
        $this->opt['field'] = (isset($field[0]) && $field[0] instanceof Field) ? $field[0]->toString() : (new Field(...$field))->toString();
        $this->bind_param = [];
    }

    /**
     * 使用排序
     * @param  string       $string 排序方式
     * @return $this
     * @throws DbFieldError
     */
    public function orderBy(string $string, string $type = self::SORT_DESC): SelectOperation
    {
        if (!Field::isName($string)) {
            throw new DbFieldError("Disallowed field name => $string", $string);
        }
        if (!in_array($type, [self::SORT_DESC, self::SORT_ASC])) {
            $type = self::SORT_DESC;
        }
        if (isset($this->opt['order']) && $this->opt['order'] !== "") {
            $this->opt['order'] = $this->opt['order'] . "," . $string . " " . $type;
        } else {
            $this->opt['order'] = $string . " " . $type;
        }

        return $this;
    }

    /**
     * 按照某个字段分组
     * @param  string       $string
     * @return $this
     * @throws DbFieldError
     */
    public function groupBy(string $string): SelectOperation
    {
        if (!Field::isName($string)) {
            throw new DbFieldError("Disallowed field name => $string", $string);
        }
        $this->opt['group_by'] = $string;
        return $this;
    }

    /**
     * limit函数
     * @param  int   $start limit开始
     * @param  int   $end   limit结束
     * @return $this
     */
    public function limit(int $start = 1, int $end = -1): SelectOperation
    {
        unset($this->opt['page']);
        $limit = strval($start);
        if ($end != -1) {
            $limit .= "," . $end;
        }
        $this->opt['limit'] = $limit;
        return $this;
    }

    /**
     * 分页
     * @param  int   $start 开始
     * @param  int   $count 数量
     * @return $this
     */
    public function page(int $start = 1, int $count = 10): SelectOperation
    {

        unset($this->opt['limit']);
        $this->opt['page'] = true;
        $this->opt['start'] = $start;
        $this->opt['count'] = $count;
        return $this;
    }

    /**
     * 提交
     * @param  int       $total
     * @param  bool      $object
     * @return array|int
     */
    public function commit(int &$total = 0, bool $object = true): array|int
    {

        if (isset($this->opt['start']) && isset($this->opt['count'])) {
            $sql = 'SELECT COUNT(*) as M_COUNTER ';
            $sql .= $this->getOpt('FROM', 'table_name');
            $sql .= $this->getOpt('WHERE', 'where');
            $sql .= $this->getOpt('ORDER BY', 'order');
            $sql .= $this->getOpt('GROUP BY', 'groupBy');

            try {
                $total = $this->db->execute($sql, $this->bind_param, true)[0]['M_COUNTER'];
            } catch (Exception $e) {
                $total = 0;
            }
            $offset = ($this->opt['start'] - 1) * $this->opt['count'];
            $limit = $this->opt['count'];

            if ($offset < 0) {
                $offset = 0;
            }
            $this->opt['limit'] = $offset . ',' . $limit;
        }

        $result = parent::__commit(true);

        if (!$object) {
            return $result;
        }
        if ($this->model !== null) {

            return $this->translate2Model($this->model, $result);
        } else {
            return $result;
        }

    }

    /**
     * 统计查出来的数据的总数
     * @param  array $conditions 统计条件
     * @return int
     |DbFieldError
     */
    public function count(array $conditions): mixed
    {
        if (!empty($conditions)) {
            $this->where($conditions);
        }
        $sql = /** @lang text */
            "SELECT COUNT(*) AS M_COUNTER FROM " . $this->opt['table_name'] . "  " . (empty($conditions) ? '' : 'where ' . $this->opt['where']);
        $this->transferSql = $sql;
        $count = $this->__commit(true);
        return isset($count[0]['M_COUNTER']) && $count[0]['M_COUNTER'] ? intval($count[0]['M_COUNTER']) : 0;
    }

    /**
     * 修改Where语句
     * @param  array        $conditions
     * @return $this
     * @throws DbFieldError
     */
    public function where(array $conditions): SelectOperation
    {
        return parent::where($conditions);
    }

    /**
     * 对某个字段进行求和
     * @param  array        $conditions 求和条件
     * @param  string       $param      求和字段
     * @return int
     * @throws DbFieldError
     */
    public function sum(array $conditions, string $param): int
    {
        if (!Field::isName($param)) {
            throw new DbFieldError("Disallowed field name => $param");
        }
        if (!empty($conditions)) {
            $this->where($conditions);
        }

        $sql = /** @lang text */
            "SELECT SUM($param) AS M_COUNTER FROM " . $this->opt['table_name'] . " " . (empty($conditions) ? '' : 'where ' . $this->opt['where']);
        try {
            $this->transferSql = $sql;
            $count = $this->__commit(true);
        } catch (Exception $e) {
            return 0;
        }
        return isset($count[0]['M_COUNTER']) && $count[0]['M_COUNTER'] ? intval($count[0]['M_COUNTER']) : 0;
    }

    /**
     * 去重
     * @return $this
     */
    public function distinct(): static
    {
        $this->opt['distinct'] = "DISTINCT";
        return $this;
    }

    /**
     * 编译
     */
    protected function translateSql(): void
    {
        $sql = $this->getOpt('SELECT', 'distinct');
        $sql .= $this->getOpt('', 'field');
        $sql .= $this->getOpt('FROM', 'table_name');
        $sql .= $this->getOpt('WHERE', 'where');
        $sql .= $this->getOpt('ORDER BY', 'order');
        $sql .= $this->getOpt('GROUP BY', 'group_by');
        $sql .= $this->getOpt('LIMIT', 'limit');
        $this->transferSql = $sql . ";";
    }

}
