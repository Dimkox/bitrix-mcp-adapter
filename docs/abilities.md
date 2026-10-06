# Свои возможности

Возможность (ability) — одна функция сайта, которую можно дать агенту. Есть три вида:

| Вид | Как видит агент | Когда использовать |
| --- | --- | --- |
| `Ability::tool` | Инструмент, который агент вызывает с аргументами | Действия и запросы с параметрами: найти, посчитать, создать |
| `Ability::resource` | Документ по адресу `схема://...` | Справочная информация без параметров: настройки, описание магазина |
| `Ability::prompt` | Готовый запрос, который пользователь выбирает в клиенте | Повторяющиеся задачи: «напиши описание», «составь отчёт» |

## Регистрация

Возможности добавляются в обработчике события `OnMcpCollectAbilities` модуля `dimkox.mcp`. Обработчик можно повесить в `init.php` или в `include.php` своего модуля. Постоянную регистрацию в установщике модуля делают через `registerEventHandler`.

```php
use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Dimkox\Mcp\Ability\Ability;
use Dimkox\Mcp\Ability\AbilityContext;
use Dimkox\Mcp\Ability\AbilityError;

EventManager::getInstance()->addEventHandler('dimkox.mcp', 'OnMcpCollectAbilities', static function (Event $event): void {
    /** @var \Dimkox\Mcp\Ability\AbilityRegistry $registry */
    $registry = $event->getParameter('registry');

    $registry->register(Ability::tool([
        'name' => 'acme-order-status',          // латиница, цифры, _ - . ; до 128 символов; уникально
        'title' => 'Статус заказа',
        'group' => 'acme',                      // для группировки на странице настроек
        'description' => 'Возвращает статус заказа по номеру. Номер — как в письме покупателю.',
        'readOnly' => true,                     // false — изменяет данные (см. ниже)
        'inputSchema' => [                      // JSON Schema аргументов
            'type' => 'object',
            'properties' => [
                'number' => ['type' => 'string', 'pattern' => '^[0-9A-Z-]{1,20}$'],
            ],
            'required' => ['number'],
            'additionalProperties' => false,
        ],
        'outputSchema' => [                     // необязательно: тогда агент получит и structuredContent
            'type' => 'object',
            'properties' => ['status' => ['type' => 'string']],
        ],
        'available' => static fn (): bool => \Bitrix\Main\Loader::includeModule('sale'),
        'permission' => static fn (array $args, AbilityContext $ctx): bool => $ctx->userId > 0,
        'execute' => static function (array $args, AbilityContext $ctx): array {
            $status = null; // ... ваш код
            if ($status === null) {
                throw AbilityError::notFound("Заказ {$args['number']}");
            }
            return ['status' => $status];
        },
    ]));
});
```

Зарегистрированная возможность появляется на вкладке «Возможности» в настройках модуля. Агенту она станет видна только после того, как администратор её отметит.

## Правила

- **Аргументы проверяются до вызова** по `inputSchema`. Поддерживаются `type`, `properties`, `required`, `additionalProperties`, `enum`, `const`, `minLength`, `maxLength`, `pattern`, `minimum`, `maximum`, `items`, `minItems`, `maxItems`, `default`. Значения `default` верхнего уровня подставляются автоматически.
- **`permission` вызывается перед каждым `execute`.** К моменту вызова пользователь токена уже авторизован, поэтому `$GLOBALS['USER']`, `CIBlock::GetPermission`, `$APPLICATION->GetGroupRight` и т. п. работают от его имени.
- **Ошибки.** Бросайте `AbilityError` для ошибок, которые агент должен увидеть («не найдено», «нет прав»). Любое другое исключение тоже будет поймано, но агент получит только общее сообщение, а подробности уйдут в журнал событий.
- **Результат.** `execute` возвращает массив (он уходит агенту как JSON) или строку.
- **Запись.** Возможность с `readOnly => false` изменяет данные. Агенту она видна, только если включён общий переключатель «Разрешить изменять данные». Укажите `destructive` (может ли удалить или перезаписать данные) и `idempotent` (безопасен ли повторный вызов): клиенты показывают эти подсказки пользователю перед вызовом.
- **Объём.** Ограничивайте объём ответа: постраничная выдача, обрезка длинных текстов. Ориентир — `$ctx->maxPageSize`.

## Ресурс

```php
$registry->register(Ability::resource([
    'name' => 'acme-delivery-rules',
    'uri' => 'acme://delivery/rules',
    'title' => 'Условия доставки',
    'description' => 'Зоны, сроки и стоимость доставки.',
    'mimeType' => 'text/markdown',
    'permission' => static fn (): bool => true,
    'execute' => static fn (): string => file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/local/docs/delivery.md'),
]));
```

## Промпт

```php
$registry->register(Ability::prompt([
    'name' => 'acme-weekly-report',
    'title' => 'Недельный отчёт по заказам',
    'description' => 'Просит агента собрать отчёт по заказам за неделю.',
    'arguments' => [['name' => 'week', 'description' => 'Неделя, например 2026-W40', 'required' => true]],
    'permission' => static fn (): bool => true,
    'execute' => static fn (array $args): string =>
        "Собери отчёт по заказам за неделю {$args['week']}: выручка, число заказов, топ-5 товаров. "
        . "Используй инструмент sale-list-orders.",
]));
```
