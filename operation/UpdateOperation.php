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
 * Class UpdateOperation
 * Created By ankio.
 * Date : 2022/11/16
 * Time : 18:19
 * Description :
 */

namespace nova\plugin\orm\operation;

class UpdateOperation extends BaseOperation
{
    /**
     * 初始化
     */
    public function __construct(&$db, $m)
    {
        parent::__construct($db, $m);
        $this->opt = [];
        $this->opt['type'] = 'update';
        $this->bind_param = [];
    }

    /**
     * 设置条件
     * @param  array           $conditions
     * @return UpdateOperation
     */
    public function where(array $conditions): UpdateOperation
    {
        return parent::where($conditions);
    }

    /**
     * 设置更新字段信息
     * @param        $row array
     * @return $this
     */
    public function set(array $row): UpdateOperation
    {
        $values = [];
        $set = '';
        foreach ($row as $k => $v) {
            if (is_int($k)) {
                $set .= $v . ',';
                continue;
            }
            $values[":_UPDATE_" . $k] = $v;
            $set .= "`{$k}` = " . ":_UPDATE_" . $k . ',';
        }
        $set = rtrim($set, ",");
        $this->bind_param += $values;
        $this->opt['set'] = $set;
        return $this;
    }

    public function commit()
    {
        return parent::__commit();
    }

    /**
     * 编译
     */
    protected function translateSql(): void
    {
        $sql = $this->getOpt('UPDATE', 'table_name');
        $sql .= $this->getOpt('SET', 'set');
        $sql .= $this->getOpt('WHERE', 'where');
        $this->transferSql = $sql . ";";
    }
}
