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
 * Package: nova\plugin\orm\object
 * Class PrimaryKey
 * Created By ankio.
 * Date : 2022/11/15
 * Time : 20:59
 * Description :
 */

namespace nova\plugin\orm\object;

class SqlKey
{
    public const TYPE_INT = 0;
    public const TYPE_FLOAT = 1;
    public const TYPE_TEXT = 2;
    public const TYPE_BOOLEAN = 3;
    public const TYPE_ARRAY = 4;

    public string $name;//键名
    public int $type;//类型
    public bool $auto;//是否自增
    public int $length;//字符长度
    public $value;

    /**
     * @param string     $name          键名
     * @param mixed|null $default_value 默认参数
     * @param int        $length        字符长度，仅默认参数类型为{@link string}生效
     * @param bool       $auto          是否自增，仅默认参数类型为{@link int}生效
     */
    public function __construct(string $name, mixed $default_value = null, bool $auto = false, int $length = 0)
    {
        $this->name = $name;
        $this->auto = false;
        $this->length = 0;
        $this->value = $default_value;
        if (is_int($default_value)) {
            $this->type = self::TYPE_INT;
            $this->auto = $auto;
        } elseif (is_string($default_value)) {
            $this->type = self::TYPE_TEXT;
            $this->length = $length;
        } elseif (is_bool($default_value)) {
            $this->type = self::TYPE_BOOLEAN;
        } elseif (is_float($default_value)) {
            $this->type = self::TYPE_FLOAT;
        } elseif (is_double($default_value)) {
            $this->type = self::TYPE_FLOAT;
        } elseif (is_array($default_value) || is_object($default_value)) {
            $this->type = self::TYPE_ARRAY;
            $this->value = serialize($default_value);
        } else {
            $this->type = self::TYPE_TEXT;
        }

    }
}
