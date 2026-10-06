<?php

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Loc::loadMessages(__FILE__);

class dimkox_mcp extends CModule
{
    public $MODULE_ID = 'dimkox.mcp';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $PARTNER_NAME;
    public $PARTNER_URI;

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME = Loc::getMessage('DIMKOX_MCP_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('DIMKOX_MCP_MODULE_DESCRIPTION');
        $this->PARTNER_NAME = 'Dimkox';
        $this->PARTNER_URI = 'https://github.com/Dimkox';
    }

    public function DoInstall()
    {
        global $APPLICATION;
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            $APPLICATION->ThrowException(Loc::getMessage('DIMKOX_MCP_PHP_VERSION'));
            return false;
        }
        ModuleManager::registerModule($this->MODULE_ID);
        try {
            $this->InstallDB();
            $this->InstallFiles();
        } catch (\Throwable $e) {
            // Leave nothing half-installed.
            $this->UnInstallFiles();
            $this->UnInstallDB();
            ModuleManager::unRegisterModule($this->MODULE_ID);
            $APPLICATION->ThrowException($e->getMessage());
            return false;
        }
        return true;
    }

    public function DoUninstall()
    {
        $this->UnInstallFiles();
        $this->UnInstallDB();
        Option::delete($this->MODULE_ID);
        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }

    public function InstallDB()
    {
        require_once dirname(__DIR__) . '/include.php';
        $connection = Application::getConnection();
        $table = \Dimkox\Mcp\Bitrix\TokenTable::getTableName();
        if (!$connection->isTableExists($table)) {
            \Dimkox\Mcp\Bitrix\TokenTable::getEntity()->createDbTable();
            $connection->createIndex($table, 'ix_dimkox_mcp_token_hash', ['TOKEN_HASH']);
            $connection->createIndex($table, 'ix_dimkox_mcp_token_user', ['USER_ID']);
        }
        return true;
    }

    public function UnInstallDB()
    {
        $connection = Application::getConnection();
        if ($connection->isTableExists('b_dimkox_mcp_token')) {
            $connection->dropTable('b_dimkox_mcp_token');
        }
        return true;
    }

    public function InstallFiles()
    {
        CopyDirFiles(__DIR__ . '/tools', Application::getDocumentRoot() . '/bitrix/tools', true, true);
        return true;
    }

    public function UnInstallFiles()
    {
        DeleteDirFilesEx('/bitrix/tools/dimkox.mcp');
        return true;
    }
}
