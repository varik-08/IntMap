<?php


namespace IntMap\Classes;

use IntMap\Helpers\HashHelper;
use IntMap\Helpers\IndexHelper;
use IntMap\Helpers\ValueHelper;
use IntMap\Interfaces\IntMapInterface;

class IntMap implements IntMapInterface
{
    /**
     * Коэффициент увеличения массива
     *
     * @var
     */
    const COEFFICIENT_INCREASE = 2;

    /**
     * Коэффициент загрузки
     *
     * @var
     */
    const LOAD_FACTOR = 0.75;

    /**
     * Зарезервированный размер для конфигурации
     *
     * @var
     */
    const CONF_SIZE = 33;

    /**
     * Зарезервированный размер для значений ключа, значения, ссылки
     *
     * @var
     */
    const VALUE_SIZE = 11;

    /**
     * Размер блока пары ключ-значения
     *
     * @var
     */
    const PAIR_BLOCK_SIZE = 33;

    /**
     * Начальный размер массива
     *
     * @var
     */
    const INIT_CAPACITY = 32;

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

        $this->resize(IntMap::INIT_CAPACITY);
    }

    /**
     * Увеличение размера массива
     *
     * @param int|null $capacity
     */
    private function resize(int $capacity = null): void
    {
        // создаем память при инициализации
        if (isset($capacity)) {
            $threshold = (int)($capacity * IntMap::LOAD_FACTOR);
            $size      = 0;

            // создаем таблицу с начальной конфигурацией
            $this->transfer($capacity, $capacity, $threshold, $size);

            return;
        }

        // обновляем таблицу при увеличении размера массива

        list($oldCapacity, , $size) = $this->getConf();

        // задаем новый размер и порог
        $newCapacity  = $oldCapacity * IntMap::COEFFICIENT_INCREASE;
        $newThreshold = (int)($newCapacity * IntMap::LOAD_FACTOR);

        // трансформируем таблицу с новой конфигурацией
        $this->transfer($oldCapacity, $newCapacity, $newThreshold, $size);
    }

    /**
     * Функция получения текущий конфигурации из разделяемой памяти
     *
     * @return int[]
     */
    private function getConf(): array
    {
        $confBlock = shmop_read(
            $this->shmId,
            0,
            IntMap::CONF_SIZE
        );

        $capacity  = (int)substr($confBlock, 0, IntMap::VALUE_SIZE);
        $threshold = (int)substr($confBlock, IntMap::VALUE_SIZE, IntMap::VALUE_SIZE);
        $size      = (int)substr($confBlock, IntMap::VALUE_SIZE * 2, IntMap::VALUE_SIZE);

        return [
            $capacity,
            $threshold,
            $size
        ];
    }

    /**
     * Трансформирует таблицу под новый размер массива
     *
     * @param $oldCapacity - старый размер блока
     * @param $newCapacity - новый размер блока
     * @param $threshold - порог
     * @param $size - количество записанных пар ключ-значение
     */
    private function transfer(int $oldCapacity, int $newCapacity, int $threshold, int $size): void
    {
        // данные пар ключ-значений
        $dataPairs = $size > 0
            ? shmop_read(
                $this->shmId,
                (IntMap::CONF_SIZE) + ($oldCapacity * IntMap::VALUE_SIZE),
                IntMap::PAIR_BLOCK_SIZE * $size
            )
            : '';

        // очищаем разделяемую память
        $clearStr = str_repeat(
            ' ',
            IntMap::CONF_SIZE + $oldCapacity * IntMap::VALUE_SIZE + $size * IntMap::PAIR_BLOCK_SIZE
        );
        shmop_write($this->shmId, $clearStr, 0);

        // новая конфигурация
        $newConf = ValueHelper::getValue($newCapacity) .
            ValueHelper::getValue($threshold) .
            ValueHelper::getValue($size);

        // создание нового блока данных пар ключ-знаачений

        // обход пар ключ-значений
        $sizeValue = IntMap::PAIR_BLOCK_SIZE;
        preg_match_all("/.{0,$sizeValue}/", $dataPairs, $matches);
        $arrayPairs = array_values(
            array_filter(
                $matches[0],
                function ($value) {
                    return trim($value) !== '';
                }
            )
        );

        // новые ссылки первых пар ключ-значений
        $newFirstPairAddress = str_repeat(' ', $newCapacity * IntMap::VALUE_SIZE);

        // новый порядок пар ключ-значений
        $newPairs = '';

        // ссылка на следующую пару ключ-значения в корзине
        $addressNextPair = IntMap::CONF_SIZE + $newCapacity * IntMap::VALUE_SIZE;

        foreach ($arrayPairs as $pair) {
            // функция обработки пары ключ-значение
            $pairProcessing = function ($pair) use (
                $newCapacity,
                &$newFirstPairAddress,
                &$addressNextPair,
                &$newPairs
            ) {
                $key   = (int)substr($pair, 0, IntMap::VALUE_SIZE);
                $value = (int)substr($pair, IntMap::VALUE_SIZE, IntMap::VALUE_SIZE);

                // вычисляем новый индекс
                $hash  = HashHelper::getHash($key);
                $index = IndexHelper::indexFor($hash, $newCapacity);

                // вычисляем адрес первой пары по индексу
                $indexInMemory    = $index * IntMap::VALUE_SIZE;
                $firstPairAddress = substr($newFirstPairAddress, $indexInMemory, IntMap::VALUE_SIZE);

                // проверяем, есть ли пара по данному индексу в памяти
                if (trim($firstPairAddress) === '') {
                    // записываем адрес первой пары в блок
                    $newFirstPairAddress = substr_replace(
                        $newFirstPairAddress,
                        ValueHelper::getValue($addressNextPair),
                        $indexInMemory,
                        IntMap::VALUE_SIZE
                    );
                } else {
                    // обработка коллизий

                    // функция поиска адреса для пары
                    $addressSearching = function (int $address) use (
                        &$newPairs,
                        $newCapacity,
                        $addressNextPair,
                        &$addressSearching
                    ) {
                        $relatedPair = substr(
                            $newPairs,
                            $address - (IntMap::CONF_SIZE + $newCapacity * IntMap::VALUE_SIZE),
                            IntMap::PAIR_BLOCK_SIZE
                        );
                        $next        = substr($relatedPair, IntMap::VALUE_SIZE * 2, IntMap::VALUE_SIZE);

                        // проверка на пустую ссылку следующей пары
                        if (trim($next) === '') {
                            $next = $addressNextPair;

                            // записываем ссылку на новую пару в свзанную пару
                            $newPairs = substr_replace(
                                $newPairs,
                                $next,
                                $address + IntMap::VALUE_SIZE * 2,
                                IntMap::VALUE_SIZE
                            );
                        } else {
                            $addressSearching((int)$next);
                        }
                    };

                    $addressSearching((int)$firstPairAddress);
                }

                // добавляем пару в корзину
                $pairBlock = ValueHelper::getValue($key) .
                    ValueHelper::getValue($value) .
                    str_repeat(' ', IntMap::VALUE_SIZE);
                $newPairs  .= $pairBlock;

                // обновляем ссылку на слующую пару в корзине
                $addressNextPair += IntMap::PAIR_BLOCK_SIZE;
            };

            $pairProcessing($pair);
        }

        // записываем новую таблицу в разделяемую память
        $newTable = $newConf . $newFirstPairAddress . $newPairs;
        shmop_write($this->shmId, $newTable, 0);
    }

    /**
     * @param int $key
     * @return int|null
     */
    public function get(int $key): ?int
    {
        $oldValue = null;

        list($capacity) = $this->getConf();

        $hash  = HashHelper::getHash($key);
        $index = IndexHelper::indexFor($hash, $capacity);

        $formattedKey = ValueHelper::getValue($key);

        // получаем адрес первой пары в блоке памяти
        $addressFirstPairInMemoryBlock = IntMap::CONF_SIZE + ($index * IntMap::VALUE_SIZE);
        $addressFirstPair              = shmop_read(
            $this->shmId,
            $addressFirstPairInMemoryBlock,
            IntMap::VALUE_SIZE
        );

        if (trim($addressFirstPair) !== '') {
            $pairSearching = function (int $address) use (
                &$pairSearching,
                $formattedKey,
                &$oldValue
            ) {
                // ищем пару
                $pair = shmop_read(
                    $this->shmId,
                    $address,
                    IntMap::PAIR_BLOCK_SIZE
                );

                $key   = substr($pair, 0, IntMap::VALUE_SIZE);
                $value = substr($pair, IntMap::VALUE_SIZE, IntMap::VALUE_SIZE);
                $next  = substr($pair, IntMap::VALUE_SIZE * 2, IntMap::VALUE_SIZE);

                if ($formattedKey === $key) {
                    $oldValue = trim($value) !== '' ? (int)$value : null;
                } elseif (trim($next) !== '') {
                    $pairSearching((int)$next);
                }
            };

            $pairSearching((int)$addressFirstPair);
        }

        return $oldValue;
    }

    /**
     * @param int $key
     * @param int $value
     * @return int|null
     */
    public function put(int $key, int $value): ?int
    {
        $oldValue   = null;
        $valueAdded = false;
        list($capacity, $threshold, $size) = $this->getConf();

        $hash  = HashHelper::getHash($key);
        $index = IndexHelper::indexFor($hash, $capacity);

        $formattedKey   = ValueHelper::getValue($key);
        $formattedValue = ValueHelper::getValue($value);

        // получаем адрес первой пары в блоке памяти
        $addressFirstPairInMemoryBlock = IntMap::CONF_SIZE + ($index * IntMap::VALUE_SIZE);
        $addressFirstPair              = shmop_read(
            $this->shmId,
            $addressFirstPairInMemoryBlock,
            IntMap::VALUE_SIZE
        );

        if (trim($addressFirstPair) === '') {
            $valueAdded = true;

            $addressPair = IntMap::CONF_SIZE + $capacity * IntMap::VALUE_SIZE + $size * IntMap::PAIR_BLOCK_SIZE;

            // записываем ссылку на первый элемент в блок памяти
            shmop_write($this->shmId, ValueHelper::getValue($addressPair), $addressFirstPairInMemoryBlock);

            // добавляем пару в корзину
            $pairBlock = $formattedKey .
                $formattedValue .
                str_repeat(' ', IntMap::VALUE_SIZE);

            shmop_write($this->shmId, $pairBlock, $addressPair);
        } else {
            $pairSearching = function (int $address) use (
                &$pairSearching,
                &$oldValue,
                &$valueAdded,
                $formattedKey,
                $formattedValue,
                $capacity,
                $size
            ) {
                // ищем пару
                $pair = shmop_read(
                    $this->shmId,
                    $address,
                    IntMap::PAIR_BLOCK_SIZE
                );

                $key   = substr($pair, 0, IntMap::VALUE_SIZE);
                $value = substr($pair, IntMap::VALUE_SIZE, IntMap::VALUE_SIZE);
                $next  = substr($pair, IntMap::VALUE_SIZE * 2, IntMap::VALUE_SIZE);

                if ($formattedKey === $key) {
                    $oldValue = trim($value) !== '' ? (int)$value : null;

                    // записываем новое значение пары
                    shmop_write(
                        $this->shmId,
                        $formattedValue,
                        $address + IntMap::VALUE_SIZE
                    );
                } else {
                    if (trim($next) === '') {
                        $valueAdded = true;

                        // адрес новой пары
                        $newPairAddress = IntMap::CONF_SIZE +
                            $capacity * IntMap::VALUE_SIZE +
                            $size * IntMap::PAIR_BLOCK_SIZE;

                        // добавляем ссылку на новую пару в связанную пару
                        shmop_write(
                            $this->shmId,
                            ValueHelper::getValue($newPairAddress),
                            $address + IntMap::VALUE_SIZE * 2
                        );

                        // добавляем новую пару в корзину
                        $pairBlock = $formattedKey .
                            $formattedValue .
                            str_repeat(' ', IntMap::VALUE_SIZE);
                        shmop_write(
                            $this->shmId,
                            $pairBlock,
                            $newPairAddress
                        );
                    } else {
                        $pairSearching((int)$next);
                    }
                }
            };

            $pairSearching((int)$addressFirstPair);
        }

        if ($valueAdded) {
            $newSize = $size + 1;

            // обновляем значение size в корзине
            shmop_write(
                $this->shmId,
                ValueHelper::getValue($newSize),
                IntMap::VALUE_SIZE * 2
            );

            if ($threshold === $newSize) {
                $this->resize();
            }
        }

        return $oldValue;
    }

    /**
     * @param int $key
     * @return int|null
     */
    public function del(int $key): ?int
    {
        $valueDeleted = false;

        list($capacity, , $size) = $this->getConf();

        $hash  = HashHelper::getHash($key);
        $index = IndexHelper::indexFor($hash, $capacity);

        $formattedKey = ValueHelper::getValue($key);

        // получаем адрес первой пары в блоке памяти
        $addressFirstPairInMemoryBlock = IntMap::CONF_SIZE + ($index * IntMap::VALUE_SIZE);
        $addressFirstPair              = shmop_read(
            $this->shmId,
            $addressFirstPairInMemoryBlock,
            IntMap::VALUE_SIZE
        );

        if (trim($addressFirstPair) !== '') {
            $pairSearching = function (int $address) use (
                &$pairSearching,
                $formattedKey,
                &$valueDeleted
            ) {
                // ищем пару
                $pair = shmop_read(
                    $this->shmId,
                    $address,
                    IntMap::PAIR_BLOCK_SIZE
                );

                $key   = substr($pair, 0, IntMap::VALUE_SIZE);
                $value = substr($pair, IntMap::VALUE_SIZE, IntMap::VALUE_SIZE);
                $next  = substr($pair, IntMap::VALUE_SIZE * 2, IntMap::VALUE_SIZE);

                if ($formattedKey === $key) {
                    if (trim($value) !== '') {
                        $valueDeleted = true;

                        // удаляем значение
                        shmop_write(
                            $this->shmId,
                            str_repeat(' ', IntMap::VALUE_SIZE),
                            $address + IntMap::VALUE_SIZE
                        );
                    }
                } elseif (trim($next) !== '') {
                    $pairSearching((int)$next);
                }
            };

            $pairSearching((int)$addressFirstPair);
        }

        if ($valueDeleted) {
            $newSize = $size - 1;

            // обновляем значение size в корзине
            shmop_write(
                $this->shmId,
                ValueHelper::getValue($newSize),
                IntMap::VALUE_SIZE * 2
            );
        }

        return $key;
    }
}