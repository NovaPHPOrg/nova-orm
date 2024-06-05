<?php
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

class DbFieldError extends Exception
{
    public string $field;

    public function __construct($message = "", $field = "")
    {
        $this->field = $field;
        Logger::error($message);
        Logger::error("Error field: $field");
        parent::__construct($message);
    }
}