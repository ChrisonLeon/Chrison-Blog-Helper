<?php
/**
 * Blog Helper - Typecho 主题前端调用接口
 *
 * 主题模板中直接调用（按命名空间自动加载，无需 require）：
 *   <?php $steps = \TypechoPlugin\BlogHelper\Api::latestSteps(); ?>
 *   <?php $status = \TypechoPlugin\BlogHelper\Api::latestStatus(); ?>
 *
 * 图标说明：心情图标为白色线稿 PNG，显示时请放在深色/彩色背景上（示例见插件设置页）。
 */

namespace TypechoPlugin\BlogHelper;

use Typecho\Db;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Api
{
    /**
     * 最新一条步数记录
     * @param string $openid 传空则取全站最新
     * @return array|null {openid, steps, step_date, created}
     */
    public static function latestSteps($openid = '')
    {
        try {
            $db = Db::get();
            $select = $db->select()->from('table.blog_helper_wechat');
            if ($openid !== '') {
                $select->where('openid = ?', $openid);
            }
            $select->order('created', Db::SORT_DESC)->limit(1);
            $row = $db->fetchRow($select);
            return $row ? $row : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 最新一条心情状态
     * @param string $openid 传空则取全站最新
     * @return array|null {openid, emoji_id, emoji_name, emoji, custom_text, created, icon_url}
     *                    icon_url 为插件内置图标的地址；emoji 推送无图标（用 emoji 字段渲染）
     */
    public static function latestStatus($openid = '')
    {
        try {
            $db = Db::get();
            $select = $db->select()->from('table.blog_helper_status');
            if ($openid !== '') {
                $select->where('openid = ?', $openid);
            }
            $select->order('created', Db::SORT_DESC)->limit(1);
            $row = $db->fetchRow($select);
            if ($row) {
                $row['icon_url'] = self::iconUrl($row['emoji_id']);
            }
            return $row ? $row : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 心情图标地址（emojiId → 插件内置 assets/status/{code}.png）
     * 规则：取 emojiId 最后一段作为图标代码，如 xqxf-mzz → mzz.png；emoji / 空 → 返回空串
     */
    public static function iconUrl($emojiId)
    {
        $code = self::iconCode($emojiId);
        if ($code === '') {
            return '';
        }
        return self::baseUrl() . $code . '.png';
    }

    /**
     * emojiId → 图标代码（不带扩展名）
     */
    public static function iconCode($emojiId)
    {
        $id = trim((string)$emojiId);
        if ($id === '' || $id === 'emoji') {
            return '';
        }
        $code = strpos($id, '-') !== false ? substr($id, strrpos($id, '-') + 1) : $id;
        if (!preg_match('/^[a-z0-9]+$/', $code) || !is_file(dirname(__FILE__) . '/assets/status/' . $code . '.png')) {
            return '';
        }
        return $code;
    }

    /**
     * 图标目录 URL（站点地址与插件目录均从 options 表读取，不依赖其他组件）
     */
    public static function baseUrl()
    {
        try {
            $db = Db::get();
            $siteUrl = '';
            $pluginUrl = '';
            $rows = $db->fetchAll($db->select()->from('table.options')
                ->where('name = ? OR name = ?', 'siteUrl', 'pluginUrl'));
            foreach ($rows as $row) {
                if ($row['name'] === 'siteUrl') {
                    $siteUrl = (string)$row['value'];
                } elseif ($row['name'] === 'pluginUrl') {
                    $pluginUrl = (string)$row['value'];
                }
            }
            return rtrim($pluginUrl !== '' ? $pluginUrl : $siteUrl . '/usr/plugins', '/') . '/BlogHelper/assets/status/';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
