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
 * Package: cleanphp\base
 * Class Model
 * Created By ankio.
 * Date : 2022/11/14
 * Time : 23:35
 * Description :
 */

namespace nova\plugin\orm\object;

use nova\framework\core\ArgObject;

abstract class Model extends ArgObject
{
    public int $id = 0;
    private bool $fromDb = false;

    /**
     * 获取表结构版本号
     * 当表结构发生变化时，增加版本号可以触发表结构更新
     * @return int 表结构版本号
     */
    public function getSchemaVersion(): int
    {
        return 1; // 默认版本为1，子类可以覆盖此方法
    }

    /**
     * 获取表结构变更SQL
     * 当表结构版本变更时，可以通过此方法提供升级SQL
     * @param  int   $fromVersion 当前版本
     * @param  int   $toVersion   目标版本
     * @return array 包含升级SQL语句的数组
     */
    public function getUpgradeSql(int $fromVersion, int $toVersion): array
    {
        return []; // 默认不需要升级，子类可以覆盖此方法
    }

    public function __construct(array $item = [], $fromDb = false)
    {
        $this->fromDb = $fromDb;
        parent::__construct($item);
    }

    public function onParseType(string $key, mixed &$val, mixed $demo): bool
    {
        if ($this->fromDb && is_string($val) && (is_array($demo) || is_object($demo))) {
            $val = unserialize($val);
        }

        if ($this->fromDb && is_string($demo) && !$this->inNoEscape($key) && is_string($val)) {
            if (empty($val)) {
                $val = $demo;
            }
            $val = htmlspecialchars($val);
        }

        if (!$this->fromDb && (is_array($demo) || is_object($demo)) && is_string($val)) {
            $val = json_decode($val, true);
        }

        return parent::onParseType($key, $val, $demo);
    }

    /**
     * 是否为不不要转义的字段
     * @param       $key
     * @return bool
     */
    private function inNoEscape($key): bool
    {
        return in_array($key, $this->getNoEscape());
    }

    /**
     * 获取不需要转义的字段
     * @return array
     */
    public function getNoEscape(): array
    {
        return [];
    }

    /**
     * 获取唯一字段
     * @return array
     */
    public function getUnique(): array
    {
        return [];
    }

    /**
     * @return bool
     */
    public function isFromDb(): bool
    {
        return $this->fromDb;
    }

    /**
     * 获取主键
     * @return SqlKey
     */
    public function getPrimaryKey(): SqlKey
    {
        return new SqlKey('id', 0, true);
    }

    public function onToArray(string $key, mixed &$value, &$ret): void
    {
        parent::onToArray($key, $value, $ret);
        if (is_array($value) || is_object($value)) {
            $value = serialize($value);
        }
    }

    public function getFullTextKeys(): array
    {
        return [];
    }
}
