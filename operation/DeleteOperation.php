<?php
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

class DeleteOperation extends BaseOperation
{

    public function __construct(&$db,$model)
    {
        parent::__construct($db, $model);
        $this->opt = [];
        $this->opt['type'] = 'delete';
        $this->bind_param = [];

    }

    /**
     * 修改Where语句
     * @param array $conditions
     * @return $this
     */
    public function where(array $conditions): DeleteOperation
    {
        return parent::where($conditions);
    }

    /**
     * 提交查询语句
     */
    public function commit()
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