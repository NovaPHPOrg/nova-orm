<?php

declare(strict_types=1);

namespace nova\plugin\orm\driver;

use nova\framework\core\File;
use nova\plugin\orm\exception\DbConnectError;
use nova\plugin\orm\object\DbConfig;
use nova\plugin\orm\object\Model;
use nova\plugin\orm\object\SqlKey;
use PDO;
use PDOException;

class Sqlite extends Driver
{
    private DbConfig $dbFile;

    /**
     * @throws DbConnectError
     */
    public function __construct(DbConfig $dbFile)
    {
        $this->dbFile = $dbFile;

        // 使用框架既有常量与工具，定位本地存储目录
        $storageDir = RUNTIME_PATH . DS . 'storage';
        File::mkDir($storageDir);

        $dbName = trim($this->dbFile->db);
        if ($dbName === '') {
            $dbName = 'database.sqlite';
        }

        // 绝对路径（含盘符或以分隔符开头）直接使用，否则相对 storage
        // 避免正则分隔符与转义歧义，改用显式字符判断
        $isWindowsAbs = (bool)(strlen($dbName) >= 3
            && ctype_alpha($dbName[0])
            && $dbName[1] === ':'
            && ($dbName[2] === '\\' || $dbName[2] === '/'));
        $isUnixAbs = str_starts_with($dbName, DIRECTORY_SEPARATOR);
        $dbPath = ($isWindowsAbs || $isUnixAbs) ? $dbName : ($storageDir . DIRECTORY_SEPARATOR . $dbName);

        // 确保父目录存在（交由框架工具处理）
        $parentDir = dirname($dbPath);
        File::mkDir($parentDir);

        try {
            $this->pdo = new PDO('sqlite:' . $dbPath);
            // 基本推荐设置：启用外键、异常错误模式
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        } catch (PDOException $e) {
            throw new DbConnectError($e->getMessage(), $e->errorInfo, 'Sqlite');
        }
    }

    /**
     * 渲染创建字段
     * @param  Model  $model
     * @param  string $table
     * @return string
     */
    public function renderCreateTable(Model $model, string $table): string
    {
        $primaryKey = $model->getPrimaryKey();
        $primaryName = $primaryKey->name;

        $sql = 'CREATE TABLE IF NOT EXISTS `' . $table . '`(';

        // SQLite 对自增主键的要求：必须为 INTEGER PRIMARY KEY（可选 AUTOINCREMENT）且只能内联定义
        $inlinePrimary = false;
        if ($primaryKey->type === SqlKey::TYPE_INT && $primaryKey->auto) {
            $sql .= '`' . $primaryName . '` INTEGER PRIMARY KEY AUTOINCREMENT,';
            $inlinePrimary = true;
        } else {
            $sql .= $this->renderKey($primaryKey, $model->getUnique()) . ',';
        }

        foreach (get_object_vars($model) as $key => $value) {
            if ($key === $primaryName) {
                continue;
            }
            $sql .= $this->renderKey(new SqlKey($key, $value), $model->getUnique()) . ',';
        }

        if (!$inlinePrimary) {
            $sql .= 'PRIMARY KEY (' . '`' . $primaryName . '`' . ')';
        } else {
            // 去掉末尾多余逗号
            $sql = rtrim($sql, ',');
        }

        // 处理联合唯一键（数组项）
        foreach ($model->getUnique() as $value) {
            if (is_array($value) && !empty($value)) {
                $sql .= ', UNIQUE (' . '`' . join('`,`', $value) . '`' . ')';
            }
        }

        $sql .= ');';
        return $sql;
    }

    /**
     * 渲染键值
     * 为保持调用兼容性，允许可选的 $unique 参数
     */
    public function renderKey(SqlKey $sqlKey, array $unique = []): string
    {
        if ($sqlKey->type === SqlKey::TYPE_TEXT && $sqlKey->value !== null) {
            $sqlKey->value = str_replace("'", "''", (string)$sqlKey->value);
        }

        // 单字段唯一约束内联处理
        $isUniqueSingle = in_array($sqlKey->name, $unique, true);

        if ($sqlKey->type === SqlKey::TYPE_INT && $sqlKey->auto) {
            // 非主键位置的自增在 SQLite 中没有意义，这里按普通整型处理
            return '`' . $sqlKey->name . '` INTEGER';
        } elseif ($sqlKey->type === SqlKey::TYPE_INT) {
            $default = is_numeric($sqlKey->value) ? (int)$sqlKey->value : 0;
            return '`' . $sqlKey->name . "` INTEGER DEFAULT $default" . ($isUniqueSingle ? ' UNIQUE' : '');
        } elseif ($sqlKey->type === SqlKey::TYPE_BOOLEAN) {
            $default = (int)($sqlKey->value ? 1 : 0);
            return '`' . $sqlKey->name . "` INTEGER DEFAULT $default" . ($isUniqueSingle ? ' UNIQUE' : '');
        } elseif ($sqlKey->type === SqlKey::TYPE_TEXT && $sqlKey->length !== 0) {
            // SQLite 对长度不严格，统一使用 TEXT
            $default = $sqlKey->value !== null ? "'{$sqlKey->value}'" : 'NULL';
            return '`' . $sqlKey->name . '` TEXT DEFAULT ' . $default . ($isUniqueSingle ? ' UNIQUE' : '');
        } elseif ($sqlKey->type === SqlKey::TYPE_TEXT && $sqlKey->length === 0 && $sqlKey->value !== null || $sqlKey->type === SqlKey::TYPE_ARRAY) {
            return '`' . $sqlKey->name . '` TEXT DEFAULT NULL' . ($isUniqueSingle ? ' UNIQUE' : '');
        } elseif ($sqlKey->type === SqlKey::TYPE_TEXT && $sqlKey->length === 0 && $sqlKey->value === null) {
            return '`' . $sqlKey->name . '` TEXT DEFAULT NULL' . ($isUniqueSingle ? ' UNIQUE' : '');
        } elseif ($sqlKey->type === SqlKey::TYPE_FLOAT) {
            $default = is_numeric($sqlKey->value) ? (float)$sqlKey->value : 0.0;
            return '`' . $sqlKey->name . "` REAL DEFAULT $default" . ($isUniqueSingle ? ' UNIQUE' : '');
        } else {
            return '`' . $sqlKey->name . '` TEXT DEFAULT NULL' . ($isUniqueSingle ? ' UNIQUE' : '');
        }
    }

    public function getDbConnect(): PDO
    {
        return $this->pdo;
    }

    public function __destruct()
    {
        unset($this->pdo);
    }

    public function renderEmpty(string $table): string
    {
        return 'DELETE FROM `' . $table . '`;';
    }

    public function onInsertModel(int $model): int
    {
        return $model;
    }
}
