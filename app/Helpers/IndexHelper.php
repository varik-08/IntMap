<?php


namespace IntMap\Helpers;


class IndexHelper
{
    public static function indexFor(int $key, int $length): int
    {
        return $key & ($length - 1);
    }
}