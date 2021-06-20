<?php

require __DIR__ . '/../vendor/autoload.php';

use IntMap\Classes\IntMap;

$shmId = shmop_open(1, "c", 0644, 100000000);

$intMapObj = new IntMap($shmId);

$count = 50000;

for ($i = 0; $i < $count; $i++) {
    $intMapObj->put($i, $i * 100 + 1);
}

for ($i = $count / 2; $i < $count; $i++) {
    $intMapObj->del($i);
}

for ($i = 0; $i < $count / 2; $i++) {
    $intMapObj->put($i, $i * 100 + 2);
}

for ($i = 0; $i < $count; $i++) {
    echo $i . ': ' . $intMapObj->get($i);
    echo "\n-----------------\n";
}

shmop_delete($shmId);
shmop_close($shmId);