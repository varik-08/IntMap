<?php


namespace IntMap\Helpers;


class HashHelper
{
    public static function getHash(int $key): string
    {
        $key ^= ($key >> 20) ^ ($key >> 12);
        return $key ^ ($key >> 7) ^ ($key >> 4);
    }
}