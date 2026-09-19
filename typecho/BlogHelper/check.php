<?php
/**
 * Blog Helper - Typecho 环境自检
 *
 * 浏览器直接访问：https://站点域名/usr/plugins/BlogHelper/check.php
 * 输出：PHP/Typecho 版本、数据库连接、数据表、密钥状态、推送入口地址、
 *       以及 error.log 的最后若干行（启用失败时先看这里）。
 * 兼容两种站点配置格式（Typecho 1.2/1.3+ 引导式 / 旧版常量式）。
 * 诊断完成后建议删除本文件。
 */

header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

$bhRoot = dirname(__DIR__, 3);
$configFile = $bhRoot . '/config.inc.php';

$rows = array();
function bh_row($name, $value, $ok = null)
{
    $color = $ok === null ? '#333' : ($ok ? '#0a7a3d' : '#c0392b');
    return '<tr><td style="padding:6px 12px;color:#555">' . htmlspecialchars($name) . '</td>'
        . '<td style="padding:6px 12px;color:' . $color . ';word-break:break-all">' . htmlspecialchars((string)$value) . '</td></tr>';
}

$rows[] = bh_row('PHP 版本', PHP_VERSION, version_compare(PHP_VERSION, '7.2.0', '>='));
$rows[] = bh_row('配置文件 config.inc.php', is_file($configFile) ? '找到' : '未找到（期望：' . $configFile . '）', is_file($configFile));

$db = null;
$prefix = null;
$legacyPdo = null;

if (is_file($configFile)) {
    require $configFile;

    // 模式一：Typecho 1.2/1.3+，config.inc.php 已引导 \Typecho\Db
    if (class_exists('\Typecho\Db') && (\Typecho\Db::get() instanceof \Typecho\Db)) {
        $db = \Typecho\Db::get();
        $prefix = $db->getPrefix();
        $rows[] = bh_row('站点数据库', '已连接（复用 Typecho 数据库实例，适配器：' . $db->getAdapterName() . '）', true);
    } elseif (defined('__TYPECHO_DB_HOST__') && defined('__TYPECHO_DB_DATABASE__')
        && (!defined('__TYPECHO_ADAPTER_NAME__') || stripos(__TYPECHO_ADAPTER_NAME__, 'sqlite') === false)) {
        // 模式二：旧版常量式配置，自建 PDO
        $host = __TYPECHO_DB_HOST__;
        $port = defined('__TYPECHO_DB_PORT__') ? __TYPECHO_DB_PORT__ : '3306';
        if (strpos($host, ':') !== false) {
            list($host, $port) = explode(':', $host, 2);
        }
        $charset = defined('__TYPECHO_DB_CHAR__') ? __TYPECHO_DB_CHAR__ : 'utf8mb4';
        try {
            $legacyPdo = new PDO(
                'mysql:host=' . $host . ';port=' . $port . ';dbname=' . __TYPECHO_DB_DATABASE__ . ';charset=' . $charset,
                __TYPECHO_DB_USER__,
                __TYPECHO_DB_PASSWORD__,
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5)
            );
            $rows[] = bh_row('数据库连接', '成功（' . $host . ':' . $port . ' / ' . __TYPECHO_DB_DATABASE__ . '）', true);
            $prefix = defined('__TYPECHO_DB_PREFIX__') ? __TYPECHO_DB_PREFIX__ : 'typecho_';
        } catch (PDOException $e) {
            $rows[] = bh_row('数据库连接', '失败：' . $e->getMessage(), false);
        }
    } else {
        $rows[] = bh_row('站点配置', '无法识别（SQLite 旧版站点暂不支持，请使用 MySQL）', false);
    }

    // 表前缀兜底：由插件表名反推
    if ($prefix === null) {
        try {
            if ($db) {
                $r = $db->fetchRow($db->select()->from('table.options')->where('name = ?', 'version'));
                $prefix = null; // 未确定
            }
        } catch (\Throwable $e) {
        }
    }

    if ($prefix !== null && ($db || $legacyPdo)) {
        // 表存在性
        foreach (array('blog_helper_wechat' => '运动明细表', 'blog_helper_status' => '心情状态明细表', 'blog_helper_log' => '日志表') as $table => $label) {
            $exists = false;
            $err = '';
            try {
                if ($db) {
                    $db->fetchAll($db->select()->from('table.' . $table)->limit(1));
                    $exists = true;
                } else {
                    $exists = (bool)$legacyPdo->query("SHOW TABLES LIKE '{$prefix}{$table}'")->fetch();
                }
            } catch (\Throwable $e) {
                $err = $e->getMessage();
            }
            $rows[] = bh_row($label . '（' . $prefix . $table . '）', $exists ? '存在' : ('不存在' . ($err !== '' ? '：' . $err : '')), $exists);
        }

        // Typecho 版本与站点地址
        $fetchOne = function ($name) use ($db, $legacyPdo) {
            try {
                if ($db) {
                    $r = $db->fetchRow($db->select()->from('table.options')->where('name = ?', $name));
                    return $r ? (string)$r['value'] : '';
                }
                if ($legacyPdo) {
                    $stmt = $legacyPdo->prepare("SELECT `value` FROM `{p}options` WHERE `name` = ?");
                    global $prefix;
                    $stmt->execute(array($name));
                    $r = $stmt->fetch(PDO::FETCH_ASSOC);
                    return $r ? (string)$r['value'] : '';
                }
            } catch (\Throwable $e) {
                return '';
            }
            return '';
        };
        $rows[] = bh_row('Typecho 版本', $fetchOne('version') !== '' ? $fetchOne('version') : '未知');
        $rows[] = bh_row('站点地址', $fetchOne('siteUrl') !== '' ? $fetchOne('siteUrl') : '未知');

        // 密钥状态
        try {
            $hasSecret = false;
            if ($db) {
                $r = $db->fetchRow($db->select()->from('table.options')->where('name = ?', 'plugin:BlogHelper'));
                $settings = $r ? unserialize($r['value']) : false;
                $hasSecret = is_array($settings) && isset($settings['secret_key']) && strlen((string)$settings['secret_key']) >= 16;
            } elseif ($legacyPdo) {
                $stmt = $legacyPdo->prepare("SELECT `value` FROM `{p}options` WHERE `name` = 'plugin:BlogHelper'");
                $stmt->execute(array());
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                $settings = $r ? unserialize($r['value']) : false;
                $hasSecret = is_array($settings) && isset($settings['secret_key']) && strlen((string)$settings['secret_key']) >= 16;
            }
            $rows[] = bh_row('授权密令', $hasSecret ? '已生成' : '未生成（插件设置页打开一次即自动生成）', $hasSecret);
        } catch (\Throwable $e) {
            $rows[] = bh_row('授权密令', '读取失败', false);
        }
    }

    // 推送入口
    $pushFile = __DIR__ . '/push.php';
    $rows[] = bh_row('推送入口 push.php', is_file($pushFile) ? '存在' : '缺失', is_file($pushFile));

    // error.log 最后 15 行
    $errFile = __DIR__ . '/error.log';
    if (is_file($errFile)) {
        $lines = file($errFile, FILE_IGNORE_NEW_LINES);
        $tail = array_slice($lines, -15);
        $rows[] = bh_row('error.log 最后 ' . count($tail) . ' 行', implode('<br>', array_map('htmlspecialchars', $tail)));
    } else {
        $rows[] = bh_row('error.log', '不存在（尚无致命错误记录）');
    }
}

echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>Blog Helper 环境自检</title></head>'
    . '<body style="font-family:-apple-system,PingFang SC,sans-serif;background:#f4f6f9;padding:32px 16px">'
    . '<div style="max-width:760px;margin:0 auto;background:#fff;border-radius:10px;padding:24px;box-shadow:0 2px 12px rgba(0,0,0,.06)">'
    . '<h2 style="margin-top:0">Blog Helper - Typecho 环境自检</h2>'
    . '<p style="color:#888">若「启用插件」出现 Server Error，下方 error.log 内容即为具体原因；排查完建议删除本文件。</p>'
    . '<table style="border-collapse:collapse;width:100%;font-size:14px">' . implode('', $rows) . '</table>'
    . '</div></body></html>';
