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
}
