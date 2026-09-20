<?php
/**
 * Blog Helper - 一键接收「目的地-Destination博客助手」小程序推送的微信运动步数与心情状态，自动建表存储，支持主题调用展示。<br>
 * 启用即完成数据表创建；推送入口为独立文件 <b>/usr/plugins/BlogHelper/push.php</b>（不依赖 Typecho 类库，不受版本升级影响）。<br>
 * 插件说明与文档：<a href="https://chrison.cn/work/520.html" target="_blank">chrison.cn/work/520.html</a>
 *
 * @package BlogHelper
 * @author Chrison
 * @version 2.0.4
 * @link https://chrison.cn
 */

namespace TypechoPlugin\BlogHelper;

use Typecho\Db;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/* 致命错误日志：启用/加载过程中若发生致命错误，把原因写入插件目录 error.log，便于排查 */
if (!function_exists(__NAMESPACE__ . '\bh_fatal_log')) {
    function bh_fatal_log($message)
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        @file_put_contents(__DIR__ . '/error.log', $line, FILE_APPEND);
    }

    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
            bh_fatal_log('FATAL ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
        }
    });

    bh_fatal_log('Plugin.php 已加载（PHP ' . PHP_VERSION . '）');
}

/**
 * 适用于 Typecho 1.2 / 1.3+
 * 说明：不调用 Helper/路由等版本敏感 API，推送入口由独立的 push.php 承接，最大化兼容性。
 * 注意：implements 必须写短名称 PluginInterface（配合 use 导入）——Typecho 的插件
 * 元数据解析基于 token 扫描，只识别 T_STRING 形式的“PluginInterface”；完全限定名
 * 在 PHP 8.0+ 是 T_NAME_FULLY_QUALIFIED 单一 token，会导致插件被误判为“即插即用”。
 */
class Plugin implements PluginInterface
{
    /** 推送入口路径（独立文件，不依赖 Typecho 类库与路由） */
    const PUSH_PATH = '/usr/plugins/BlogHelper/push.php';

    /**
     * 激活插件：创建三张数据表（日志表 / 运动明细表 / 心情状态明细表）
     */
    public static function activate()
    {
        bh_fatal_log('activate 开始');
        self::normalizeConfig();

        $tableMessage = '';
        try {
            self::createTables();
            $tableMessage = '数据表创建完成。';
            bh_fatal_log('activate 建表完成');
        } catch (\Throwable $e) {
            // 建表失败不中断启用（可手动执行 SQL 补建）
            $tableMessage = '数据表创建失败：' . $e->getMessage() . '（可稍后手动建表）。';
            bh_fatal_log('activate 建表失败：' . $e->getMessage());
        }

        bh_fatal_log('activate 完成');
        return 'Blog Helper 已启用。' . $tableMessage
            . '推送接口地址为 https://你的域名' . self::PUSH_PATH
            . '，请到插件设置中复制「接口地址」与「授权密令」填入小程序完成对接。';
    }

    /**
     * 停用插件：数据表保留，不删除用户数据
     */
    public static function deactivate()
    {
        return 'Blog Helper 已停用，数据表与数据均已保留。';
    }

    /**
     * 插件设置面板
     */
    public static function config($form)
    {
        $secret = self::secretKey();

        $description = '把下面两项填入小程序「我的」页即可完成对接：<br>'
            . '接口地址：<b>' . self::apiUrl() . '</b><br>'
            . '授权密令：<b>' . $secret . '</b><br>'
            . '密钥已自动生成并保存；也可手动改成自定义密钥（改后需同步更新小程序端）。如怀疑泄露：清空并保存，刷新设置页会自动生成新密钥。';

        $usage = '<br><br><b>📖 完整使用文档</b>：<a href="https://chrison.cn/work/520.html" target="_blank">chrison.cn/work/520.html</a>（新标签打开）<br><br>'
            . '<b>前端调用（主题模板中使用，可直接复制）</b><br>'
            . '最新步数：<br>'
            . '<code style="display:block;white-space:pre-wrap;word-break:break-all;background:#f6f8fa;padding:8px;border-radius:6px;">'
            . htmlspecialchars('<?php $s = \TypechoPlugin\BlogHelper\Api::latestSteps(); echo $s ? $s[\'steps\'] . \' 步（\' . $s[\'step_date\'] . \'）\' : \'暂无步数\'; ?>')
            . '</code>'
            . '最新状态（图标 + 名称）：'
            . '<code style="display:block;white-space:pre-wrap;word-break:break-all;background:#f6f8fa;padding:8px;border-radius:6px;">'
            . htmlspecialchars('<?php $s = \TypechoPlugin\BlogHelper\Api::latestStatus(); if ($s) { echo $s[\'icon_url\'] ? \'<img src="\' . $s[\'icon_url\'] . \'" style="background:#4A5568;border-radius:20%;width:32px;height:32px">\' : $s[\'emoji\']; echo \' \' . $s[\'emoji_name\']; } ?>')
            . '</code>'
            . '说明：心情图标已内置（assets/status/，白色线稿），显示时请放在深色/彩色背景上；'
            . '可用 \TypechoPlugin\BlogHelper\Api::iconCode($emojiId) / iconUrl($emojiId) 解析图标地址。<br><br>'
            . '<b>小程序二维码（微信扫码使用）</b><br>'
            . '<img src="' . self::assetUrl('qrcode.jpeg') . '" alt="小程序码" style="width:160px;height:160px;border-radius:8px" /><br>'
            . '环境自检：浏览器访问 <b>' . self::apiUrl() . '</b>（推送入口）与 <b>/usr/plugins/BlogHelper/check.php</b>；'
            . '若启用出错，本目录下 error.log 记录了具体原因。';

        $element = new Form\Element\Text(
            'secret_key',
            null,
            $secret,
            '接口保护密钥（授权密令）',
            $description . $usage
        );
        $form->addInput($element);

        $midElement = new Form\Element\Text(
            'storys_mid',
            null,
            isset(self::pluginSettings()['storys_mid']) ? self::pluginSettings()['storys_mid'] : '',
            '说说分类ID（发射说说必填）',
            '在「管理 → 分类」中进入分类编辑页，地址栏 mid=XXX 即为分类ID。'
            . '说说会发布为该分类下的文章；留空则说说无法发射（小程序会提示）。'
        );
        $form->addInput($midElement);

        $lightboxElement = new Form\Element\Radio(
            'lightbox',
            array('1' => '开启', '0' => '关闭'),
            isset(self::pluginSettings()['lightbox']) ? (string)self::pluginSettings()['lightbox'] : '1',
            '说说图片点击查看原图（灯箱链接）',
            '开启后，说说文章里的图片会包一层指向原图的链接：主题自带灯箱时会接管弹出大图；'
            . '无灯箱的主题点击则在浏览器新标签页打开原图。关闭则图片不可点击。'
            . '注意：该开关只影响之后新发射的说说，已发布文章内容不变。'
        );
        $form->addInput($lightboxElement);
    }

    public static function personalConfig($form)
    {
    }

    /**
     * 获取（或生成并持久化）授权密钥，保证同一博客密钥稳定
     */
    public static function secretKey()
    {
        $settings = self::pluginSettings();
        $secret = isset($settings['secret_key']) ? (string)$settings['secret_key'] : '';
        if (strlen($secret) >= 16) {
            return $secret;
        }

        $secret = self::randHex(16);
        self::persistSecret($secret);
        return $secret;
    }

    /**
     * 解码插件配置：Typecho 1.3+ 存 JSON；兼容读取旧版的 serialize 格式
     */
    public static function decodeSettings($value)
    {
        $decoded = json_decode((string)$value, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $legacy = @unserialize((string)$value);
        return is_array($legacy) ? $legacy : array();
    }

    /**
     * 读取插件配置（options 表 plugin:BlogHelper）
     */
    public static function pluginSettings()
    {
        try {
            $db = Db::get();
            $row = $db->fetchRow($db->select()->from('table.options')->where('name = ?', 'plugin:BlogHelper'));
            return $row ? self::decodeSettings($row['value']) : array();
        } catch (\Throwable $e) {
            bh_fatal_log('pluginSettings 读取失败：' . $e->getMessage());
            return array();
        }
    }

    /**
     * 启用时把旧版 serialize 格式的配置迁移为 JSON（1.3.0 的 configPlugin 按 JSON 读取，
     * 若残留 serialize 数据会导致启用时 array_merge(null) 而报 Server Error）
     */
    private static function normalizeConfig()
    {
        try {
            $db = Db::get();
            $row = $db->fetchRow($db->select()->from('table.options')->where('name = ?', 'plugin:BlogHelper'));
            if (!$row) {
                return;
            }
            $value = (string)$row['value'];
            if (!is_array(json_decode($value, true))) {
                $legacy = @unserialize($value);
                if (is_array($legacy)) {
                    $db->query($db->update('table.options')
                        ->rows(array('value' => json_encode($legacy)))
                        ->where('name = ?', 'plugin:BlogHelper'));
                }
            }
        } catch (\Throwable $e) {
            bh_fatal_log('normalizeConfig 失败：' . $e->getMessage());
        }
    }

    /**
     * 把生成的密钥合并写回插件配置（JSON 格式，与 Typecho 1.3+ 一致）
     */
    private static function persistSecret($secret)
    {
        try {
            $db = Db::get();
            $settings = self::pluginSettings();
            $settings['secret_key'] = $secret;
            $exists = self::optionValue('plugin:BlogHelper') !== '';
            if ($exists) {
                $db->query($db->update('table.options')
                    ->rows(['value' => json_encode($settings)])
                    ->where('name = ?', 'plugin:BlogHelper'));
            } else {
                $db->query($db->insert('table.options')
                    ->rows(['name' => 'plugin:BlogHelper', 'value' => json_encode($settings), 'user' => 0]));
            }
        } catch (\Throwable $e) {
            bh_fatal_log('persistSecret 失败：' . $e->getMessage());
        }
    }

    /**
     * 读取 options 表单项的值
     */
    private static function optionValue($name)
    {
        try {
            $db = Db::get();
            $row = $db->fetchRow($db->select()->from('table.options')->where('name = ?', $name));
            return $row ? (string)$row['value'] : '';
        } catch (\Throwable $e) {
            bh_fatal_log('optionValue(' . $name . ') 读取失败：' . $e->getMessage());
            return '';
        }
    }

    /**
     * 插件资源文件 URL（assets/ 下）
     */
    public static function assetUrl($file)
    {
        $pluginUrl = self::optionValue('pluginUrl');
        if ($pluginUrl === '') {
            $pluginUrl = rtrim(self::optionValue('siteUrl'), '/') . '/usr/plugins';
        }
        return rtrim($pluginUrl, '/') . '/BlogHelper/assets/' . ltrim($file, '/');
    }

    /**
     * 推送接口完整地址（站点地址从 options 表读取，不依赖其他组件）
     */
    public static function apiUrl()
    {
        $siteUrl = rtrim(self::optionValue('siteUrl'), '/');
        return $siteUrl . self::PUSH_PATH;
    }

    /**
     * 创建数据表（表名带 blog_helper_ 特色前缀 + Typecho 全局前缀，避免冲突）
     */
    private static function createTables()
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        bh_fatal_log('createTables 开始，前缀：' . $prefix);

        // 建表语句按数据库适配器选择：MySQL（InnoDB + utf8mb4，支持 emoji）/ SQLite 等
        $adapterName = '';
        try {
            $adapterName = (string)$db->getAdapterName();
        } catch (\Throwable $e) {
        }
        $isMysql = stripos($adapterName, 'mysql') !== false || $adapterName === '';

        if ($isMysql) {
            $queries = array(
                "CREATE TABLE IF NOT EXISTS `{$prefix}blog_helper_wechat` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `openid` VARCHAR(64) NOT NULL,
                    `steps` INT UNSIGNED NOT NULL DEFAULT 0,
                    `step_date` DATE NOT NULL,
                    `created` DATETIME NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_created` (`created`),
                    KEY `idx_openid` (`openid`)
                ) DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS `{$prefix}blog_helper_status` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `openid` VARCHAR(64) NOT NULL,
                    `emoji_id` VARCHAR(32) NOT NULL DEFAULT '',
                    `emoji_name` VARCHAR(32) NOT NULL DEFAULT '',
                    `emoji` VARCHAR(32) NOT NULL DEFAULT '',
                    `custom_text` VARCHAR(100) NOT NULL DEFAULT '',
                    `created` DATETIME NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_created` (`created`),
                    KEY `idx_openid` (`openid`)
                ) DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS `{$prefix}blog_helper_log` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `openid` VARCHAR(64) NOT NULL DEFAULT '',
                    `action` VARCHAR(32) NOT NULL DEFAULT '',
                    `request_data` TEXT NULL,
                    `result` TINYINT NOT NULL DEFAULT 0,
                    `created` DATETIME NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_created` (`created`)
                ) DEFAULT CHARSET=utf8mb4",
            );
        } else {
            $queries = array(
                "CREATE TABLE IF NOT EXISTS {$prefix}blog_helper_wechat (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    openid VARCHAR(64) NOT NULL,
                    steps INTEGER NOT NULL DEFAULT 0,
                    step_date DATE NOT NULL,
                    created DATETIME NOT NULL
                )",
                "CREATE TABLE IF NOT EXISTS {$prefix}blog_helper_status (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    openid VARCHAR(64) NOT NULL,
                    emoji_id VARCHAR(32) NOT NULL DEFAULT '',
                    emoji_name VARCHAR(32) NOT NULL DEFAULT '',
                    emoji VARCHAR(32) NOT NULL DEFAULT '',
                    custom_text VARCHAR(100) NOT NULL DEFAULT '',
                    created DATETIME NOT NULL
                )",
                "CREATE TABLE IF NOT EXISTS {$prefix}blog_helper_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    openid VARCHAR(64) NOT NULL DEFAULT '',
                    action VARCHAR(32) NOT NULL DEFAULT '',
                    request_data TEXT NULL,
                    result INTEGER NOT NULL DEFAULT 0,
                    created DATETIME NOT NULL
                )",
            );
        }

        foreach ($queries as $query) {
            try {
                $db->query($query);
            } catch (\Throwable $e) {
                // 表已存在等情况忽略，保证激活流程不中断
                bh_fatal_log('createTables 忽略：' . $e->getMessage());
            }
        }
    }

    /**
     * 随机 hex 字符串
     */
    private static function randHex($bytes = 16)
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes($bytes));
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes($bytes));
        }
        return substr(md5(uniqid(mt_rand(), true)), 0, $bytes * 2);
    }
}
