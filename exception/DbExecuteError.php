<?php
declare(strict_types=1);
/*
 * Copyright (c) 2023. Ankio. All Rights Reserved.
 */

/**
 * Package: nova\plugin\orm\exception
 * Class DataBaseDriverNotFound
 * Created By ankio.
 * Date : 2022/11/16
 * Time : 15:42
 * Description :
 */

namespace nova\plugin\orm\exception;

use Exception;
use nova\framework\log\Logger;

class DbExecuteError extends Exception
{
    public function __construct($message = "",$sql = "")
    {
        Logger::error($message);
        Logger::error("Error sql: $sql");
        parent::__construct($message);
    }
}