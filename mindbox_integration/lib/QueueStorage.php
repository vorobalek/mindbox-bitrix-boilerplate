<?php

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Highloadblock\HighloadBlockTable;

class MindboxIntegrationQueueStorage
{
    private static $dataClasses = [];

    private static function getDataClass(string $hlBlockName): string
    {
        if (isset(self::$dataClasses[$hlBlockName])) {
            return self::$dataClasses[$hlBlockName];
        }

        if (!Loader::includeModule('highloadblock')) {
            throw new \RuntimeException('highloadblock module is not available');
        }

        $hl = HighloadBlockTable::getList([
            'filter' => ['=NAME' => $hlBlockName],
        ])->fetch();

        if (!$hl) {
            throw new \RuntimeException('HL block not found: ' . $hlBlockName);
        }

        $entity = HighloadBlockTable::compileEntity($hl);
        $dataClass = $entity->getDataClass();
        self::$dataClasses[$hlBlockName] = $dataClass;

        return $dataClass;
    }

    public static function add(string $hlBlockName, array $fields): int
    {
        $dataClass = self::getDataClass($hlBlockName);
        $result = $dataClass::add($fields);
        return (int)$result->getId();
    }

    public static function updateById(string $hlBlockName, int $id, array $fields): void
    {
        $dataClass = self::getDataClass($hlBlockName);
        $dataClass::update($id, $fields);
    }

    public static function getDue(string $hlBlockName, int $limit, DateTime $now, int $lockSeconds): array
    {
        $dataClass = self::getDataClass($hlBlockName);

        $rows = $dataClass::getList([
            'filter' => [
                '=UF_STATUS' => ['N', 'R', 'W'],
                [
                    'LOGIC' => 'OR',
                    ['<=UF_NEXT_RUN' => $now],
                    ['=UF_NEXT_RUN' => false],
                ],
                [
                    'LOGIC' => 'OR',
                    ['=UF_LOCKED_UNTIL' => false],
                    ['<UF_LOCKED_UNTIL' => $now],
                ],
            ],
            'order' => ['UF_NEXT_RUN' => 'ASC', 'ID' => 'ASC'],
            'limit' => $limit,
        ])->fetchAll();

        return $rows ?: [];
    }
}
