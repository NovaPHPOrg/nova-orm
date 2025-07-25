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
 * Package: nova\plugin\orm\exception
 * Class DataBaseDriverNotFound
 * Created By ankio.
 * Date : 2022/11/16
 * Time : 15:42
 * Description :
 */

namespace nova\plugin\orm\exception;

use Exception;
use nova\framework\core\Logger;

class DbFieldError extends Exception
{
    public string $field;

    public function __construct($message = "", $field = "")
    {
        $this->field = $field;
        Logger::error(strip_tags($message));
        Logger::error("Error field: $field");
        parent::__construct($message);
    }
}
