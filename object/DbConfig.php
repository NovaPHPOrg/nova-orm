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

namespace nova\plugin\orm\object;

use nova\framework\core\ConfigObject;

/**
 * Package: nova\plugin\orm\object
 * Class DbFile
 * Created By ankio.
 * Date : 2022/11/16
 * Time : 15:24
 * Description : 数据库配置文件模板
 */
class DbConfig extends ConfigObject
{
    public string $host = "";
    public string $type = "mysql";
    public int $port = 0;
    public string $username = "";
    public string $password = "";
    public string $db = "";
    public string $charset = "mb4utf8";

}
