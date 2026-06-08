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
 * Package: nova\plugin\orm\driver
 * Class Postgresql
 * Created By ankio.
 * Date : 2025/06/08
 * Time : 18:00
 * Description : PostgreSQL 数据库驱动实现
 */

namespace nova\plugin\orm\driver;

use nova\plugin\orm\exception\DbConnectError;
use nova\plugin\orm\object\DbConfig;
use nova\plugin\orm\object\Model;
use nova\plugin\orm\object\SqlKey;
use PDO;
use PDOException;

class Postgresql extends Driver
{
    private DbConfig $dbFile;

    /**
     * @throws DbConnectError
     */
    public function __construct(DbConfig $dbFile)
    {
        $this->dbFile = $dbFile;

        // PostgreSQL DSN 格式：pgsql:host=...;dbname=...
        $dsn = "pgsql:host={$this->dbFile->host};port={$this->dbFile->port};dbname={$this->dbFile->db}";

        try {
            $this->pdo = new PDO(
                $dsn,
                $this->dbFile->username,
                $this->dbFile->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]
            );
            // 设置字符集
            $this->pdo->exec("SET NAMES '{$this->dbFile->charset}'");
        } catch (PDOException $e) {
            throw new DbConnectError($e->getMessage(), $e->errorInfo, 'Postgresql');
        }
    }

    /**
     * 渲染创建表 SQL
     * @param  Model  $model
     * @param  string $table
     * @return string
     */
    public function renderCreateTable(Model $model, string $table): string
    {
        $primaryKey = $model->getPrimaryKey();
        $primaryName = $primaryKey->name;

        $sql = 'CREATE TABLE IF NOT EXISTS "' . $table . '" (';

        // PostgreSQL 自增列必须使用 BIGSERIAL
        if ($primaryKey->type === SqlKey::TYPE_INT && $primaryKey->auto) {
            $sql .= '"' . $primaryName . '" BIGSERIAL,';
        } else {
            $sql .= $this->renderKey($primaryKey, $model->getUnique()) . ',';
        }

        foreach (get_object_vars($model) as $key => $value) {
            if ($key === $primaryName) {
                continue;
            }
            $sql .= $this->renderKey(new SqlKey($key, $value), $model->getUnique()) . ',';
        }

        $sql .= 'PRIMARY KEY ("' . $primaryName . '")';

        // 处理唯一的约束
        foreach ($model->getUnique() as $value) {
            if (!is_array($value) || $value === []) {
                continue;
            }
            $sql .= ', UNIQUE ("' . implode('", "', $value) . '")';
        }

        $sql .= ');';

        return $sql;
    }

    /**
     * 渲染列定义 SQL
     * @param  SqlKey $sqlKey
     * @param  array  $unique
     * @return string
     */
    public function renderKey(SqlKey $sqlKey, array $unique = []): string
    {
        if ($sqlKey->type === SqlKey::TYPE_TEXT && $sqlKey->value !== null) {
            // PostgreSQL 使用双引号转义
            $sqlKey->value = str_replace('"', '""', $sqlKey->value);
        }

        $column = '"' . $sqlKey->name . '"';
        $uniqueConstraint = in_array($sqlKey->name, $unique, true) ? ' UNIQUE' : '';

        switch ($sqlKey->type) {
            case SqlKey::TYPE_INT:
                if ($sqlKey->auto) {
                    return $column . ' BIGSERIAL' . $uniqueConstraint;
                } elseif ($sqlKey->length !== 0) {
                    $precision = $sqlKey->length <= 65535 ? 5 : 10;
                    return $column . ' BIGINT DEFAULT ' . intval($sqlKey->value) . $uniqueConstraint;
                } else {
                    return $column . ' BIGINT DEFAULT ' . intval($sqlKey->value ?? 0) . $uniqueConstraint;
                }

                // no break
            case SqlKey::TYPE_FLOAT:
                return $column . ' DECIMAL(19,4)' . $uniqueConstraint;

            case SqlKey::TYPE_BOOLEAN:
                return $column . ' BOOLEAN DEFAULT ' . (boolval($sqlKey->value) ? 'TRUE' : 'FALSE') . $uniqueConstraint;

            case SqlKey::TYPE_ARRAY:
                return $column . ' TEXT DEFAULT NULL' . $uniqueConstraint;

            case SqlKey::TYPE_TEXT:
            default:
                if ($sqlKey->value !== null) {
                    return $column . ' TEXT DEFAULT ' . var_export($sqlKey->value, true) . $uniqueConstraint;
                }
                return $column . ' TEXT DEFAULT NULL' . $uniqueConstraint;
        }
    }

    /**
     * 获取数据库连接
     * @return PDO
     */
    public function getDbConnect(): PDO
    {
        return $this->pdo;
    }

    /**
     * 析构函数 - 释放连接
     */
    public function __destruct()
    {
        unset($this->pdo);
    }

    /**
     * 清空数据表
     * 注意：PostgreSQL 的 TRUNCATE 不能在有外键关联的表上使用，除非指定 CASCADE
     */
    public function renderEmpty(string $table): string
    {
        return 'TRUNCATE TABLE "' . $table . '" CASCADE;';
    }

    /**
     * 处理插入模式
     * @param      $model int 从以下{@link InsertOperation::INSERT_NORMAL}、{@link InsertOperation::INSERT_DUPLICATE}、{@link InsertOperation::INSERT_IGNORE}数据中获取
     * @return int
     */
    public function onInsertModel(int $model): int
    {
        return $model;
    }

    /**
     * INSERT 或 UPDATE 的冲突时处理（ON CONFLICT DO UPDATE）
     * @param  array  $insertColumnNames INSERT 列名顺序
     * @param  array  $updateColumnNames 发生冲突时需要更新的列名
     * @return string
     */
    public function renderInsertOnDuplicateSuffix(array $insertColumnNames, array $updateColumnNames): string
    {
        // PostgreSQL 的 ON CONFLICT 需要指定冲突的目标列
        // 如果没有指定更新列，则使用所有插入列作为冲突目标
        $conflictColumns = $insertColumnNames;

        $parts = [];
        foreach ($updateColumnNames as $name) {
            $parts[] = '"' . $name . '" = EXCLUDED."' . $name . '"';
        }

        return ' ON CONFLICT DO UPDATE SET ' . implode(', ', $parts);
    }

    /**
     * INSERT IGNORE 语句前缀
     * @return string
     */
    public function renderInsertIgnoreLead(): string
    {
        return 'INSERT INTO';
    }
}
