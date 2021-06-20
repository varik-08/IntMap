<?php


namespace IntMap\Helpers;


class ValueHelper
{
    public static function getValue(int $value): string
    {
        return str_pad($value, 11, ' ', STR_PAD_LEFT);
    }
}