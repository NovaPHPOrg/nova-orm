<?php
declare(strict_types=1);
/*
 * Copyright (c) 2023. Ankio. All Rights Reserved.
 */

namespace nova\plugin\orm\object;

use nova\framework\text\ArgObject;

/**
 * Package: nova\plugin\orm\object
 * Class DbFile
 * Created By ankio.
 * Date : 2022/11/16
 * Time : 15:24
 * Description : 数据库配置文件模板
 */
class DbFile extends ArgObject
{
    public string $host = "";
    public string $type = "";
    public int $port = 0;
    public string $username = "";
    public string $password = "";
    public string $db = "";
    public string $charset = "mb4utf8";

    function hash(): string
    {
        return md5($this->host . $this->type . $this->port . $this->username . $this->password . $this->db . $this->charset);
    }
}