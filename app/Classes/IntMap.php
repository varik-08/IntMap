<?php


namespace IntMap\Classes;

use IntMap\Helpers\HashHelper;
use IntMap\Interfaces\IntMapInterface;

class IntMap implements IntMapInterface
{
    /**
     * @var
     */
    private $shmId;

    /**
     * IntMap constructor.
     * @param $shmId
     */
    public function __construct($shmId)
    {
        $this->shmId = $shmId;

        $this->writeSharedMemory([]);
    }

    /**
     * @param int $key
     * @return int|null
     */
    public function get(int $key): ?int
    {
        $hash = HashHelper::getHash($key);
        $map = $this->readSharedMemory();

        if (isset($map[$hash])) {
            foreach ($map[$hash] as $pair) {
                if ($pair['key'] === $key) {
                    return $pair['value'];
                }
            }
        }

        return null;
    }

    /**
     * @param int $key
     * @param int $value
     * @return int|null
     */
    public function put(int $key, int $value): ?int
    {
        $hash = HashHelper::getHash($key);
        $map = $this->readSharedMemory();

        $newPair = [
            'key' => $key,
            'value' => $value,
        ];

        if (!isset($map[$hash])) {
            $map[$hash] = [
                $newPair,
            ];
        } else {
            foreach ($map[$hash] as $i => $pair) {
                if ($pair['key'] === $newPair['key']) {
                    $oldValue = $pair['value'];
                    $map[$hash][$i] = $newPair;
                    $this->writeSharedMemory($map);

                    return $oldValue;
                }
            }

            //another key with the same hash
            $map[$hash][] = $newPair;
        }

        $this->writeSharedMemory($map);

        return null;
    }

    /**
     * @param int $key
     * @return int|null
     */
    public function del(int $key): ?int
    {
        $hash = HashHelper::getHash($key);
        $map = $this->readSharedMemory();

        if (isset($map[$hash])) {
            foreach ($map[$hash] as $i => $pair) {
                if ($pair['key'] === $key) {
                    unset($map[$hash][$i]);

                    if(count($map[$hash]) === 0) {
                        unset($map[$hash]);
                    }
                    $this->writeSharedMemory($map);

                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * @return array
     */
    private function readSharedMemory(): array
    {
        return unserialize(shmop_read($this->shmId, 0, 0));
    }

    /**
     * @param array $data
     */
    private function writeSharedMemory(array $data): void
    {
        shmop_write($this->shmId, serialize($data), 0);
    }
}