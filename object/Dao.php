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
 * Package: nova\plugin\orm\object
 * Class dao
 * Created By ankio.
 * Date : 2022/11/15
 * Time : 21:15
 * Description :
 */

namespace nova\plugin\orm\object;

use nova\framework\core\Context;
use nova\framework\core\Logger;
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

        if (!empty($model)) {
            $this->model = $model;
        } elseif (!empty($child)) {
            $class = str_replace(["dao", "Dao"], ["model", "Model"], $child);
            $this->child = $child;
            if (class_exists($class)) {
                $this->model = $class;
            }
        }
        $this->initTable();
    }

    /**
     * 初始化表结构
     * 检查表是否存在，不存在则创建
     * @return bool 是否成功初始化
     */
    public function initTable(): bool
    {
        if (empty($this->model)) {
            return false;
        }

        $cache = Context::instance()->cache;
        $table = $this->getTable();
        $versionKey = "table_version_" . $table;
        $modelClass = $this->model;
        $model = new $modelClass();
        $currentVersion = $model->getSchemaVersion();

        // 获取缓存中的版本号
        $cachedVersion = $cache->get($versionKey);

        Logger::info("Model class: " . $this->model . " (version: " . $currentVersion . ")");

        // 非调试模式下，有缓存版本就认为表存在
        if (!Context::instance()->isDebug() && $cachedVersion !== null) {
            // 只在版本不一致时进行升级
            if ($cachedVersion < $currentVersion) {
                return $this->upgradeTable($model, $cachedVersion, $currentVersion, $versionKey);
            }
            return true;
        }

        // 调试模式或无缓存时，检查表是否存在
        try {
            $result = $this->db->getDriver()->getDbConnect()->query(/** @lang text */ "SELECT count(*) FROM `{$table}` LIMIT 1");
            $table_exist = $result instanceof PDOStatement && ($result->rowCount() === 1);
        } catch (Throwable $exception) {
            if ($exception instanceof AppExitException) {
                throw $exception;
            }
            $table_exist = false;
        }

        // 表不存在，需要创建
        if (!$table_exist) {
            try {
                $this->db->initTable($this, $model, trim($table, '`'));
                $cache->set($versionKey, $currentVersion);
                Logger::info("Initialize table {$table}: ");
                return true;
            } catch (Throwable $e) {
                Logger::alert("Failed to initialize table {$table}: " . $e->getMessage(), $e->getTrace());
                return false;
            }
        }

        // 表存在，检查是否需要升级
        if ($cachedVersion === null || $cachedVersion < $currentVersion || Context::instance()->isDebug()) {
            return $this->upgradeTable($model, $cachedVersion ?? 1, $currentVersion, $versionKey);
        }

        return true;
    }

    /**
     * 升级表结构（按版本 +1 顺序执行）
     *
     * @param Model  $model       模型实例，需实现 getUpgradeSql($from, $to)：返回 [
     *                            "1_2" => [ 'ALTER ...', ... ],
     *                            "2_3" => [ ... ],
     *                            …
     *                          ]
     * @param int    $fromVersion 当前版本（缓存中读到）
     * @param int    $toVersion   目标版本（配置里要求的最新版本）
     * @param string $versionKey  缓存版本号的键名，用于升级后写入缓存
     * @return bool   是否升级成功
     */
    public function upgradeTable(Model $model, int $fromVersion, int $toVersion, string $versionKey): bool
    {
        // 如果已经是最新，且非调试模式，直接返回
        if ($fromVersion >= $toVersion && !Context::instance()->isDebug()) {
            return true;
        }

        Logger::info("Upgrading table {$this->getTable()} from v{$fromVersion} to v{$toVersion}");

        // 拿到所有 fromVersion → toVersion 范围内的脚本
        $allUpgradeSql = $model->getUpgradeSql($fromVersion, $toVersion);
        // 如果没有任何脚本，直接更新版本号并返回
        if (empty($allUpgradeSql)) {
            Context::instance()->cache->set($versionKey, $toVersion);
            return true;
        }

        $this->transactionBegin();
        try {
            // 按 +1 顺序依次执行脚本
            for ($v = $fromVersion; $v < $toVersion; $v++) {
                $nextKey = "{$v}_" . ($v + 1);
                if (!isset($allUpgradeSql[$nextKey])) {
                    throw new \RuntimeException("Missing upgrade script for version {$v} → " . ($v + 1));
                }
                Logger::info("Executing upgrade from v{$v} to v" . ($v + 1));
                foreach ($allUpgradeSql[$nextKey] as $sql) {
                    $this->execute($sql);
                }
            }

            $this->transactionCommit();

            // 全部成功后，把版本号写入缓存
            Context::instance()->cache->set($versionKey, $toVersion);
            Logger::info("Table {$this->getTable()} successfully upgraded to v{$toVersion}");

            return true;
        } catch (\Throwable $e) {
            $this->transactionRollBack();
            Logger::alert("Failed to upgrade table {$this->getTable()}: " . $e->getMessage());
            return false;
        }
    }


    /**
     * 数据库初始化
     * @param  DbFile|null $dbFile
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
    public static function getInstance($user_key = null): Dao
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
     * @param       $key_name
     * @param       $key_value
     * @param       $set_key
     * @param       $set_value
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
    public function update(): UpdateOperation
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
        // 如果已经设置了表名，直接返回
        if (!empty($this->table)) {
            return $this->table;
        }

        // 从类名获取表名
        $className = get_class($this);
        if (preg_match('/(\w+)Dao$/', $className, $matches)) {
            $tableName = strtolower($matches[1]);

            // 添加用户键前缀（如果存在）
            if (!empty($this->user_key)) {
                $tableName = $tableName . "_" . md5($this->user_key);
            }

            $this->table = $tableName;
            return $this->table;
        }

        throw new DbExecuteError("Invalid DAO class name format. Class name must end with 'Dao'");
    }

    /**
     * 获取指定条件下的数据量
     * @return int|mixed
     */
    public function getCount($condition = []): mixed
    {
        return $this->select()->count($condition);
    }

    /**
     * 查找
     * @param                              ...$field string|Field 需要查询的字段
     * @return SelectOperation
     * @throws DbFieldError|DbExecuteError
     */
    public function select(...$field): SelectOperation
    {
        return (new SelectOperation($this->db, $this->model, ...$field))->table($this->getTable());
    }

    /**
     * 获取指定参数的求和
     * @param array  $condition
     * @param string $field
     */
    public function getSum(array $condition = [], string $field = "id")
    {
        return $this->select()->sum($condition, $field);
    }

    /**
     * 删除当前表
     * @return array|int
     */
    public function dropTable(): int|array
    {
        return $this->db->execute("DROP TABLE IF EXISTS `{$this->getTable()}`");
    }

    /**
     * 数据库执行
     * @param  string    $sql      需要执行的sql语句
     * @param  array     $params   绑定的sql参数
     * @param  false     $readonly 是否为查询
     * @return array|int
     */
    public function execute(string $sql, array $params = [], bool $readonly = false): int|array
    {
        return $this->db->execute($sql, $params, $readonly);
    }

    /**
     * 清空当前表
     * @return array|int
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
     * @param  Model $model
     * @param  bool  $autoUpdate 是否自动更新
     * @return int
     */
    public function insertModel(Model $model, bool $autoUpdate = false): int
    {
        $primary = $this->getAutoPrimary($model);//自增主键不去赋值
        $unique = $model->getUnique();
        $kv = $model->toArray();
        if ($primary !== null) {
            if (isset($kv[$primary])) {
                unset($kv[$primary]);
            }
        }
        if (!$autoUpdate) {
            return (int)$this->insert()->keyValue($kv)->commit();
        } else {

            $kvKeys = array_keys($kv);
            $kvKeys = array_diff($kvKeys, $unique);

            return (int)$this->insert(InsertOperation::INSERT_DUPLICATE)->keyValue($kv, $kvKeys)->commit();
        }
    }

    /**
     * 获取自增主键
     * @param  Model       $old_model
     * @return string|null
     */
    private function getAutoPrimary(Model $old_model): ?string
    {
        $primaryKey = $old_model->getPrimaryKey();
        $primary_keys = $primaryKey instanceof SqlKey ? [$primaryKey] : $primaryKey;

        if (!is_array($primary_keys)) {
            return null;
        }

        foreach ($primary_keys as $value) {
            if ($value instanceof SqlKey && $value->auto) {
                return $value->name;
            }
        }
        return null;
    }

    /**
     * 插入语句
     * @param  int             $model
     * @return InsertOperation
     */
    public function insert(int $model = InsertOperation::INSERT_NORMAL): InsertOperation
    {
        return (new InsertOperation($this->db, $this->model, $model))->table($this->getTable());
    }

    /**
     * 更新模型
     * @param  Model      $new_model 新的模型
     * @param  Model|null $old_model 旧的模型
     * @return bool
     */
    public function updateModel(Model $new_model, Model $old_model = null): bool
    {
        if ($old_model == null) {
            $condition = $this->getPrimaryCondition($new_model);
        } else {
            $condition = $this->getPrimaryCondition($old_model);
        }
        if ($this->find(new Field("id"), $condition) == null) {
            return false;
        }
        //获取到更新数据的条件
        $this->update()->where($condition)->set($new_model->toArray())->commit();
        return true;
    }

    /**
     * 获取主键数组
     * @param  Model $old_model
     * @return array
     */
    private function getPrimaryCondition(Model $old_model): array
    {
        $primaryKey = $old_model->getPrimaryKey();
        $primary_keys = $primaryKey instanceof SqlKey ? [$primaryKey] : $primaryKey;

        if (!is_array($primary_keys)) {
            return [];
        }

        $condition = [];
        foreach ($primary_keys as $value) {
            if ($value instanceof SqlKey) {
                $name = $value->name;
                $condition[$name] = $old_model->$name;
            }
        }
        return $condition;
    }

    /**
     * 删除模型
     * @param  Model $model
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
    public function delete(): DeleteOperation
    {
        return (new DeleteOperation($this->db, $this->model))->table($this->getTable());
    }

    /**
     * 查找单个数据
     * @param  ?Field     $field     字段构造
     * @param  array      $condition 查询条件
     * @return mixed|null
     */
    public function find(Field $field = null, array $condition = []): mixed
    {
        if ($field === null) {
            $field = new Field();
        }

        $result = $this->select($field)->where($condition)->limit()->commit();
        if (!empty($result)) {
            return $result[0];
        }
        return null;
    }

    /**
     * 事务开始
     */
    public function transactionBegin(): void
    {
        $this->db->connection()->beginTransaction();
    }

    /**
     * 事务回滚
     */
    public function transactionRollBack(): void
    {
        $this->db->connection()->rollBack();
    }

    /**
     * 事务提交
     */
    public function transactionCommit(): void
    {
        $this->db->connection()->commit();
    }

    /**
     * 是否在事务里面
     * @return bool
     */
    public function inTransaction(): bool
    {
        return  $this->db->connection()->inTransaction();
    }

    /**
     * 获取所有数据
     * @param  array|null                    $fields
     * @param  array                         $where
     * @param  int|null                      $start
     * @param  int                           $size
     * @param  bool                          $page
     * @param  array|string|null             $orderBy
     * @return array
     * @throws DbFieldError|AppExitException
     */
    public function getAll(?array $fields = [], array $where = [], ?int $start = null, int $size = 10, bool $page = false, array|string $orderBy = null): array
    {
        $ret = [
            "data" => [],
        ];
        if ($size > 1000) {
            $size = 1000;
        }
        $total = 0;
        if ($fields === null) {
            $fields = [];
        }
        if ($start === null) {
            $result = $this->select(...$fields)->where($where)->commit();
        } elseif (!empty($orderBy)) {
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
        $ret['data'] = $result;
        return $ret;

    }

    /**
     * 判断是否存在
     * @param  array $where
     * @return bool
     */
    public function exists(array $where = []): bool
    {
        return $this->select(new Field("id"))->where($where)->limit()->commit() != null;
    }

}
