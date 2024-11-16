<?php
declare(strict_types=1);
/*
 * Copyright (c) 2023. Ankio. All Rights Reserved.
 */

/**
 * Package: nova\plugin\orm\object
 * Class dao
 * Created By ankio.
 * Date : 2022/11/15
 * Time : 21:15
 * Description :
 */

namespace nova\plugin\orm\object;

use nova\framework\App;
use nova\framework\cache\Cache;
use nova\framework\exception\AppExitException;
use nova\plugin\orm\Db;
use nova\plugin\orm\exception\DbExecuteError;
use nova\plugin\orm\exception\DbFieldError;
use nova\plugin\orm\operation\DeleteOperation;
use nova\plugin\orm\operation\InsertOperation;
use nova\plugin\orm\operation\SelectOperation;
use nova\plugin\orm\operation\UpdateOperation;
use PDOStatement;
use Throwable;

abstract class Dao
{

    protected ?Db $db = null;
    protected ?string $model = null;//具体的模型
    protected string $table = "";
    private ?string $child = null;
    private ?string $user_key = null;

    /**
     * @param string|null $model 指定具体模型
     */
    public function __construct(string $model = null, string $child = null, $user_key = null)
    {
        $this->user_key = $user_key;
        $this->dbInit();
        $cache = new Cache();
        if (!empty($model)) {
            $this->model = $model;
        } elseif (!empty($child)) {
            $class = str_replace(["dao", "Dao"], ["model", "Model"], $child);
            $this->child = $child;
            if (class_exists($class)) {

                $this->model = $class;
                $table = $this->getTable();
                $key = "table_" . $table;
                if ($cache->get($key) == null || App::getInstance()->debug) {
                    try {
                        $result = $this->db->getDriver()->getDbConnect()->query(/** @lang text */ "SELECT count(*) FROM `{$table}` LIMIT 1");
                        $table_exist = $result instanceof PDOStatement && ($result->rowCount() === 1);
                    } catch (Throwable $exception) {
                        if ($exception instanceof AppExitException) {
                            throw $exception;
                        }
                        $table_exist = false;
                    }
                    if (!$table_exist) {
                        $this->db->initTable($this, new $class, trim($table, '`'));
                        $cache->set("table_" . $table, true);
                    }
                }

            }
        }

    }


    /**
     * 数据库初始化
     * @param DbFile|null $dbFile
     * @return void
     */
    protected function dbInit(?DbFile $dbFile = null): void
    {
        $this->db = Db::getInstance($dbFile);
    }

    private static $instances = [];

    /**
     * 获取数据库实例
     * @return $this
     */
    static function getInstance($user_key = null): Dao
    {
        $cls = get_called_class();
        $instance = self::$instances[$cls] ?? null;
        if (empty($instance)) {
            $instance = new static(null, $cls, $user_key);
            self::$instances[$cls] = $instance;
        }
        return $instance;
    }

    /**
     * 设置单项
     * @param $key_name
     * @param $key_value
     * @param $set_key
     * @param $set_value
     * @return void
     */
    public function setOption($key_name, $key_value, $set_key, $set_value): void
    {
        $this->update()->set([$set_key => $set_value])->where([$key_name => $key_value])->commit();
    }

    /**
     * 更新
     * @return UpdateOperation
     */
    protected function update(): UpdateOperation
    {
        return (new UpdateOperation($this->db, $this->model))->table($this->getTable());
    }

    /**
     * 当前操作的表
     * @return string
     * @throws DbExecuteError
     */
    public function getTable(): string
    {
        if (!empty($this->table)) return $this->table;
        if (!empty($this->child)) {
            $array = explode("\\", $this->child);
            $class = str_replace("Dao", "", end($array));
            $pattern = '/(?<=[a-z])([A-Z])/';
            $replacement = '_$1';
            $this->table = strtolower(preg_replace($pattern, $replacement, $class));
            if (!empty($this->user_key)) {
                $this->table = $this->table . "_" . md5($this->user_key);
            }
            return $this->table;
        }
        throw new DbExecuteError("Unknown table name");
    }

    /**
     * 获取指定条件下的数据量
     * @return int|mixed
     */
    function getCount($condition = []): mixed
    {
        return $this->select()->count($condition);
    }

    /**
     * 查找
     * @param ...$field string|Field 需要查询的字段
     * @return SelectOperation
     * @throws DbFieldError|DbExecuteError
     */
    protected function select(...$field): SelectOperation
    {
        return (new SelectOperation($this->db, $this->model, ...$field))->table($this->getTable());
    }

    /**
     * 获取指定参数的求和
     * @param array $condition
     * @param string $field
     */
    function getSum(array $condition = [], string $field = "id")
    {
        return $this->select()->sum($condition, $field);
    }

    /**
     * 删除当前表
     * @return array|int
     * @throws DbExecuteError
     */
    public function dropTable(): int|array
    {
        return $this->db->execute("DROP TABLE IF EXISTS `{$this->getTable()}`");
    }

    /**
     * 数据库执行
     * @param string $sql 需要执行的sql语句
     * @param array $params 绑定的sql参数
     * @param false $readonly 是否为查询
     * @return array|int
     * @throws DbExecuteError
     */
    protected function execute(string $sql, array $params = [], bool $readonly = false): int|array
    {
        return $this->db->execute($sql, $params, $readonly);
    }

    /**
     * 清空当前表
     * @return array|int
     * @throws DbExecuteError
     */
    public function emptyTable(): int|array
    {
        return $this->db->execute($this->db->getDriver()->renderEmpty($this->getTable()));
    }

    /**
     * 当表被创建的时候
     * @return void
     */
    public function onCreateTable()
    {
    }

    /**
     * 插入模型
     * @param Model $model
     * @param bool $autoUpdate 是否自动更新
     * @return int
     */
    public function insertModel(Model $model, bool $autoUpdate = false): int
    {
        $primary = $this->getAutoPrimary($model);//自增主键不去赋值
        $unique = $model->getUnique();
        $kv = $model->toArray();
        if ($primary !== null) {
            if (isset($kv[$primary])) unset($kv[$primary]);
        }
        if (!$autoUpdate) return (int)$this->insert()->keyValue($kv)->commit();
        else {

            $kvKeys = array_keys($kv);
            $kvKeys = array_diff($kvKeys, $unique);

            return (int)$this->insert(InsertOperation::INSERT_DUPLICATE)->keyValue($kv, $kvKeys)->commit();
        }
    }

    /**
     * 获取自增主键
     * @param Model $old_model
     * @return string|null
     */
    private function getAutoPrimary(Model $old_model): ?string
    {
        $primary_keys = $old_model->getPrimaryKey() instanceof SqlKey ? [$old_model->getPrimaryKey()] : $old_model->getPrimaryKey();
        /**
         * @var $value SqlKey
         */
        foreach ($primary_keys as $value) {
            if ($value->auto) return $value->name;
        }
        return null;
    }

    /**
     * 插入语句
     * @param int $model
     * @return InsertOperation
     */
    protected function insert(int $model = InsertOperation::INSERT_NORMAL): InsertOperation
    {
        return (new InsertOperation($this->db, $this->model, $model))->table($this->getTable());
    }

    /**
     * 更新模型
     * @param Model $new_model 新的模型
     * @param Model|null $old_model 旧的模型
     * @return bool
     */
    public function updateModel(Model $new_model, Model $old_model = null): bool
    {
        if ($old_model == null) {
            $condition = $this->getPrimaryCondition($new_model);
        } else {
            $condition = $this->getPrimaryCondition($old_model);
        }
        if ($this->find(new Field("id"), $condition) == null) return false;
        //获取到更新数据的条件
        $this->update()->where($condition)->set($new_model->toArray())->commit();
        return true;
    }

    /**
     * 获取主键数组
     * @param Model $old_model
     * @return array
     */
    private function getPrimaryCondition(Model $old_model): array
    {
        $primary_keys = $old_model->getPrimaryKey() instanceof SqlKey ? [$old_model->getPrimaryKey()] : $old_model->getPrimaryKey();
        $condition = [];
        /**
         * @var $value SqlKey
         */
        foreach ($primary_keys as $value) {
            //key
            $name = $value->name;
            //获取主键
            $condition[$name] = $old_model->$name;
        }
        return $condition;
    }

    /**
     * 删除模型
     * @param Model $model
     * @return void
     */
    public function deleteModel(Model $model): void
    {
        $condition = $this->getPrimaryCondition($model);
        $this->delete()->where($condition)->commit();
    }

    /**
     * 删除
     * @return DeleteOperation
     */
    protected function delete(): DeleteOperation
    {
        return (new DeleteOperation($this->db, $this->model))->table($this->getTable());
    }

    /**
     * 查找单个数据
     * @param ?Field $field 字段构造
     * @param array $condition 查询条件
     * @return mixed|null
     */
    protected function find(Field $field = null, array $condition = []): mixed
    {
        if ($field === null) $field = new Field();

        $result = $this->select($field)->where($condition)->limit()->commit();
        if (!empty($result)) {
            return $result[0];
        }
        return null;
    }

    /**
     * 事务开始
     */
    protected function affairBegin(): void
    {
        $this->db->execute("BEGIN");
    }

    /**
     * 事务回滚
     */
    protected function affairRollBack(): void
    {
        $this->db->execute("ROLLBACK");
    }

    /**
     * 事务提交
     */
    protected function affairCommit(): void
    {
        $this->db->execute("COMMIT");
    }

    /**
     * 获取所有数据
     * @param array|null $fields
     * @param array $where
     * @param int|null $start
     * @param int $size
     * @param bool $page
     * @param array|string|null $orderBy
     * @return array
     * @throws DbExecuteError
     * @throws DbFieldError|AppExitException
     */
    function getAll(?array $fields = [], array $where = [], ?int $start = null, int $size = 10, bool $page = false, array|string $orderBy = null): array
    {
        $ret = [
            "data" => [],
        ];
        if ($size > 1000) $size = 1000;
        $total = 0;
        if ($fields === null) $fields = [];
        if ($start === null) {
            $result = $this->select(...$fields)->where($where)->commit();
        } else if (!empty($orderBy)) {
            $select = $this->select(...$fields)->page($start, $size)->where($where);

            if (is_array($orderBy)) {
                foreach ($orderBy as $value) {
                    $select->orderBy($value);
                }
            } else {
                $select->orderBy($orderBy);
            }

            $result = $select->commit($total);
        } else {
            $result = $this->select(...$fields)->page($start, $size)->where($where)->commit($total);
        }

        $ret['total'] = $total;
        if ($page) {
            array_walk($result, function (&$value, $key, $arr) {
                $arr['ret']['data'][] = $value->toArray();
            }, ['ret' => &$ret]);
        } else {
            $ret['data'] = $result;
        }
        return $ret;

    }

    /**
     * 判断是否存在
     * @param array $where
     * @return bool
     * @throws DbExecuteError
     */
    public function exists(array $where = []): bool
    {
        return $this->select(new Field("id"))->where($where)->limit()->commit() != null;
    }


}