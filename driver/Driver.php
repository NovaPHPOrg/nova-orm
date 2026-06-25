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
 * Class Driver
 * Created By ankio.
 * Date : 2022/11/15
 * Time : 21:48
 * Description :
 */

namespace nova\plugin\orm\driver;

use nova\plugin\orm\object\DbConfig;
use nova\plugin\orm\object\Model;
use nova\plugin\orm\object\SqlKey;
use PDO;

abstract class Driver
{
    protected ?PDO $pdo = null;

    /**
     * @param DbConfig $dbFile 数据库配置类型
     */
    abstract public function __construct(DbConfig $dbFile);

    /**
     * 主键渲染
     * @param  Model  $model
     * @param  string $table
     * @return string
     */
    abstract public function renderCreateTable(Model $model, string $table): string;

    /**
     * 渲染键值
     * @param  SqlKey $sqlKey
     * @return mixed
     */
    abstract public function renderKey(SqlKey $sqlKey): mixed;

    /**
     * 获取数据库链接
     * @return PDO
     */
    abstract public function getDbConnect(): PDO;

    /**
     * 清空数据表
     * @param        $table string 表格
     * @return mixed
     */
    abstract public function renderEmpty(string $table): mixed;

    /**
     * 处理插入模式
     * @param      $model int 从以下{@link InsertOperation::INSERT_NORMAL}、{@link InsertOperation::INSERT_DUPLICATE}、{@link InsertOperation::INSERT_IGNORE}数据中获取
     * @return int
     */
    abstract public function onInsertModel(int $model): int;

    /**
     * INSERT 冲突时追加子句（MySQL: ON DUPLICATE KEY UPDATE；SQLite: ON CONFLICT(...) DO UPDATE SET ...）
     *
     * @param array $insertColumnNames INSERT 列名顺序（与 VALUES 一致）
     * @param array $updateColumnNames 发生冲突时需要更新的列名
     */
    abstract public function renderInsertOnDuplicateSuffix(array $insertColumnNames, array $updateColumnNames): string;

    /**
     * INSERT IGNORE 语句中表名前的关键字（MySQL: INSERT IGNORE INTO；SQLite: INSERT OR IGNORE INTO）
     */
    abstract public function renderInsertIgnoreLead(): string;

    /**
     * INSERT IGNORE 语句后缀（PostgreSQL: ON CONFLICT DO NOTHING）
     */
    public function renderInsertIgnoreSuffix(): string
    {
        return '';
    }

    /**
     * 引用单个标识符（表名、列名）
     */
    public function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * 引用带点号的标识符（如 table.column）
     */
    public function quoteQualifiedIdentifier(string $name): string
    {
        $parts = explode('.', $name);

        return implode('.', array_map(fn (string $part): string => $this->quoteIdentifier($part), $parts));
    }

    /**
     * 渲染 LIMIT 子句（MySQL: LIMIT offset,count）
     */
    public function renderLimitClause(?string $limit): string
    {
        if ($limit === null || $limit === '') {
            return '';
        }

        return ' LIMIT ' . $limit . ' ';
    }

    /**
     * 渲染 DROP TABLE 语句
     */
    public function renderDropTable(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quoteIdentifier($table);
    }

    /**
     * 将 Model::getUpgradeSql() 中的 MySQL 风格标识符转为当前驱动可识别的形式。
     * 子类可覆盖以处理方言差异（如 PostgreSQL 不支持 ADD COLUMN ... COMMENT）。
     */
    public function normalizeUpgradeSql(string $sql): string
    {
        if (!str_contains($sql, '`')) {
            return $sql;
        }

        return preg_replace_callback(
            '/`([^`]+)`/',
            fn (array $matches): string => $this->quoteIdentifier($matches[1]),
            $sql,
        ) ?? $sql;
    }
}
