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
 * Class BaseOperation
 * Created By ankio.
 * Date : 2022/11/16
 * Time : 16:10
 * Description :
 */

namespace nova\plugin\orm\operation;

use nova\framework\cache\Cache;
use nova\framework\core\Logger;
use nova\plugin\orm\Db;
use nova\plugin\orm\exception\DbExecuteError;
use nova\plugin\orm\exception\DbFieldError;

abstract class BaseOperation
{
    protected array $opt = [];//封装常见的数据库查询选项
    protected ?string $transferSql = null;//编译完成的sql语句

    private string $buildSql = "";//构建的sql语句
    protected array $bind_param = [];//绑定的参数列表

    protected Db $db;

    protected ?string $model;

    protected array $tables = [];

    /**
     * @param $db    DB 数据库对象
     * @param $model ?string 数据模型
     */
    public function __construct(Db &$db, string $model = null)
    {
        $this->db = $db;
        $this->model = $model;
    }

    /**
     * 设置表名
     * @param  string        ...$tableName
     * @return BaseOperation $this
     */
    public function table(string ...$tableName): BaseOperation
    {
        if (is_array($tableName)) {
            $names = $tableName;
        } else {
            $names = explode(",", $tableName);
        }

        $this->tables = $names;
        $table = "";
        foreach ($names as $name) {
            if (!empty($name)) {
                $table .= '`' . $name . '`,';
            }

        }
        $this->opt['table_name'] = trim($table, ",");
        return $this;
    }

    private function buildRunSQL($sql, $params): string
    {
        $sql_default = $sql;
        $params = array_reverse($params);

        foreach ($params as $k => $v) {
            $sql_default = match (gettype($v)) {
                "double", "boolean", "integer" => str_replace($k, strval($v), $sql_default),
                "NULL" => str_replace($k, "NULL", $sql_default),
                default => str_replace($k, "'$v'", $sql_default),
            };
        }
        return $sql_default;
    }

    /**
     *
     * 提交
     * @param  bool           $readonly
     * @return array|int
     
     */
    protected function __commit(bool $readonly = false): int|array
    {

        if ($this->transferSql == null) {
            $this->translateSql();
        }

        $this->buildSql = $this->buildRunSQL($this->transferSql, $this->bind_param);

        $cache = new Cache();
        $tableKey = md5($this->getTable());
        $key = $this->getCacheKey();
        $result = null;

        if ($readonly) {
            //尝试从缓存中获取数据
            $result = $cache->get("sql/$tableKey/$key");
        }
        if (empty($result)) {
            Logger::info("SQL",[ $this->buildSql ]);
            $result = $this->db->execute($this->transferSql, $this->bind_param, $readonly);
            if ($readonly) {
                //将数据存入缓存
                $cache->set("sql/$tableKey/$key", $result, 300); //缓存5分钟
            } else {
                //清空缓存
                $cache->deleteKeyStartWith("sql/$tableKey");
            }
        }

        return $result;
    }

    private function getCacheKey()
    {
        return md5($this->buildSql);
    }

    /**
     * 获取表名
     * @return string
     */

    private function getTable(): string
    {
        return $this->opt['table_name'];
    }

    /**
     * 编译sql语句
     * @return void
     */
    abstract protected function translateSql(): void;

    /**
     * 获取存储的数据选项
     * @param         $head
     * @param         $opt
     * @return string
     */
    protected function getOpt($head, $opt): string
    {
        if (isset($this->opt[$opt])) {
            return ' ' . $head . ' ' . $this->opt[$opt] . ' ';
        }
        return ' ';
    }

    /**
     * 设置查询条件
     * @param  array         $conditions 条件内容，必须是数组,格式如下["name"=>"张三","i > :hello",":hello"=>"hi"," id in (:in)",":in"=>"1,3,4,5"]
     * @return BaseOperation $this
     * @throws DbFieldError
     */
    protected function where(array $conditions): BaseOperation
    {
        if (!empty($conditions)) {
            $sql = null;
            $join = [];
            reset($conditions);

            foreach ($conditions as $key => &$condition) {
                if (is_array($condition)) {
                    throw new DbFieldError("UnSupport Array Condition: " . json_encode($condition), $key);
                }
                if (is_int($key)) {
                    $isMatched = preg_match_all('/in(\s+)?\((\s+)?(:\w+)\)/', strval($condition), $matches);

                    if ($isMatched) {
                        for ($i = 0; $i < $isMatched; $i++) {
                            $key2 = $matches[3][$i];
                            if (isset($conditions[$key2])) {
                                $value = $conditions[$key2];
                                unset($conditions[$key2]);
                                $values = is_string($value) ? explode(",", $value) : $value;
                                $new = "";
                                $len = sizeof($values);
                                for ($j = 0; $j < $len; $j++) {
                                    $new .= $key2 . "_$j";
                                    $conditions[$key2 . "_$j"] = ($values[$j]);
                                    if ($j !== $len - 1) {
                                        $new .= ",";
                                    }
                                }
                                $condition = str_replace($key2, $new, $condition);
                                //condition改写
                            }

                        }
                    }
                    //识别Like语句
                    $isMatched = preg_match_all('/like\s+([\'"])?(%)?(:[\w]+)(%)?([\'"])?/i', strval($condition), $matches);

                    if ($isMatched) {
                        foreach ($matches[3] as $i => $key2) {
                            if (!isset($conditions[$key2])) {
                                continue;
                            }

                            $value = $conditions[$key2];
                            unset($conditions[$key2]);

                            // 构建新的值，包含通配符
                            $value = ($matches[2][$i] ?? '') . $value . ($matches[4][$i] ?? '');
                            $conditions[$key2] = $value;

                            // 替换原始模式为新的参数名
                            $originalPattern = $matches[1][$i] . $matches[2][$i] . $key2 . $matches[4][$i] . $matches[5][$i];
                            $condition = str_replace($originalPattern, $key2, $condition);
                        }
                    }

                    $join[] = $condition;
                    unset($conditions[$key]);
                    continue;
                }
                $keyRaw = $key;
                $key = str_replace('.', '_', $key);
                if (!str_starts_with($key, ":")) {
                    unset($conditions[$keyRaw]);
                    $conditions[":_WHERE_" . $key] = $condition;
                    $join[] = "`" . str_replace('.', '`.`', $keyRaw) . "` = :_WHERE_" . $key;
                }

            }
            if (!$sql) {
                $sql = join(" AND ", $join);
            }
            $this->opt['where'] = $sql;
            $this->bind_param += $conditions;
        }
        return $this;
    }

    /**
     * 将数据集转换为对象
     
     */
    protected function translate2Model(string $model, array $data): ?array
    {
        if (!class_exists($model)) {
            throw new DbExecuteError("Model Not Found: " . $model);
        }
        $ret = [];
        foreach ($data as $val) {
            $ret[] = new $model($val, true);
        }
        return $ret;
    }

}
