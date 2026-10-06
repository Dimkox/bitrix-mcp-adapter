<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Abilities;

use Bitrix\Main\Loader;
use Dimkox\Mcp\Ability\Ability;
use Dimkox\Mcp\Ability\AbilityContext;
use Dimkox\Mcp\Ability\AbilityError;
use Dimkox\Mcp\Ability\AbilityRegistry;

/**
 * Information blocks: catalogs, news, articles - the content of most Bitrix sites.
 * Every read goes through CHECK_PERMISSIONS, every write through the iblock's
 * (simple or extended) rights for the authenticated user.
 */
final class IblockAbilities
{
    private const ELEMENT_SELECT = [
        'ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'NAME', 'CODE', 'XML_ID', 'ACTIVE', 'SORT',
        'DATE_ACTIVE_FROM', 'DATE_ACTIVE_TO', 'TIMESTAMP_X', 'PREVIEW_TEXT', 'DETAIL_PAGE_URL',
    ];

    public static function register(AbilityRegistry $registry): void
    {
        $available = static fn (): bool => Loader::includeModule('iblock');
        $authorized = static fn (array $args, AbilityContext $ctx): bool => $ctx->userId > 0;
        $id = ['type' => 'integer', 'minimum' => 1];

        $registry->register(Ability::tool([
            'name' => 'iblock-list-iblocks',
            'title' => 'List information blocks',
            'group' => 'iblock',
            'description' => 'Lists the information blocks (catalogs, news, articles...) the user can read: id, code, type, name. Use the id with the other iblock tools.',
            'readOnly' => true,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'maxLength' => 50, 'description' => 'Optional iblock type id, e.g. "catalog" or "news"'],
                ],
                'additionalProperties' => false,
            ],
            'available' => $available,
            'permission' => $authorized,
            'execute' => static function (array $args): array {
                $filter = ['ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'Y', 'MIN_PERMISSION' => 'R'];
                if (!empty($args['type'])) {
                    $filter['TYPE'] = $args['type'];
                }
                $rows = \CIBlock::GetList(['IBLOCK_TYPE' => 'ASC', 'SORT' => 'ASC'], $filter);
                $iblocks = [];
                while ($row = $rows->Fetch()) {
                    $iblocks[] = [
                        'id' => (int) $row['ID'],
                        'code' => (string) $row['CODE'],
                        'type' => (string) $row['IBLOCK_TYPE_ID'],
                        'name' => (string) $row['NAME'],
                        'site' => (string) $row['LID'],
                        'description' => Access::text($row['DESCRIPTION'], 300),
                    ];
                }
                return ['iblocks' => $iblocks];
            },
        ]));

        $registry->register(Ability::tool([
            'name' => 'iblock-list-sections',
            'title' => 'List sections',
            'group' => 'iblock',
            'description' => 'Lists sections (categories) of an information block, optionally only the children of one section.',
            'readOnly' => true,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'iblock_id' => $id,
                    'parent_id' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Children of this section; 0 = top level; omit for all levels'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100],
                ],
                'required' => ['iblock_id'],
                'additionalProperties' => false,
            ],
            'available' => $available,
            'permission' => static fn (array $args): bool => self::canRead($args['iblock_id']),
            'execute' => static function (array $args, AbilityContext $ctx): array {
                $filter = ['IBLOCK_ID' => $args['iblock_id'], 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'Y'];
                if (isset($args['parent_id'])) {
                    $filter['SECTION_ID'] = $args['parent_id'] ?: false;
                }
                $rows = \CIBlockSection::GetList(
                    ['LEFT_MARGIN' => 'ASC'],
                    $filter,
                    false,
                    ['ID', 'IBLOCK_SECTION_ID', 'NAME', 'CODE', 'DEPTH_LEVEL', 'SECTION_PAGE_URL'],
                    ['nTopCount' => min($args['limit'], $ctx->maxPageSize * 4)]
                );
                $sections = [];
                while ($row = $rows->GetNext()) {
                    $sections[] = [
                        'id' => (int) $row['ID'],
                        'parent_id' => (int) $row['IBLOCK_SECTION_ID'],
                        'name' => (string) $row['~NAME'],
                        'code' => (string) $row['~CODE'],
                        'depth' => (int) $row['DEPTH_LEVEL'],
                        'url' => (string) $row['~SECTION_PAGE_URL'],
                    ];
                }
                return ['sections' => $sections];
            },
        ]));

        $registry->register(Ability::tool([
            'name' => 'iblock-search-elements',
            'title' => 'Search elements',
            'group' => 'iblock',
            'description' => 'Finds elements (products, news items, articles...) of one information block by text in the name or preview, optionally inside a section. Paged.',
            'readOnly' => true,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'iblock_id' => $id,
                    'query' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Text to look for in the name and preview text'],
                    'section_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Only this section and its subsections'],
                    'active_only' => ['type' => 'boolean', 'default' => true, 'description' => 'false also returns inactive and scheduled elements, if the user may see them (iblock right S or higher)'],
                    'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                    'page_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                ],
                'required' => ['iblock_id'],
                'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'items' => ['type' => 'array'],
                    'page' => ['type' => 'integer'],
                    'total' => ['type' => 'integer'],
                ],
            ],
            'available' => $available,
            'permission' => static fn (array $args): bool => self::canRead($args['iblock_id']),
            'execute' => static function (array $args, AbilityContext $ctx): array {
                $filter = ['IBLOCK_ID' => $args['iblock_id'], 'CHECK_PERMISSIONS' => 'Y', 'MIN_PERMISSION' => 'R'];
                if ($args['active_only'] || !self::canSeeUnpublished($args['iblock_id'])) {
                    $filter['ACTIVE'] = 'Y';
                    $filter['ACTIVE_DATE'] = 'Y';
                }
                if (!empty($args['section_id'])) {
                    $filter['SECTION_ID'] = $args['section_id'];
                    $filter['INCLUDE_SUBSECTIONS'] = 'Y';
                }
                $query = trim((string) ($args['query'] ?? ''));
                if ($query !== '') {
                    $filter[] = ['LOGIC' => 'OR', '%NAME' => $query, '%PREVIEW_TEXT' => $query];
                }
                $pageSize = min($args['page_size'], $ctx->maxPageSize);
                $rows = \CIBlockElement::GetList(
                    ['SORT' => 'ASC', 'ID' => 'DESC'],
                    $filter,
                    false,
                    ['nPageSize' => $pageSize, 'iNumPage' => $args['page'], 'checkOutOfRange' => true],
                    self::ELEMENT_SELECT
                );
                $items = [];
                while ($row = $rows->GetNext()) {
                    $items[] = self::element($row, 300);
                }
                return ['items' => $items, 'page' => $args['page'], 'page_size' => $pageSize, 'total' => (int) $rows->NavRecordCount];
            },
        ]));

        $registry->register(Ability::tool([
            'name' => 'iblock-get-element',
            'title' => 'Get element',
            'group' => 'iblock',
            'description' => 'Returns one element with its full texts and property values.',
            'readOnly' => true,
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['id' => $id],
                'required' => ['id'],
                'additionalProperties' => false,
            ],
            'available' => $available,
            'permission' => $authorized,
            'execute' => static fn (array $args): array => self::loadElement($args['id']),
        ]));

        $registry->register(Ability::tool([
            'name' => 'iblock-create-element',
            'title' => 'Create element',
            'group' => 'iblock',
            'description' => 'Creates an element in an information block. It is created inactive unless active=true is given, so a person can review it before it is published.',
            'readOnly' => false,
            'destructive' => false,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'iblock_id' => $id,
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                    'code' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Symbolic code (URL slug)'],
                    'section_id' => ['type' => 'integer', 'minimum' => 1],
                    'active' => ['type' => 'boolean', 'default' => false],
                    'sort' => ['type' => 'integer', 'minimum' => 0, 'default' => 500],
                    'preview_text' => ['type' => 'string', 'maxLength' => 20000],
                    'detail_text' => ['type' => 'string', 'maxLength' => 200000],
                    'text_type' => ['enum' => ['text', 'html'], 'default' => 'html'],
                    'properties' => ['type' => 'object', 'description' => 'Property values by property code'],
                ],
                'required' => ['iblock_id', 'name'],
                'additionalProperties' => false,
            ],
            'available' => $available,
            'permission' => static fn (array $args): bool => self::canWrite($args['iblock_id'], 0, $args['section_id'] ?? 0),
            'execute' => static function (array $args): array {
                self::assertDirectWrite($args['iblock_id']);
                $fields = [
                    'IBLOCK_ID' => $args['iblock_id'],
                    'NAME' => $args['name'],
                    'ACTIVE' => $args['active'] ? 'Y' : 'N',
                    'SORT' => $args['sort'],
                    'IBLOCK_SECTION_ID' => $args['section_id'] ?? false,
                ] + self::textFields($args);
                if (isset($args['code'])) {
                    $fields['CODE'] = $args['code'];
                }
                if (!empty($args['properties'])) {
                    $fields['PROPERTY_VALUES'] = self::propertyValues($args['iblock_id'], $args['properties']);
                }
                $element = new \CIBlockElement();
                $newId = $element->Add($fields, false, true, true);
                if (!$newId) {
                    throw new AbilityError('Bitrix refused the element: ' . Access::text($element->LAST_ERROR, 1000));
                }
                return self::savedElement((int) $newId);
            },
        ]));

        $registry->register(Ability::tool([
            'name' => 'iblock-update-element',
            'title' => 'Update element',
            'group' => 'iblock',
            'description' => 'Changes fields and/or property values of an existing element. Only the given fields and properties change.',
            'readOnly' => false,
            'destructive' => true,
            'idempotent' => true,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => $id,
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                    'code' => ['type' => 'string', 'maxLength' => 255],
                    'active' => ['type' => 'boolean'],
                    'sort' => ['type' => 'integer', 'minimum' => 0],
                    'preview_text' => ['type' => 'string', 'maxLength' => 20000],
                    'detail_text' => ['type' => 'string', 'maxLength' => 200000],
                    'text_type' => ['enum' => ['text', 'html'], 'default' => 'html'],
                    'properties' => ['type' => 'object', 'description' => 'Property values by property code; other properties are left as they are'],
                ],
                'required' => ['id'],
                'additionalProperties' => false,
            ],
            'available' => $available,
            'permission' => static function (array $args): bool {
                $iblockId = (int) \CIBlockElement::GetIBlockByID($args['id']);
                return $iblockId > 0 && self::canWrite($iblockId, $args['id']);
            },
            'execute' => static function (array $args): array {
                $iblockId = (int) \CIBlockElement::GetIBlockByID($args['id']);
                self::assertDirectWrite($iblockId);
                $properties = !empty($args['properties']) ? self::propertyValues($iblockId, $args['properties']) : [];
                $fields = self::textFields($args);
                foreach (['name' => 'NAME', 'code' => 'CODE', 'sort' => 'SORT'] as $arg => $field) {
                    if (isset($args[$arg])) {
                        $fields[$field] = $args[$arg];
                    }
                }
                if (isset($args['active'])) {
                    $fields['ACTIVE'] = $args['active'] ? 'Y' : 'N';
                }
                if ($fields !== []) {
                    $element = new \CIBlockElement();
                    if (!$element->Update($args['id'], $fields, false, true, true)) {
                        throw new AbilityError('Bitrix refused the change: ' . Access::text($element->LAST_ERROR, 1000));
                    }
                }
                if ($properties !== []) {
                    // Changes only the given properties (Update with PROPERTY_VALUES would reset the others).
                    \CIBlockElement::SetPropertyValuesEx($args['id'], $iblockId, $properties);
                    \CIBlockElement::UpdateSearch($args['id'], true);
                }
                return self::savedElement($args['id']);
            },
        ]));

        $registry->register(Ability::prompt([
            'name' => 'iblock-describe-element',
            'title' => 'Write a description for an element',
            'group' => 'iblock',
            'description' => 'Prepares a request to write a selling description for a product or other element, based on its current data.',
            'arguments' => [
                ['name' => 'element_id', 'description' => 'Element id', 'required' => true],
                ['name' => 'tone', 'description' => 'Tone of voice, e.g. "friendly", "expert"'],
            ],
            'available' => $available,
            'permission' => $authorized,
            'execute' => static function (array $args): string {
                if (!ctype_digit((string) $args['element_id'])) {
                    throw new AbilityError('element_id must be a number');
                }
                $element = self::loadElement((int) $args['element_id']);
                $properties = [];
                foreach ($element['properties'] as $code => $property) {
                    if ($property['value'] !== null && $property['value'] !== '' && $property['value'] !== []) {
                        $value = is_array($property['value']) ? implode(', ', array_map('strval', $property['value'])) : (string) $property['value'];
                        $properties[] = "- {$property['name']}: $value";
                    }
                }
                $tone = trim((string) ($args['tone'] ?? '')) ?: 'дружелюбный, без канцелярита';
                return "Напиши продающее описание для элемента сайта «{$element['name']}».\n"
                    . "Тон: $tone. 2–3 абзаца, без выдуманных характеристик: опирайся только на данные ниже.\n\n"
                    . "Анонс: {$element['preview_text']}\n"
                    . "Подробно: {$element['detail_text']}\n"
                    . ($properties ? "Свойства:\n" . implode("\n", $properties) . "\n" : '')
                    . "\nЕсли данных мало, перечисли, каких сведений не хватает.";
            },
        ]));
    }

    /** Property types an agent may write: string, number, list, element link, section link. */
    private const WRITABLE_PROPERTY_TYPES = ['S', 'N', 'L', 'E', 'G'];

    /**
     * Gate before a read query. With extended rights a user may read some sections
     * without an iblock-level right, so the query's CHECK_PERMISSIONS decides.
     */
    public static function canRead(int $iblockId): bool
    {
        return \CIBlock::GetArrayByID($iblockId, 'RIGHTS_MODE') === 'E' || \CIBlock::GetPermission($iblockId) >= 'R';
    }

    /** Inactive and scheduled elements are visible in Bitrix only from the "view in admin" right (S) up. */
    public static function canSeeUnpublished(int $iblockId): bool
    {
        if (\CIBlock::GetArrayByID($iblockId, 'RIGHTS_MODE') === 'E') {
            return (bool) \CIBlockRights::UserHasRightTo($iblockId, $iblockId, 'iblock_admin_display');
        }
        return \CIBlock::GetPermission($iblockId) >= 'S';
    }

    /**
     * Simple rights: W or X on the iblock. Extended rights: element_edit on the element,
     * or section_element_bind on the target section (0 = iblock root) for a new element.
     */
    public static function canWrite(int $iblockId, int $elementId = 0, int $sectionId = 0): bool
    {
        if (\CIBlock::GetArrayByID($iblockId, 'RIGHTS_MODE') === 'E') {
            return $elementId > 0
                ? (bool) \CIBlockElementRights::UserHasRightTo($iblockId, $elementId, 'element_edit')
                : (bool) \CIBlockSectionRights::UserHasRightTo($iblockId, $sectionId, 'section_element_bind');
        }
        return \CIBlock::GetPermission($iblockId) >= 'W';
    }

    /** Iblocks under document workflow or business processes are changed through their approval flow only. */
    private static function assertDirectWrite(int $iblockId): void
    {
        if (\CIBlock::GetArrayByID($iblockId, 'WORKFLOW') === 'Y' || \CIBlock::GetArrayByID($iblockId, 'BIZPROC') === 'Y') {
            throw new AbilityError('This information block uses document workflow or business processes; change it in the admin panel');
        }
    }

    /**
     * Checks property values against the iblock's properties: known codes, writable
     * types only (no files: a file value could make Bitrix copy a server path), scalar values.
     *
     * @return array<string, mixed>
     */
    private static function propertyValues(int $iblockId, array $values): array
    {
        $types = [];
        $rows = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y']);
        while ($row = $rows->Fetch()) {
            $types[(string) ($row['CODE'] ?: $row['ID'])] = (string) $row['PROPERTY_TYPE'];
        }
        $clean = [];
        foreach ($values as $code => $value) {
            $code = (string) $code;
            if (!isset($types[$code]) || in_array(strtoupper($code), \Dimkox\Mcp\Bitrix\Settings::hiddenProperties(), true)) {
                throw new AbilityError("Unknown property \"$code\"");
            }
            if (!in_array($types[$code], self::WRITABLE_PROPERTY_TYPES, true)) {
                throw new AbilityError("Property \"$code\" (type {$types[$code]}) cannot be changed through MCP");
            }
            $list = is_array($value) ? $value : [$value];
            foreach ($list as $item) {
                if (!is_scalar($item) && $item !== null) {
                    throw new AbilityError("Property \"$code\" accepts only plain values or a list of plain values");
                }
            }
            $clean[$code] = is_array($value) ? array_values($value) : $value;
        }
        return $clean;
    }

    /**
     * The element after a write. A user may be allowed to save an element without
     * being allowed to see it unpublished; the write still succeeded.
     *
     * @return array<string, mixed>
     */
    private static function savedElement(int $id): array
    {
        try {
            return self::loadElement($id);
        } catch (AbilityError) {
            return ['id' => $id, 'saved' => true, 'note' => 'Saved. The element is not published, and this user cannot view unpublished elements.'];
        }
    }

    /** @return array<string, mixed> */
    private static function loadElement(int $id): array
    {
        $filter = ['ID' => $id, 'CHECK_PERMISSIONS' => 'Y', 'MIN_PERMISSION' => 'R'];
        $iblockId = (int) \CIBlockElement::GetIBlockByID($id);
        if ($iblockId <= 0 || !self::canSeeUnpublished($iblockId)) {
            $filter['ACTIVE'] = 'Y';
            $filter['ACTIVE_DATE'] = 'Y';
        }
        $rows = \CIBlockElement::GetList(
            [],
            $filter,
            false,
            false,
            [...self::ELEMENT_SELECT, 'DETAIL_TEXT', 'PREVIEW_TEXT_TYPE', 'DETAIL_TEXT_TYPE']
        );
        $object = $rows->GetNextElement();
        if (!$object) {
            throw AbilityError::notFound("Element $id");
        }
        $fields = $object->GetFields();
        $element = self::element($fields, 4000);
        $element['detail_text'] = Access::text($fields['~DETAIL_TEXT'] ?? '', 20000);
        $element['properties'] = [];
        $hidden = array_flip(\Dimkox\Mcp\Bitrix\Settings::hiddenProperties());
        foreach ($object->GetProperties() as $code => $property) {
            if (isset($hidden[strtoupper((string) $code)])) {
                continue;
            }
            $value = $property['~VALUE'] ?? $property['VALUE'];
            if ($property['PROPERTY_TYPE'] === 'L') {
                $value = $property['VALUE_ENUM'] ?? $property['VALUE'];
            }
            $element['properties'][(string) ($code ?: $property['ID'])] = [
                'name' => (string) $property['NAME'],
                'type' => (string) $property['PROPERTY_TYPE'] . ($property['USER_TYPE'] ? ':' . $property['USER_TYPE'] : ''),
                'multiple' => $property['MULTIPLE'] === 'Y',
                'value' => $value === false ? null : $value,
            ];
        }
        return $element;
    }

    /** @return array<string, mixed> */
    private static function element(array $row, int $previewLimit): array
    {
        return [
            'id' => (int) $row['ID'],
            'iblock_id' => (int) $row['IBLOCK_ID'],
            'section_id' => (int) $row['IBLOCK_SECTION_ID'],
            'name' => (string) ($row['~NAME'] ?? $row['NAME']),
            'code' => (string) ($row['~CODE'] ?? $row['CODE']),
            'active' => $row['ACTIVE'] === 'Y',
            'sort' => (int) $row['SORT'],
            'active_from' => Access::date($row['DATE_ACTIVE_FROM'] ?? null),
            'active_to' => Access::date($row['DATE_ACTIVE_TO'] ?? null),
            'updated_at' => Access::date($row['TIMESTAMP_X'] ?? null),
            'url' => (string) ($row['~DETAIL_PAGE_URL'] ?? ''),
            'preview_text' => Access::text($row['~PREVIEW_TEXT'] ?? $row['PREVIEW_TEXT'] ?? '', $previewLimit),
        ];
    }

    /** @return array<string, string> */
    private static function textFields(array $args): array
    {
        $fields = [];
        $type = ($args['text_type'] ?? 'html') === 'text' ? 'text' : 'html';
        if (isset($args['preview_text'])) {
            $fields['PREVIEW_TEXT'] = $args['preview_text'];
            $fields['PREVIEW_TEXT_TYPE'] = $type;
        }
        if (isset($args['detail_text'])) {
            $fields['DETAIL_TEXT'] = $args['detail_text'];
            $fields['DETAIL_TEXT_TYPE'] = $type;
        }
        return $fields;
    }
}
