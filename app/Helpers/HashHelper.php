<?php


namespace IntMap\Helpers;


class HashHelper
{
    public static function getHash(int $key): string
    {
        return hash('md5', $key);
    }
}