<?php
/*
 * Copyright (c) 2023. Ankio. All Rights Reserved.
 */

/**
 * Package: nova\plugin\orm\exception
 * Class DbConnectError
 * Created By ankio.
 * Date : 2022/11/18
 * Time : 11:13
 * Description :
 */

namespace nova\plugin\orm\exception;


use exception;
use nova\framework\log\Logger;

class DbConnectError extends exception
{
    public function __construct($message, array $error, $tag)
    {
        Logger::error($message);
        Logger::error("Error info: " . implode(" , ", $error));
        parent::__construct($message);
    }
}