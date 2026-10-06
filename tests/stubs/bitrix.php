<?php

/*
 * Minimal in-memory stand-ins for the parts of the Bitrix API the module calls.
 * They exist to exercise the module's own wiring (argument shapes, permission
 * flow, result mapping) without a Bitrix installation. They are not a model of
 * Bitrix behaviour: only a real site proves that.
 */

declare(strict_types=1);

namespace Bitrix\Main {
    final class Loader
    {
        public static array $modules = ['iblock' => true, 'sale' => true, 'dimkox.mcp' => true];

        public static function includeModule(string $module): bool
        {
            return self::$modules[$module] ?? false;
        }
    }

    final class Event
    {
        /** @var list<callable> */
        public static array $handlers = [];

        public function __construct(private string $module, private string $type, private array $parameters = [])
        {
        }

        public function getParameter(string $name): mixed
        {
            return $this->parameters[$name] ?? null;
        }

        public function send(): void
        {
            foreach (self::$handlers as $handler) {
                $handler($this);
            }
        }
    }

    final class ModuleManager
    {
        public static function getInstalledModules(): array
        {
            return ['main' => [], 'iblock' => [], 'sale' => [], 'dimkox.mcp' => []];
        }
    }

    final class StubResult
    {
        private int $i = 0;

        public function __construct(private array $rows, private int $count = -1)
        {
        }

        public function fetch(): array|false
        {
            return $this->rows[$this->i++] ?? false;
        }

        public function fetchAll(): array
        {
            return $this->rows;
        }

        public function getCount(): int
        {
            return $this->count >= 0 ? $this->count : count($this->rows);
        }
    }

    final class UserTable
    {
        public static array $rows = [];

        public static function getList(array $params): StubResult
        {
            $rows = array_values(array_filter(self::$rows, static function (array $row) use ($params): bool {
                foreach ($params['filter'] ?? [] as $key => $value) {
                    if (is_array($value)) {
                        continue; // OR-groups: return everything, enough for the stubs
                    }
                    if (str_starts_with((string) $key, '=') && (string) ($row[substr($key, 1)] ?? '') !== (string) $value) {
                        return false;
                    }
                }
                return true;
            }));
            return new StubResult(array_slice($rows, 0, $params['limit'] ?? 1000));
        }
    }

    final class GroupTable
    {
        public static function getList(array $params): StubResult
        {
            return new StubResult(array_map(static fn (int $id) => ['ID' => $id, 'NAME' => "Group $id"], $params['filter']['@ID']));
        }
    }

    final class SiteTable
    {
        public static function getList(array $params): StubResult
        {
            return new StubResult([['LID' => 's1', 'NAME' => 'Магазин', 'SERVER_NAME' => 'shop.example.ru', 'DIR' => '/', 'LANGUAGE_ID' => 'ru', 'DEF' => 'Y']]);
        }
    }
}

namespace Bitrix\Main\Config {
    final class Option
    {
        public static array $values = [];

        public static function get(string $module, string $name, string $default = ''): string
        {
            return self::$values["$module.$name"] ?? $default;
        }

        public static function set(string $module, string $name, string $value): void
        {
            self::$values["$module.$name"] = $value;
        }
    }
}

namespace Bitrix\Main\Type {
    class Date
    {
        public function __construct(protected int $timestamp = 0)
        {
            $this->timestamp = $timestamp ?: time();
        }

        public function format(string $format): string
        {
            return date($format, $this->timestamp);
        }

        public function getTimestamp(): int
        {
            return $this->timestamp;
        }

        public function __toString(): string
        {
            return $this->format('d.m.Y H:i:s');
        }
    }

    class DateTime extends Date
    {
        public function __construct(?string $time = null, ?string $format = null)
        {
            parent::__construct($time !== null ? (int) strtotime($time) : time());
        }

        public static function createFromTimestamp(int $timestamp): static
        {
            $dt = new static();
            $dt->timestamp = $timestamp;
            return $dt;
        }
    }
}

namespace Bitrix\Main\ORM\Fields {
    abstract class Field
    {
        public function __construct(public string $name)
        {
        }

        public function __call(string $method, array $args): static
        {
            return $this; // configurePrimary(), configureSize()...
        }
    }

    final class IntegerField extends Field
    {
    }

    final class StringField extends Field
    {
    }

    final class BooleanField extends Field
    {
    }

    final class DatetimeField extends Field
    {
    }
}

namespace Bitrix\Main\ORM\Data {
    use Bitrix\Main\StubResult;

    final class AddResult
    {
        public function __construct(private int $id)
        {
        }

        public function isSuccess(): bool
        {
            return true;
        }

        public function getId(): int
        {
            return $this->id;
        }

        public function getErrorMessages(): array
        {
            return [];
        }
    }

    abstract class DataManager
    {
        public static array $storage = [];

        public static function add(array $fields): AddResult
        {
            $id = count(static::$storage[static::class] ?? []) + 1;
            static::$storage[static::class][$id] = ['ID' => $id] + $fields + ['ACTIVE' => true, 'EXPIRES_AT' => null];
            return new AddResult($id);
        }

        public static function update(int $id, array $fields): void
        {
            if (isset($fields['ACTIVE'])) {
                $fields['ACTIVE'] = $fields['ACTIVE'] === 'Y';
            }
            static::$storage[static::class][$id] = $fields + static::$storage[static::class][$id];
        }

        public static function delete(int $id): void
        {
            unset(static::$storage[static::class][$id]);
        }

        public static function getList(array $params = []): StubResult
        {
            $rows = array_values(array_filter(static::$storage[static::class] ?? [], static function (array $row) use ($params): bool {
                foreach ($params['filter'] ?? [] as $key => $value) {
                    if ($row[ltrim((string) $key, '=')] !== $value) {
                        return false;
                    }
                }
                return true;
            }));
            return new StubResult($rows);
        }
    }
}

namespace Bitrix\Sale {
    final class OrderStatus
    {
        /** user id => viewable statuses */
        public static array $viewable = [];

        public static function getStatusesUserCanDoOperations(int $userId, array $operations): array
        {
            return self::$viewable[$userId] ?? [];
        }
    }

    final class Order
    {
        public static ?array $lastFilter = null;

        public static function getList(array $params): \Bitrix\Main\StubResult
        {
            self::$lastFilter = $params['filter'];
            return new \Bitrix\Main\StubResult([
                ['ID' => 12, 'ACCOUNT_NUMBER' => '12', 'DATE_INSERT' => new \Bitrix\Main\Type\DateTime('2026-10-01 10:00:00'), 'STATUS_ID' => 'N',
                    'PRICE' => '1990.00', 'CURRENCY' => 'RUB', 'PAYED' => 'N', 'CANCELED' => 'N', 'USER_ID' => 7, 'LID' => 's1'],
            ], 31);
        }

        public static function load(int $id): ?self
        {
            return null;
        }
    }
}

namespace {
    final class CUser
    {
        public static array $groups = [];
        public static array $operations = [];
        public int $id = 0;

        public function Authorize(int $id, bool $save = false, bool $update = true): bool
        {
            $this->id = $id;
            return true;
        }

        public function IsAuthorized(): bool
        {
            return $this->id > 0;
        }

        public function GetID(): int
        {
            return $this->id;
        }

        public function IsAdmin(): bool
        {
            return in_array(1, self::$groups[$this->id] ?? [], true);
        }

        public function CanDoOperation(string $operation): bool
        {
            return in_array($operation, self::$operations[$this->id] ?? [], true);
        }

        public static function GetUserGroup(int $id): array
        {
            return self::$groups[$id] ?? [2];
        }
    }

    final class CMain
    {
        public static array $rights = [];

        public function GetGroupRight(string $module): string
        {
            return self::$rights[$module] ?? 'D';
        }

        public function RestartBuffer(): void
        {
        }
    }

    final class CEventLog
    {
        public static array $records = [];

        public static function Add(array $record): void
        {
            self::$records[] = $record;
        }
    }

    final class StubDbResult
    {
        private int $i = 0;
        public int $NavRecordCount;

        public function __construct(private array $rows, ?int $total = null)
        {
            $this->NavRecordCount = $total ?? count($rows);
        }

        public function Fetch(): array|false
        {
            return $this->rows[$this->i++] ?? false;
        }

        public function GetNext(): array|false
        {
            $row = $this->Fetch();
            if ($row === false) {
                return false;
            }
            foreach ($row as $key => $value) {
                if (is_string($value)) {
                    $row["~$key"] = $value;
                    $row[$key] = htmlspecialchars($value);
                }
            }
            return $row;
        }

        public function GetNextElement(): object|false
        {
            $row = $this->GetNext();
            if ($row === false) {
                return false;
            }
            return new class ($row) {
                public function __construct(private array $row)
                {
                }

                public function GetFields(): array
                {
                    return $this->row;
                }

                public function GetProperties(): array
                {
                    return CIBlockElement::$properties[$this->row['ID']] ?? [];
                }
            };
        }
    }

    final class CIBlock
    {
        /** iblock id => right letter for the current user */
        public static array $permissions = [];
        public static array $iblocks = [];

        public static function GetList(array $order, array $filter): StubDbResult
        {
            return new StubDbResult(array_values(array_filter(self::$iblocks, static fn (array $i) => (self::$permissions[$i['ID']] ?? 'D') >= 'R')));
        }

        public static function GetPermission(int $id): string
        {
            return self::$permissions[$id] ?? 'D';
        }

        /** iblock id => [field => value], e.g. RIGHTS_MODE, WORKFLOW */
        public static array $fields = [];

        public static function GetArrayByID(int $id, string $field): string
        {
            return self::$fields[$id][$field] ?? ($field === 'RIGHTS_MODE' ? 'S' : 'N');
        }
    }

    final class CIBlockSection
    {
        public static function GetList(array $order, array $filter, bool $count, array $select, array $nav): StubDbResult
        {
            return new StubDbResult([['ID' => '3', 'IBLOCK_SECTION_ID' => '', 'NAME' => 'Смартфоны', 'CODE' => 'phones', 'DEPTH_LEVEL' => '1', 'SECTION_PAGE_URL' => '/catalog/phones/']]);
        }
    }

    final class CIBlockElement
    {
        public static array $elements = [];
        public static array $properties = [];
        public static array $lastFilter = [];
        public string $LAST_ERROR = '';

        public static function GetList(array $order, array $filter, mixed $group, mixed $nav, array $select): StubDbResult
        {
            self::$lastFilter = $filter;
            $rows = array_values(array_filter(self::$elements, static function (array $e) use ($filter): bool {
                if (isset($filter['ID']) && (int) $e['ID'] !== (int) $filter['ID']) {
                    return false;
                }
                if (isset($filter['IBLOCK_ID']) && (int) $e['IBLOCK_ID'] !== (int) $filter['IBLOCK_ID']) {
                    return false;
                }
                if (($filter['ACTIVE'] ?? null) === 'Y' && $e['ACTIVE'] !== 'Y') {
                    return false;
                }
                return (CIBlock::$permissions[(int) $e['IBLOCK_ID']] ?? 'D') >= 'R';
            }));
            return new StubDbResult($rows);
        }

        public static function GetIBlockByID(int $id): int|false
        {
            return isset(self::$elements[$id]) ? (int) self::$elements[$id]['IBLOCK_ID'] : false;
        }

        public function Add(array $fields, bool $workflow = false, bool $updateSearch = true, bool $resizePictures = false): int|false
        {
            if ($fields['NAME'] === 'bad') {
                $this->LAST_ERROR = 'Element with this code already exists.<br>';
                return false;
            }
            $id = max(array_keys(self::$elements) ?: [0]) + 1;
            self::$elements[$id] = ['ID' => (string) $id, 'IBLOCK_SECTION_ID' => '', 'CODE' => '', 'SORT' => '500', 'PREVIEW_TEXT' => '', 'DETAIL_TEXT' => '',
                'DATE_ACTIVE_FROM' => '', 'DATE_ACTIVE_TO' => '', 'TIMESTAMP_X' => '', 'DETAIL_PAGE_URL' => ''] + array_map('strval', array_filter($fields, 'is_scalar'));
            return $id;
        }

        public function Update(int $id, array $fields): bool
        {
            self::$elements[$id] = array_map('strval', $fields) + self::$elements[$id];
            return true;
        }

        public static array $searchUpdated = [];

        public static function UpdateSearch(int $id, bool $force = false): void
        {
            self::$searchUpdated[] = $id;
        }

        public static function SetPropertyValuesEx(int $id, int $iblockId, array $values): void
        {
            foreach ($values as $code => $value) {
                self::$properties[$id][$code] = ['~VALUE' => $value, 'VALUE' => $value] + (self::$properties[$id][$code] ?? [
                    'ID' => 0, 'NAME' => $code, 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => null, 'MULTIPLE' => is_array($value) ? 'Y' : 'N',
                ]);
            }
        }
    }

    final class CIBlockElementRights
    {
        public static array $granted = [];

        public static function UserHasRightTo(int $iblockId, int $id, string $operation): bool
        {
            return in_array("element:$id:$operation", self::$granted, true);
        }
    }

    final class CIBlockSectionRights
    {
        public static array $granted = [];

        public static function UserHasRightTo(int $iblockId, int $id, string $operation): bool
        {
            return in_array("section:$id:$operation", self::$granted, true);
        }
    }

    final class CIBlockRights
    {
        public static array $granted = [];

        public static function UserHasRightTo(int $iblockId, int $id, string $operation): bool
        {
            return in_array("iblock:$id:$operation", self::$granted, true);
        }
    }

    final class CIBlockProperty
    {
        public static function GetList(array $order, array $filter): StubDbResult
        {
            return new StubDbResult([
                ['ID' => '1', 'CODE' => 'COLOR', 'PROPERTY_TYPE' => 'L'],
                ['ID' => '2', 'CODE' => 'MANUAL', 'PROPERTY_TYPE' => 'F'],
                ['ID' => '3', 'CODE' => 'TAGS', 'PROPERTY_TYPE' => 'S'],
            ]);
        }
    }

    function MakeTimeStamp(string $value): int|false
    {
        return strtotime($value);
    }
}
