<?php

require __DIR__ . '/../vendor/autoload.php';

use IntMap\Classes\IntMap;

$shmId = shmop_open(1, "c", 0644, 10000000);

$intMapObj = new IntMap($shmId);

$oldValue = $intMapObj->put(1, 100);
echo 'old: ' . $oldValue . ' new: ' . $intMapObj->get(1);
echo "\n-----------------\n";

$oldValue = $intMapObj->put(1, 200);
echo 'old: ' . $oldValue . ' new: ' . $intMapObj->get(1);
echo "\n-----------------\n";

$delKey = $intMapObj->del(1);
echo 'del: ' . $delKey;
echo "\n-----------------\n";

$delKey = $intMapObj->del(1);
echo 'del: ' . $delKey;
echo "\n-----------------\n";

echo $intMapObj->get(1);
echo "\n-----------------\n";

echo $intMapObj->get(2);
echo "\n-----------------\n";

shmop_close($shmId);