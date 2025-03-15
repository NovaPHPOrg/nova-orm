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
 * Class DeleteOperation
 * Created By ankio.
 * Date : 2022/11/16
 * Time : 18:19
 * Description :
 */

namespace nova\plugin\orm\operation;

use nova\plugin\orm\exception\DbFieldError;

class DeleteOperation extends BaseOperation
{
    public function __construct(&$db, $model)
    {
        parent::__construct($db, $model);
        $this->opt = [];
        $this->opt['type'] = 'delete';
        $this->bind_param = [];

    }

    /**
     * 修改Where语句
     * @param  array        $conditions
     * @return $this
     * @throws DbFieldError
     */
    public function where(array $conditions): DeleteOperation
    {
        return parent::where($conditions);
    }

    /**
     * 提交查询语句

     */
    public function commit(): int|array
    {
        return parent::__commit();
    }

    protected function translateSql(): void
    {
        $sql = $this->getOpt('DELETE FROM', 'table_name');
        $sql .= $this->getOpt('WHERE', 'where');
        $this->transferSql = $sql . ";";
    }
}
