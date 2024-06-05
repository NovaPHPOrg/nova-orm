<?php
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



use nova\plugin\orm\Db;
use nova\plugin\orm\exception\DbExecuteError;
use nova\plugin\orm\exception\DbFieldError;

abstract class BaseOperation
{
    protected array $opt = [];//封装常见的数据库查询选项
    protected ?string $transferSql = null;//编译完成的sql语句
    protected array $bind_param = [];//绑定的参数列表

    protected Db $db;

    protected ?string $model;

    protected array $tables = [];

    /**
     * @param $db DB 数据库对象
     * @param $model ?string 数据模型
     */
    public function __construct(Db &$db, string $model = null)
    {
        $this->db = $db;
        $this->model = $model;
    }

    /**
     * 设置表名
     * @param string ...$tableName
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

    /**
     *
     * 提交
     * @return array|int
     */
    protected function __commit($readonly = false): int|array
    {
        if ($this->transferSql == null) $this->translateSql();
        return $this->db->execute($this->transferSql, $this->bind_param, $readonly);
    }

    /**
     * 编译sql语句
     * @return void
     */
    abstract protected function translateSql(): void;

    /**
     * 获取存储的数据选项
     * @param $head
     * @param $opt
     * @return string
     */
    protected function getOpt($head, $opt): string
    {
        if (isset($this->opt[$opt])) return ' ' . $head . ' ' . $this->opt[$opt] . ' ';
        return ' ';
    }

    /**
     * 设置查询条件
     * @param array $conditions 条件内容，必须是数组,格式如下["name"=>"张三","i > :hello",":hello"=>"hi"," id in (:in)",":in"=>"1,3,4,5"]
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
                if (is_array($condition)) throw new DbFieldError("UnSupport Array Condition: " . json_encode($condition), $key);
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
                    $isMatched = preg_match_all('/like\s+(\')?(%)?(:\w+)(%)?(\')?/', strval($condition), $matches);

                    if ($isMatched) {
                        for ($i = 0; $i < $isMatched; $i++) {
                            $left_1 = $matches[1][$i];
                            $key2 = $matches[3][$i];
                            $left = $matches[2][$i];
                            $right = $matches[4][$i];
                            $right_1 = $matches[5][$i];
                            if (isset($conditions[$key2])) {
                                $value = $conditions[$key2];
                                unset($conditions[$key2]);
                                $value = "$left$value$right";
                                $conditions[$key2] = $value;
                                $condition = str_replace("$left_1$left$key2$right$right_1", $key2, $condition);

                                //condition改写
                            }

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
            if (!$sql) $sql = join(" AND ", $join);
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
        if (!class_exists($model)) throw new DbExecuteError("Model Not Found: " . $model);
        $ret = [];
        foreach ($data as $val) {
            $ret[] = new $model($val, true);
        }
        return $ret;
    }

}