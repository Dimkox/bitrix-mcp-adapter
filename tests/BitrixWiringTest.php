<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Tests;

require_once __DIR__ . '/stubs/bitrix.php';

use Bitrix\Main\Config\Option;
use Bitrix\Main\Event;
use Bitrix\Main\UserTable;
use Dimkox\Mcp\Ability\Ability;
use Dimkox\Mcp\Bitrix\AbilityCollector;
use Dimkox\Mcp\Bitrix\Endpoint;
use Dimkox\Mcp\Bitrix\TokenService;
use Dimkox\Mcp\Bitrix\TokenTable;
use Dimkox\Mcp\Http\HttpHandler;

/**
 * End-to-end wiring over the Bitrix stubs: token -> user -> abilities -> MCP results.
 */
final class BitrixWiringTest extends TestCase
{
    private const MANAGER = 7;
    private const ADMIN = 1;

    public function __construct()
    {
        Option::$values = ['dimkox.mcp.enabled' => 'Y', 'main.site_name' => 'Магазин'];
        Event::$handlers = [];
        TokenTable::$storage = [];
        \CEventLog::$records = [];
        UserTable::$rows = [
            ['ID' => self::ADMIN, 'LOGIN' => 'admin', 'NAME' => 'Ада', 'LAST_NAME' => 'Админ', 'EMAIL' => 'a@example.ru', 'ACTIVE' => 'Y', 'LAST_LOGIN' => null],
            ['ID' => self::MANAGER, 'LOGIN' => 'manager', 'NAME' => 'Мария', 'LAST_NAME' => 'Контент', 'EMAIL' => 'm@example.ru', 'ACTIVE' => 'Y', 'LAST_LOGIN' => null],
            ['ID' => 9, 'LOGIN' => 'gone', 'NAME' => '', 'LAST_NAME' => '', 'EMAIL' => '', 'ACTIVE' => 'N', 'LAST_LOGIN' => null],
        ];
        \CUser::$groups = [self::ADMIN => [1, 2], self::MANAGER => [2, 5]];
        \CMain::$rights = [];
        $GLOBALS['USER'] = new \CUser();
        $GLOBALS['APPLICATION'] = new \CMain();
        \CIBlock::$iblocks = [
            ['ID' => '2', 'CODE' => 'catalog', 'IBLOCK_TYPE_ID' => 'catalog', 'NAME' => 'Каталог', 'LID' => 's1', 'DESCRIPTION' => '<p>Товары</p>'],
            ['ID' => '4', 'CODE' => 'hr', 'IBLOCK_TYPE_ID' => 'private', 'NAME' => 'Кадры', 'LID' => 's1', 'DESCRIPTION' => ''],
        ];
        \CIBlock::$permissions = [2 => 'W', 4 => 'D'];
        \CIBlock::$fields = [];
        \CIBlockSectionRights::$granted = [];
        \CIBlockElement::$searchUpdated = [];
        \Bitrix\Sale\OrderStatus::$viewable = [];
        \CIBlockElement::$elements = [
            10 => ['ID' => '10', 'IBLOCK_ID' => '2', 'IBLOCK_SECTION_ID' => '3', 'NAME' => 'Phone "X"', 'CODE' => 'phone-x', 'XML_ID' => '', 'ACTIVE' => 'Y', 'SORT' => '100',
                'DATE_ACTIVE_FROM' => '', 'DATE_ACTIVE_TO' => '', 'TIMESTAMP_X' => '01.10.2026 12:00:00', 'PREVIEW_TEXT' => '<b>Хороший</b> телефон',
                'DETAIL_TEXT' => 'Подробно', 'DETAIL_PAGE_URL' => '/catalog/phones/phone-x/'],
            20 => ['ID' => '20', 'IBLOCK_ID' => '4', 'IBLOCK_SECTION_ID' => '', 'NAME' => 'Зарплаты', 'CODE' => '', 'XML_ID' => '', 'ACTIVE' => 'Y', 'SORT' => '1',
                'DATE_ACTIVE_FROM' => '', 'DATE_ACTIVE_TO' => '', 'TIMESTAMP_X' => '', 'PREVIEW_TEXT' => 'secret', 'DETAIL_TEXT' => '', 'DETAIL_PAGE_URL' => ''],
        ];
        \CIBlockElement::$properties = [10 => ['COLOR' => ['ID' => 1, 'NAME' => 'Цвет', 'PROPERTY_TYPE' => 'L', 'USER_TYPE' => null, 'MULTIPLE' => 'N', 'VALUE' => '1', 'VALUE_ENUM' => 'Чёрный']]];
    }

    private function call(string $token, string $method, array $params = []): array
    {
        $handler = new HttpHandler(Endpoint::server(), \Closure::fromCallable([Endpoint::class, 'authenticate']), ['shop.example.ru']);
        $response = $handler->handle('POST', ['Authorization' => "Bearer $token", 'Content-Type' => 'application/json'], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]));
        self::assertSame(200, $response->status, $response->body);
        return json_decode($response->body, true);
    }

    private function tool(string $token, string $name, array $arguments = []): array
    {
        return $this->call($token, 'tools/call', ['name' => $name, 'arguments' => $arguments])['result'];
    }

    public function testTokensResolveOnlyForActiveUsersAndActiveTokens(): void
    {
        $token = TokenService::issue(self::MANAGER, 'Claude')['token'];
        self::assertSame(self::MANAGER, TokenService::resolve($token));
        self::assertSame(null, TokenService::resolve($token . 'x'));
        self::assertSame(null, TokenService::resolve('bxmcp_' . str_repeat('0', 64)));
        $inactiveUser = TokenService::issue(9, 'Old')['token'];
        self::assertSame(null, TokenService::resolve($inactiveUser));
        $expired = TokenService::issue(self::MANAGER, 'Expired', \Bitrix\Main\Type\DateTime::createFromTimestamp(time() - 10))['token'];
        self::assertSame(null, TokenService::resolve($expired));
        TokenService::revoke(1);
        self::assertSame(null, TokenService::resolve($token));
        $stored = json_encode(TokenTable::$storage);
        self::assertTrue(!str_contains($stored, substr($token, 12)), 'plain token stored');
    }

    public function testDefaultAbilitiesAndCurrentUser(): void
    {
        $token = TokenService::issue(self::MANAGER, 'Claude')['token'];
        $names = array_column($this->call($token, 'tools/list')['result']['tools'], 'name');
        self::assertSame(['iblock-get-element', 'iblock-list-iblocks', 'iblock-list-sections', 'iblock-search-elements', 'main-current-user'], $names);
        $me = $this->tool($token, 'main-current-user')['structuredContent'];
        self::assertSame(self::MANAGER, $me['id']);
        self::assertSame('Мария Контент', $me['name']);
        self::assertSame(false, $me['is_admin']);
    }

    public function testIblockReadsRespectPermissions(): void
    {
        $token = TokenService::issue(self::MANAGER, 'Claude')['token'];
        $iblocks = json_decode($this->tool($token, 'iblock-list-iblocks')['content'][0]['text'], true)['iblocks'];
        self::assertSame(['catalog'], array_column($iblocks, 'code'));
        self::assertSame('Товары', $iblocks[0]['description']);

        $found = $this->tool($token, 'iblock-search-elements', ['iblock_id' => 2, 'query' => 'Phone'])['structuredContent'];
        self::assertSame('Phone "X"', $found['items'][0]['name']);
        self::assertSame('Хороший телефон', $found['items'][0]['preview_text']);
        self::assertSame('Y', \CIBlockElement::$lastFilter['CHECK_PERMISSIONS']);
        self::assertSame(['LOGIC' => 'OR', '%NAME' => 'Phone', '%PREVIEW_TEXT' => 'Phone'], \CIBlockElement::$lastFilter[0]);

        $element = json_decode($this->tool($token, 'iblock-get-element', ['id' => 10])['content'][0]['text'], true);
        self::assertSame('Чёрный', $element['properties']['COLOR']['value']);
        Option::$values['dimkox.mcp.hidden_properties'] = 'color, purchase_price';
        $hidden = json_decode($this->tool($token, 'iblock-get-element', ['id' => 10])['content'][0]['text'], true);
        self::assertTrue(!isset($hidden['properties']['COLOR']), 'hidden property shown');
        unset(Option::$values['dimkox.mcp.hidden_properties']);
        self::assertSame('/catalog/phones/phone-x/', $element['url']);

        $denied = $this->tool($token, 'iblock-search-elements', ['iblock_id' => 4]);
        self::assertSame(true, $denied['isError']);
        $hidden = $this->tool($token, 'iblock-get-element', ['id' => 20]);
        self::assertSame('Element 20 not found', $hidden['content'][0]['text']);
    }

    public function testWritesNeedBothTheSiteSwitchAndTheAbility(): void
    {
        $token = TokenService::issue(self::MANAGER, 'Claude')['token'];
        self::assertSame(-32602, $this->call($token, 'tools/call', ['name' => 'iblock-create-element', 'arguments' => ['iblock_id' => 2, 'name' => 'N']])['error']['code']);

        Option::$values['dimkox.mcp.abilities'] = 'iblock-create-element,iblock-update-element,iblock-get-element';
        self::assertSame(-32602, $this->call($token, 'tools/call', ['name' => 'iblock-create-element', 'arguments' => ['iblock_id' => 2, 'name' => 'N']])['error']['code']);

        Option::$values['dimkox.mcp.allow_write'] = 'Y';
        $created = json_decode($this->tool($token, 'iblock-create-element', ['iblock_id' => 2, 'name' => 'Новый товар', 'preview_text' => 'Анонс'])['content'][0]['text'], true);
        self::assertSame('Новый товар', $created['name']);
        self::assertSame(false, $created['active']);

        $refused = $this->tool($token, 'iblock-create-element', ['iblock_id' => 2, 'name' => 'bad']);
        self::assertSame('Bitrix refused the element: Element with this code already exists.', $refused['content'][0]['text']);

        $noRight = $this->tool($token, 'iblock-create-element', ['iblock_id' => 4, 'name' => 'x']);
        self::assertContains('Access denied', $noRight['content'][0]['text']);

        $updated = json_decode($this->tool($token, 'iblock-update-element', ['id' => 10, 'name' => 'Phone Y', 'properties' => ['COLOR' => '2']])['content'][0]['text'], true);
        self::assertSame('Phone Y', $updated['name']);
        self::assertSame('2', \CIBlockElement::$properties[10]['COLOR']['VALUE']);
    }

    public function testSaleNeedsManagerRight(): void
    {
        Option::$values['dimkox.mcp.abilities'] = 'sale-list-orders,sale-get-order';
        $token = TokenService::issue(self::MANAGER, 'Claude')['token'];
        self::assertContains('Access denied', $this->tool($token, 'sale-list-orders')['content'][0]['text']);
        \CMain::$rights['sale'] = 'U';
        $none = json_decode($this->tool($token, 'sale-list-orders')['content'][0]['text'], true);
        self::assertSame(0, $none['total']);
        \Bitrix\Sale\OrderStatus::$viewable[self::MANAGER] = ['N', 'P'];
        $orders = json_decode($this->tool($token, 'sale-list-orders', ['date_from' => '2026-10-01'])['content'][0]['text'], true);
        self::assertSame(['N', 'P'], \Bitrix\Sale\Order::$lastFilter['@STATUS_ID']);
        self::assertSame(31, $orders['total']);
        self::assertSame(1990.0, $orders['orders'][0]['price']);
        self::assertSame('Order 5 not found', $this->tool($token, 'sale-get-order', ['id' => 5])['content'][0]['text']);
    }

    public function testUnpublishedElementsNeedTheAdminViewRight(): void
    {
        \CIBlockElement::$elements[11] = ['IBLOCK_ID' => '2', 'NAME' => 'Черновик', 'ACTIVE' => 'N'] + \CIBlockElement::$elements[10];
        \CIBlockElement::$elements[11]['ID'] = '11';
        \CIBlock::$permissions[2] = 'R';
        $token = TokenService::issue(self::MANAGER, 'Claude')['token'];
        $found = $this->tool($token, 'iblock-search-elements', ['iblock_id' => 2, 'active_only' => false])['structuredContent'];
        self::assertSame('Y', \CIBlockElement::$lastFilter['ACTIVE']);
        self::assertSame('Y', \CIBlockElement::$lastFilter['ACTIVE_DATE']);
        self::assertSame([10], array_column($found['items'], 'id'));
        self::assertSame('Element 11 not found', $this->tool($token, 'iblock-get-element', ['id' => 11])['content'][0]['text']);

        \CIBlock::$permissions[2] = 'S';
        $this->tool($token, 'iblock-search-elements', ['iblock_id' => 2, 'active_only' => false]);
        self::assertTrue(!isset(\CIBlockElement::$lastFilter['ACTIVE']), 'S right should see drafts');
        self::assertSame('Черновик', json_decode($this->tool($token, 'iblock-get-element', ['id' => 11])['content'][0]['text'], true)['name']);
    }

    public function testPropertyWritesAreCheckedAndWorkflowIblocksRefused(): void
    {
        Option::$values['dimkox.mcp.abilities'] = 'iblock-create-element,iblock-update-element';
        Option::$values['dimkox.mcp.allow_write'] = 'Y';
        $token = TokenService::issue(self::MANAGER, 'Claude')['token'];
        $file = $this->tool($token, 'iblock-update-element', ['id' => 10, 'properties' => ['MANUAL' => ['name' => 'a.txt', 'tmp_name' => '/etc/passwd']]]);
        self::assertSame('Property "MANUAL" (type F) cannot be changed through MCP', $file['content'][0]['text']);
        $unknown = $this->tool($token, 'iblock-update-element', ['id' => 10, 'properties' => ['NOPE' => 1]]);
        self::assertSame('Unknown property "NOPE"', $unknown['content'][0]['text']);
        $nested = $this->tool($token, 'iblock-create-element', ['iblock_id' => 2, 'name' => 'x', 'properties' => ['TAGS' => [['tmp_name' => '/etc/passwd']]]]);
        self::assertContains('accepts only plain values', $nested['content'][0]['text']);

        $ok = $this->tool($token, 'iblock-update-element', ['id' => 10, 'properties' => ['TAGS' => ['new', 'hit']]]);
        self::assertSame(false, $ok['isError']);
        self::assertSame([10], \CIBlockElement::$searchUpdated);

        \CIBlock::$fields[2]['WORKFLOW'] = 'Y';
        self::assertContains('document workflow', $this->tool($token, 'iblock-update-element', ['id' => 10, 'name' => 'x'])['content'][0]['text']);
    }

    public function testExtendedRightsCheckTheTargetSection(): void
    {
        Option::$values['dimkox.mcp.abilities'] = 'iblock-create-element';
        Option::$values['dimkox.mcp.allow_write'] = 'Y';
        \CIBlock::$fields[2]['RIGHTS_MODE'] = 'E';
        \CIBlockSectionRights::$granted = ['section:3:section_element_bind'];
        $token = TokenService::issue(self::MANAGER, 'Claude')['token'];
        self::assertContains('Access denied', $this->tool($token, 'iblock-create-element', ['iblock_id' => 2, 'name' => 'Root'])['content'][0]['text']);
        $created = $this->tool($token, 'iblock-create-element', ['iblock_id' => 2, 'name' => 'In 3', 'section_id' => 3]);
        self::assertSame(false, $created['isError']);
        // May bind into section 3 but not view unpublished elements: the save is still reported.
        self::assertSame(true, json_decode($created['content'][0]['text'], true)['saved']);
    }

    public function testAllowedOriginsIgnoreTheHostHeader(): void
    {
        $_SERVER['HTTP_HOST'] = 'evil.example.com';
        $origins = \Dimkox\Mcp\Bitrix\Settings::allowedOrigins();
        unset($_SERVER['HTTP_HOST']);
        self::assertSame(['shop.example.ru'], $origins);
    }

    public function testSiteResourceShowsModulesToAdminsOnly(): void
    {
        $manager = TokenService::issue(self::MANAGER, 'Claude')['token'];
        $info = json_decode($this->call($manager, 'resources/read', ['uri' => 'bitrix://site/info'])['result']['contents'][0]['text'], true);
        self::assertSame('shop.example.ru', $info['sites'][0]['server_name']);
        self::assertTrue(!isset($info['modules']));
        $admin = TokenService::issue(self::ADMIN, 'Admin')['token'];
        $info = json_decode($this->call($admin, 'resources/read', ['uri' => 'bitrix://site/info'])['result']['contents'][0]['text'], true);
        self::assertTrue(in_array('iblock', $info['modules'], true));
    }

    public function testPromptAndAuditAndThirdPartyAbilities(): void
    {
        Option::$values['dimkox.mcp.abilities'] = 'iblock-describe-element,acme-ping';
        Event::$handlers[] = static function (Event $event): void {
            $event->getParameter('registry')->register(Ability::tool([
                'name' => 'acme-ping',
                'description' => 'Third-party ability',
                'readOnly' => true,
                'permission' => static fn (): bool => true,
                'execute' => static fn (): string => 'pong',
            ]));
        };
        $token = TokenService::issue(self::MANAGER, 'Claude')['token'];
        self::assertSame('pong', $this->tool($token, 'acme-ping')['content'][0]['text']);

        $prompt = $this->call($token, 'prompts/get', ['name' => 'iblock-describe-element', 'arguments' => ['element_id' => '10']])['result'];
        self::assertContains('«Phone "X"»', $prompt['messages'][0]['content']['text']);
        self::assertContains('- Цвет: Чёрный', $prompt['messages'][0]['content']['text']);

        $types = array_column(\CEventLog::$records, 'ITEM_ID');
        self::assertSame(['tool:acme-ping', 'prompt:iblock-describe-element'], $types);
        self::assertSame(self::MANAGER, \CEventLog::$records[0]['USER_ID']);
    }

    public function testDisabledServerAndCollectorWithoutSettings(): void
    {
        self::assertTrue(count(AbilityCollector::collect(false)->all()) >= 11);
        Option::$values['dimkox.mcp.enabled'] = 'N';
        ob_start();
        Endpoint::run();
        $body = ob_get_clean();
        self::assertContains('mcp_disabled', $body);
    }
}
