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
 * Class DbConnectError
 * Created By ankio.
 * Date : 2022/11/18
 * Time : 11:13
 * Description :
 */

namespace nova\plugin\orm\exception;

use exception;
use nova\framework\core\Logger;

class DbConnectError extends exception
{
    public function __construct($message, array $error, $tag)
    {
        Logger::error($message);
        Logger::error("Error info: " . implode(" , ", $error));
        parent::__construct($message);
    }
}
