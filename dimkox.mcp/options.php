<?php

/**
 * Settings page: Settings → Product settings → Module settings → MCP adapter.
 *
 * @var CMain $APPLICATION
 * @var CUser $USER
 */

use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Dimkox\Mcp\Ability\Ability;
use Dimkox\Mcp\Bitrix\AbilityCollector;
use Dimkox\Mcp\Bitrix\Settings;
use Dimkox\Mcp\Bitrix\TokenService;
use Dimkox\Mcp\Bitrix\TokenTable;

$moduleId = 'dimkox.mcp';
if (!$USER->IsAdmin() || !Loader::includeModule($moduleId)) {
    return;
}
Loc::loadMessages(__FILE__);

$request = Context::getCurrent()->getRequest();
$issued = null;
$errors = [];

if ($request->isPost() && check_bitrix_sessid()) {
    if ($request->getPost('save') !== null) {
        Option::set($moduleId, 'enabled', $request->getPost('enabled') === 'Y' ? 'Y' : 'N');
        Option::set($moduleId, 'allow_write', $request->getPost('allow_write') === 'Y' ? 'Y' : 'N');
        Option::set($moduleId, 'audit', $request->getPost('audit') === 'Y' ? 'Y' : 'N');
        Option::set($moduleId, 'max_page_size', (string) max(1, min(200, (int) $request->getPost('max_page_size'))));
        Option::set($moduleId, 'allowed_origins', trim((string) $request->getPost('allowed_origins')));
        Option::set($moduleId, 'instructions', trim((string) $request->getPost('instructions')));
        Option::set($moduleId, 'hidden_properties', trim((string) $request->getPost('hidden_properties')));
        $abilities = $request->getPost('abilities');
        Settings::setEnabledAbilities(is_array($abilities) ? array_map('strval', $abilities) : []);
    } elseif ($request->getPost('issue_token') !== null) {
        $userId = (int) $request->getPost('token_user_id');
        $days = (int) $request->getPost('token_days');
        $user = $userId > 0 ? UserTable::getList(['select' => ['ID'], 'filter' => ['=ID' => $userId, '=ACTIVE' => 'Y']])->fetch() : null;
        if (!$user) {
            $errors[] = Loc::getMessage('DIMKOX_MCP_ERR_USER');
        } else {
            $issued = TokenService::issue($userId, (string) $request->getPost('token_name'), $days > 0 ? DateTime::createFromTimestamp(time() + $days * 86400) : null);
        }
    } elseif ($request->getPost('revoke_token') !== null) {
        TokenService::revoke((int) $request->getPost('revoke_token'));
    } elseif ($request->getPost('delete_token') !== null) {
        TokenService::delete((int) $request->getPost('delete_token'));
    }
}

$abilities = AbilityCollector::collect(false)->all();
$enabledAbilities = array_flip(Settings::enabledAbilities());
$scheme = $request->isHttps() ? 'https' : 'http';
$endpoint = $scheme . '://' . $request->getHttpHost() . '/bitrix/tools/dimkox.mcp/index.php';
$tokens = TokenTable::getList(['order' => ['ID' => 'DESC']])->fetchAll();

$tabs = [
    ['DIV' => 'settings', 'TAB' => Loc::getMessage('DIMKOX_MCP_TAB_SETTINGS'), 'TITLE' => Loc::getMessage('DIMKOX_MCP_TAB_SETTINGS')],
    ['DIV' => 'abilities', 'TAB' => Loc::getMessage('DIMKOX_MCP_TAB_ABILITIES'), 'TITLE' => Loc::getMessage('DIMKOX_MCP_TAB_ABILITIES_TITLE')],
    ['DIV' => 'tokens', 'TAB' => Loc::getMessage('DIMKOX_MCP_TAB_TOKENS'), 'TITLE' => Loc::getMessage('DIMKOX_MCP_TAB_TOKENS_TITLE')],
];
$tabControl = new CAdminTabControl('dimkox_mcp_options', $tabs);
$h = static fn ($value): string => htmlspecialcharsbx((string) $value);

foreach ($errors as $error) {
    CAdminMessage::ShowMessage($error);
}
if ($issued !== null) {
    CAdminMessage::ShowMessage([
        'TYPE' => 'OK',
        'MESSAGE' => Loc::getMessage('DIMKOX_MCP_TOKEN_ISSUED'),
        'DETAILS' => '<code style="font-size:14px;user-select:all">' . $h($issued['token']) . '</code><br><br>'
            . Loc::getMessage('DIMKOX_MCP_TOKEN_ONCE')
            . '<pre style="white-space:pre-wrap">claude mcp add --transport http bitrix ' . $h($endpoint)
            . ' --header "Authorization: Bearer ' . $h($issued['token']) . '"</pre>',
        'HTML' => true,
    ]);
}
if (!Settings::isEnabled()) {
    CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => Loc::getMessage('DIMKOX_MCP_DISABLED_NOTE')]);
}
if ($scheme !== 'https') {
    CAdminMessage::ShowNote(Loc::getMessage('DIMKOX_MCP_HTTPS_NOTE'));
}
?>
<form method="post" action="<?= $h($APPLICATION->GetCurPage()) ?>?mid=<?= urlencode($moduleId) ?>&lang=<?= LANGUAGE_ID ?>">
<?= bitrix_sessid_post() ?>
<?php $tabControl->Begin(); ?>

<?php $tabControl->BeginNextTab(); ?>
<tr>
    <td width="40%"><?= Loc::getMessage('DIMKOX_MCP_ENDPOINT') ?></td>
    <td><code style="user-select:all"><?= $h($endpoint) ?></code></td>
</tr>
<tr>
    <td><label for="enabled"><?= Loc::getMessage('DIMKOX_MCP_ENABLED') ?></label></td>
    <td><input type="checkbox" id="enabled" name="enabled" value="Y" <?= Settings::isEnabled() ? 'checked' : '' ?>></td>
</tr>
<tr>
    <td><label for="allow_write"><?= Loc::getMessage('DIMKOX_MCP_ALLOW_WRITE') ?></label></td>
    <td><input type="checkbox" id="allow_write" name="allow_write" value="Y" <?= Settings::allowWrite() ? 'checked' : '' ?>>
        <br><small><?= Loc::getMessage('DIMKOX_MCP_ALLOW_WRITE_HINT') ?></small></td>
</tr>
<tr>
    <td><label for="audit"><?= Loc::getMessage('DIMKOX_MCP_AUDIT') ?></label></td>
    <td><input type="checkbox" id="audit" name="audit" value="Y" <?= Settings::auditEnabled() ? 'checked' : '' ?>></td>
</tr>
<tr>
    <td><label for="max_page_size"><?= Loc::getMessage('DIMKOX_MCP_PAGE_SIZE') ?></label></td>
    <td><input type="number" id="max_page_size" name="max_page_size" min="1" max="200" value="<?= Settings::maxPageSize() ?>"></td>
</tr>
<tr>
    <td><label for="allowed_origins"><?= Loc::getMessage('DIMKOX_MCP_ORIGINS') ?></label></td>
    <td><input type="text" size="60" id="allowed_origins" name="allowed_origins" value="<?= $h(Option::get($moduleId, 'allowed_origins', '')) ?>">
        <br><small><?= Loc::getMessage('DIMKOX_MCP_ORIGINS_HINT') ?></small></td>
</tr>
<tr>
    <td><label for="hidden_properties"><?= Loc::getMessage('DIMKOX_MCP_HIDDEN_PROPERTIES') ?></label></td>
    <td><input type="text" size="60" id="hidden_properties" name="hidden_properties" value="<?= $h(Option::get($moduleId, 'hidden_properties', '')) ?>">
        <br><small><?= Loc::getMessage('DIMKOX_MCP_HIDDEN_PROPERTIES_HINT') ?></small></td>
</tr>
<tr>
    <td class="adm-detail-valign-top"><label for="instructions"><?= Loc::getMessage('DIMKOX_MCP_INSTRUCTIONS') ?></label></td>
    <td><textarea id="instructions" name="instructions" rows="4" cols="60"><?= $h(Settings::instructions()) ?></textarea>
        <br><small><?= Loc::getMessage('DIMKOX_MCP_INSTRUCTIONS_HINT') ?></small></td>
</tr>

<?php $tabControl->BeginNextTab(); ?>
<tr><td colspan="2"><?= Loc::getMessage('DIMKOX_MCP_ABILITIES_HINT') ?></td></tr>
<?php foreach ($abilities as $ability): /** @var Ability $ability */ ?>
<tr>
    <td width="40%">
        <label for="ability_<?= $h($ability->name) ?>"><b><?= $h($ability->title) ?></b></label><br>
        <small><code><?= $h($ability->name) ?></code> · <?= $h($ability->kind) ?> · <?= $h($ability->group) ?>
        <?= $ability->writes() ? ' · <span style="color:#c00">' . Loc::getMessage('DIMKOX_MCP_WRITES') . '</span>' : '' ?>
        <?= !$ability->isAvailable() ? ' · ' . Loc::getMessage('DIMKOX_MCP_UNAVAILABLE') : '' ?></small>
    </td>
    <td>
        <input type="checkbox" id="ability_<?= $h($ability->name) ?>" name="abilities[]" value="<?= $h($ability->name) ?>"
            <?= isset($enabledAbilities[$ability->name]) ? 'checked' : '' ?>>
        <?= $h($ability->description) ?>
    </td>
</tr>
<?php endforeach; ?>

<?php $tabControl->BeginNextTab(); ?>
<tr><td colspan="2"><?= Loc::getMessage('DIMKOX_MCP_TOKENS_HINT') ?></td></tr>
<tr>
    <td><?= Loc::getMessage('DIMKOX_MCP_TOKEN_USER') ?></td>
    <td><input type="number" name="token_user_id" min="1" value="<?= (int) $USER->GetID() ?>"></td>
</tr>
<tr>
    <td><?= Loc::getMessage('DIMKOX_MCP_TOKEN_NAME') ?></td>
    <td><input type="text" name="token_name" size="40" placeholder="Claude Desktop"></td>
</tr>
<tr>
    <td><?= Loc::getMessage('DIMKOX_MCP_TOKEN_DAYS') ?></td>
    <td><input type="number" name="token_days" min="0" value="90"> <small><?= Loc::getMessage('DIMKOX_MCP_TOKEN_DAYS_HINT') ?></small></td>
</tr>
<tr>
    <td></td>
    <td><input type="submit" name="issue_token" value="<?= Loc::getMessage('DIMKOX_MCP_TOKEN_ISSUE') ?>"></td>
</tr>
<tr>
    <td colspan="2">
        <table class="internal" width="100%">
            <tr class="heading">
                <td>ID</td><td><?= Loc::getMessage('DIMKOX_MCP_TOKEN_NAME') ?></td><td><?= Loc::getMessage('DIMKOX_MCP_TOKEN_USER') ?></td>
                <td><?= Loc::getMessage('DIMKOX_MCP_TOKEN_PREFIX') ?></td><td><?= Loc::getMessage('DIMKOX_MCP_TOKEN_LAST_USED') ?></td>
                <td><?= Loc::getMessage('DIMKOX_MCP_TOKEN_EXPIRES') ?></td><td></td>
            </tr>
            <?php foreach ($tokens as $token): ?>
            <tr>
                <td><?= (int) $token['ID'] ?></td>
                <td><?= $h($token['NAME']) ?></td>
                <td><a href="/bitrix/admin/user_edit.php?ID=<?= (int) $token['USER_ID'] ?>&lang=<?= LANGUAGE_ID ?>">#<?= (int) $token['USER_ID'] ?></a></td>
                <td><code><?= $h($token['TOKEN_PREFIX']) ?>…</code></td>
                <td><?= $h($token['LAST_USED_AT'] ?: '—') ?></td>
                <td><?= $h($token['EXPIRES_AT'] ?: '—') ?></td>
                <td>
                    <?php if ($token['ACTIVE'] === true || $token['ACTIVE'] === 'Y'): ?>
                        <button type="submit" name="revoke_token" value="<?= (int) $token['ID'] ?>"><?= Loc::getMessage('DIMKOX_MCP_TOKEN_REVOKE') ?></button>
                    <?php else: ?>
                        <?= Loc::getMessage('DIMKOX_MCP_TOKEN_REVOKED') ?>
                        <button type="submit" name="delete_token" value="<?= (int) $token['ID'] ?>"><?= Loc::getMessage('DIMKOX_MCP_TOKEN_DELETE') ?></button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </td>
</tr>

<?php $tabControl->Buttons(); ?>
<input type="submit" name="save" value="<?= Loc::getMessage('DIMKOX_MCP_SAVE') ?>" class="adm-btn-save">
<?php $tabControl->End(); ?>
</form>
