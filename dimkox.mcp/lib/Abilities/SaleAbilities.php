<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Abilities;

use Bitrix\Main\Loader;
use Dimkox\Mcp\Ability\Ability;
use Dimkox\Mcp\Ability\AbilityContext;
use Dimkox\Mcp\Ability\AbilityError;
use Dimkox\Mcp\Ability\AbilityRegistry;

/**
 * Online store orders (read-only). Orders contain buyers' personal data, so
 * these abilities are off by default and require the store manager right
 * (sale module right U or higher); only orders in statuses the user may view
 * are visible.
 */
final class SaleAbilities
{
    public static function register(AbilityRegistry $registry): void
    {
        $available = static fn (): bool => Loader::includeModule('sale');
        $manager = static fn (): bool => Access::isAdmin() || Access::moduleRight('sale') >= 'U';

        $registry->register(Ability::tool([
            'name' => 'sale-list-orders',
            'title' => 'List orders',
            'group' => 'sale',
            'description' => 'Lists recent store orders (newest first): number, date, status, sum, paid/canceled flags. Filter by status or by date.',
            'readOnly' => true,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'status_id' => ['type' => 'string', 'maxLength' => 2, 'description' => 'Order status id, e.g. "N" (new), "F" (finished)'],
                    'date_from' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$', 'description' => 'Only orders created on or after this date (YYYY-MM-DD)'],
                    'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                    'page_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                ],
                'additionalProperties' => false,
            ],
            'available' => $available,
            'permission' => $manager,
            'execute' => static function (array $args, AbilityContext $ctx): array {
                $filter = ['@STATUS_ID' => self::viewableStatuses($ctx)];
                if (!empty($args['status_id'])) {
                    $filter['=STATUS_ID'] = $args['status_id'];
                }
                if ($filter['@STATUS_ID'] === []) {
                    return ['orders' => [], 'page' => $args['page'], 'page_size' => 0, 'total' => 0];
                }
                if (!empty($args['date_from'])) {
                    $filter['>=DATE_INSERT'] = new \Bitrix\Main\Type\DateTime($args['date_from'] . ' 00:00:00', 'Y-m-d H:i:s');
                }
                $pageSize = min($args['page_size'], $ctx->maxPageSize);
                $rows = \Bitrix\Sale\Order::getList([
                    'select' => ['ID', 'ACCOUNT_NUMBER', 'DATE_INSERT', 'STATUS_ID', 'PRICE', 'CURRENCY', 'PAYED', 'CANCELED', 'USER_ID', 'LID'],
                    'filter' => $filter,
                    'order' => ['ID' => 'DESC'],
                    'limit' => $pageSize,
                    'offset' => ($args['page'] - 1) * $pageSize,
                    'count_total' => true,
                ]);
                $orders = [];
                while ($row = $rows->fetch()) {
                    $orders[] = [
                        'id' => (int) $row['ID'],
                        'number' => (string) $row['ACCOUNT_NUMBER'],
                        'created_at' => Access::date($row['DATE_INSERT']),
                        'status' => (string) $row['STATUS_ID'],
                        'price' => (float) $row['PRICE'],
                        'currency' => (string) $row['CURRENCY'],
                        'paid' => $row['PAYED'] === 'Y',
                        'canceled' => $row['CANCELED'] === 'Y',
                        'user_id' => (int) $row['USER_ID'],
                        'site' => (string) $row['LID'],
                    ];
                }
                return ['orders' => $orders, 'page' => $args['page'], 'page_size' => $pageSize, 'total' => (int) $rows->getCount()];
            },
        ]));

        $registry->register(Ability::tool([
            'name' => 'sale-get-order',
            'title' => 'Get order',
            'group' => 'sale',
            'description' => 'Returns one order with its items, buyer contact fields, payment and delivery state.',
            'readOnly' => true,
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]],
                'required' => ['id'],
                'additionalProperties' => false,
            ],
            'available' => $available,
            'permission' => $manager,
            'execute' => static function (array $args, AbilityContext $ctx): array {
                $order = \Bitrix\Sale\Order::load($args['id']);
                if ($order === null || !in_array((string) $order->getField('STATUS_ID'), self::viewableStatuses($ctx), true)) {
                    throw AbilityError::notFound("Order {$args['id']}");
                }
                $items = [];
                foreach ($order->getBasket()->getBasketItems() as $item) {
                    $items[] = [
                        'product_id' => (int) $item->getProductId(),
                        'name' => (string) $item->getField('NAME'),
                        'quantity' => (float) $item->getQuantity(),
                        'price' => (float) $item->getPrice(),
                        'sum' => (float) $item->getFinalPrice(),
                    ];
                }
                $properties = [];
                foreach ($order->getPropertyCollection() as $property) {
                    $value = $property->getValue();
                    if ($value !== null && $value !== '' && $value !== []) {
                        $properties[(string) ($property->getField('CODE') ?: $property->getPropertyId())] = [
                            'name' => (string) $property->getName(),
                            'value' => $value,
                        ];
                    }
                }
                return [
                    'id' => (int) $order->getId(),
                    'number' => (string) $order->getField('ACCOUNT_NUMBER'),
                    'created_at' => Access::date($order->getDateInsert()),
                    'status' => (string) $order->getField('STATUS_ID'),
                    'price' => (float) $order->getPrice(),
                    'currency' => (string) $order->getCurrency(),
                    'paid' => $order->isPaid(),
                    'shipped' => $order->isShipped(),
                    'canceled' => $order->isCanceled(),
                    'user_id' => (int) $order->getUserId(),
                    'comment' => (string) $order->getField('USER_DESCRIPTION'),
                    'items' => $items,
                    'properties' => $properties,
                ];
            },
        ]));
    }

    /**
     * Order statuses the user may view, as set in the store's status access settings.
     *
     * @return list<string>
     */
    private static function viewableStatuses(AbilityContext $ctx): array
    {
        return array_values(array_map('strval', \Bitrix\Sale\OrderStatus::getStatusesUserCanDoOperations($ctx->userId, ['view'])));
    }
}
