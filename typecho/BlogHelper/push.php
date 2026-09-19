<?php
/**
 * Blog Helper - Typecho 独立推送接口入口
 *
 * 接口地址：https://站点域名/usr/plugins/BlogHelper/push.php
 *
 * 兼容两种站点配置格式（加载 config.inc.php 后自动识别）：
 *   - Typecho 1.2/1.3+：config 内部完成 \Typecho\Db 初始化，直接复用站点数据库实例
 *   - 旧版 Typecho（define 常量式）：使用常量自建 PDO 连接
 *
 * 协议：POST JSON {action, openid, timestamp, nonce, ...业务字段, sign}
 *   sign = sha256(action + openid + timestamp + nonce + keyfield + secret) 小写hex
 *   keyfield：steps→步数字符串；status→emojiId；ping→空串；timestamp 偏差 ≤300 秒
 * 响应：{"code":0,"msg":"ok","data":{...}}
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

function bh_reply($code, $msg, $data = null)
{
    $resp = array('code' => $code, 'msg' => $msg);
    if ($data !== null) {
        $resp['data'] = $data;
    }
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

function bh_param($body, $key, $default = '')
{
    return isset($body[$key]) ? trim((string)$body[$key]) : $default;
}

function bh_decode_settings($value)
{
    $decoded = json_decode((string)$value, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    $legacy = @unserialize((string)$value);
    return is_array($legacy) ? $legacy : array();
}

function bh_cut($text, $max)
{
    $text = (string)$text;
    return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
}

// ---------------------------------------------------------------------------
// 1. 加载站点配置（本文件位于 /usr/plugins/BlogHelper/）
// ---------------------------------------------------------------------------
$bhRoot = dirname(__DIR__, 3);
$configFile = $bhRoot . '/config.inc.php';
if (!is_file($configFile)) {
    bh_reply(5000, '未找到站点配置文件 config.inc.php（期望位置：' . $configFile . '）');
}
require $configFile;

// ---------------------------------------------------------------------------
// 2. 统一数据库访问层
// $bhInsert：插入一行；$bhDal：storys 使用的查询单行/插入/更新（均为查询构造器或预处理，无裸 SQL 拼参）
// ---------------------------------------------------------------------------
$bhInsert = null;   // function (string $tableNoPrefix, array $row)
$bhDal = null;      // array of closures
$bhPrefix = null;

if (class_exists('\Typecho\Db')) {
    // Typecho 1.2/1.3+：config.inc.php 已完成 Db 初始化。
    // 注意：Db::query() 第二参数是 int $op，不支持传参数数组，
    // 因此查询一律使用查询构造器（insert/select/update），与官方用法一致。
    try {
        $bhDb = \Typecho\Db::get();
        $bhPrefix = $bhDb->getPrefix();
    } catch (\Throwable $e) {
        bh_reply(5000, '站点数据库初始化失败：' . $e->getMessage());
    }

    $bhInsert = function ($tableNoPrefix, array $row) use ($bhDb) {
        $bhDb->query($bhDb->insert('table.' . $tableNoPrefix)->rows($row));
    };
    $bhDal = array(
        // 单行查询：selectOne('metas', 'mid', ['mid' => 1, 'type' => 'category'], 'cid')
        'selectOne' => function ($tableNoPrefix, $cols, array $where, $orderBy = '') use ($bhDb) {
            $cols = is_array($cols) ? implode(',', $cols) : $cols;
            $select = $bhDb->select($cols)->from('table.' . $tableNoPrefix);
            foreach ($where as $col => $value) {
                $select->where($col . ' = ?', $value);
            }
            if ($orderBy !== '') {
                $select->order($orderBy, \Typecho\Db::SORT_DESC);
            }
            $row = $bhDb->fetchRow($select);
            return $row ? $row : null;
        },
        'insert' => function ($tableNoPrefix, array $row) use ($bhDb) {
            $bhDb->query($bhDb->insert('table.' . $tableNoPrefix)->rows($row));
        },
        'update' => function ($tableNoPrefix, array $row, array $where) use ($bhDb) {
            $update = $bhDb->update('table.' . $tableNoPrefix)->rows($row);
            foreach ($where as $col => $value) {
                $update->where($col . ' = ?', $value);
            }
            $bhDb->query($update);
        },
    );
} elseif (defined('__TYPECHO_DB_HOST__') && defined('__TYPECHO_DB_DATABASE__')
    && (!defined('__TYPECHO_ADAPTER_NAME__') || stripos(__TYPECHO_ADAPTER_NAME__, 'sqlite') === false)) {
    // 旧版 Typecho（define 常量式）：自建 PDO（仅 MySQL）
    $bhHost = __TYPECHO_DB_HOST__;
    if (strpos($bhHost, ':') !== false) {
        list($bhHost, $bhPort) = explode(':', $bhHost, 2);
    } else {
        $bhPort = defined('__TYPECHO_DB_PORT__') ? __TYPECHO_DB_PORT__ : '3306';
    }
    $bhCharset = defined('__TYPECHO_DB_CHAR__') ? __TYPECHO_DB_CHAR__ : 'utf8mb4';
    try {
        $bhPdo = new PDO(
            'mysql:host=' . $bhHost . ';port=' . $bhPort . ';dbname=' . __TYPECHO_DB_DATABASE__ . ';charset=' . $bhCharset,
            __TYPECHO_DB_USER__,
            __TYPECHO_DB_PASSWORD__,
            array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            )
        );
    } catch (PDOException $e) {
        bh_reply(5000, '数据库连接失败：' . $e->getMessage());
    }
    $bhPrefix = defined('__TYPECHO_DB_PREFIX__') ? __TYPECHO_DB_PREFIX__ : 'typecho_';

    $bhInsert = function ($tableNoPrefix, array $row) use ($bhPdo, $bhPrefix) {
        $cols = array_keys($row);
        $marks = implode(',', array_fill(0, count($cols), '?'));
        $stmt = $bhPdo->prepare("INSERT INTO `{$bhPrefix}{$tableNoPrefix}` (`" . implode('`,`', $cols) . "`) VALUES ({$marks})");
        $stmt->execute(array_values($row));
    };
    $bhDal = array(
        'selectOne' => function ($tableNoPrefix, $cols, array $where, $orderBy = '') use ($bhPdo, $bhPrefix) {
            if (!is_array($cols)) {
                $cols = array($cols);
            }
            $sql = 'SELECT ' . implode(',', array_map(function ($c) {
                return '`' . $c . '`';
            }, $cols)) . ' FROM `' . $bhPrefix . $tableNoPrefix . '`';
            $params = array();
            foreach ($where as $col => $value) {
                $sql .= (count($params) === 0 ? ' WHERE' : ' AND') . ' `' . $col . '` = ?';
                $params[] = $value;
            }
            if ($orderBy !== '') {
                $sql .= ' ORDER BY `' . $orderBy . '` DESC';
            }
            $stmt = $bhPdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            return $row ? $row : null;
        },
        'insert' => function ($tableNoPrefix, array $row) use ($bhPdo, $bhPrefix) {
            $cols = array_keys($row);
            $marks = implode(',', array_fill(0, count($cols), '?'));
            $stmt = $bhPdo->prepare("INSERT INTO `{$bhPrefix}{$tableNoPrefix}` (`" . implode('`,`', $cols) . "`) VALUES ({$marks})");
            $stmt->execute(array_values($row));
        },
        'update' => function ($tableNoPrefix, array $row, array $where) use ($bhPdo, $bhPrefix) {
            $sets = array();
            $params = array();
            foreach ($row as $col => $value) {
                $sets[] = '`' . $col . '` = ?';
                $params[] = $value;
            }
            foreach ($where as $col => $value) {
                $sql_where[] = '`' . $col . '` = ?';
                $params[] = $value;
            }
            $stmt = $bhPdo->prepare('UPDATE `' . $bhPrefix . $tableNoPrefix . '` SET ' . implode(',', $sets) . ' WHERE ' . implode(' AND ', $sql_where));
            $stmt->execute($params);
        },
    );
} else {
    bh_reply(5000, '无法识别站点配置文件格式');
}

// 表前缀占位：{p} 在 SQL 中统一替换
function bh_table_sql($sql, $prefix)
{
    return str_replace('{p}', $prefix, $sql);
}

// ---------------------------------------------------------------------------
// 3. 读取授权密令（Typecho 插件配置保存在 options 表，序列化存储）
// ---------------------------------------------------------------------------
$secret = '';
try {
    if (isset($bhDb)) {
        $row = $bhDb->fetchRow($bhDb->select()->from('table.options')->where('name = ?', 'plugin:BlogHelper'));
    } else {
        $row = call_user_func($bhFetchRow, "SELECT `value` FROM `{p}options` WHERE `name` = 'plugin:BlogHelper'", array());
    }
    $settings = $row ? bh_decode_settings($row['value']) : array();
    if (is_array($settings) && isset($settings['secret_key'])) {
        $secret = (string)$settings['secret_key'];
    }
} catch (\Throwable $e) {
    bh_reply(5000, '读取插件配置失败：' . $e->getMessage());
}
if (strlen($secret) < 16) {
    bh_reply(5000, '插件未正确配置授权密令，请到 Typecho 后台 Blog Helper 插件设置查看');
}

// ---------------------------------------------------------------------------
// 4. 解析请求（浏览器/GET 直接访问返回运行状态，便于确认部署）
// ---------------------------------------------------------------------------
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body) || count($body) === 0) {
    bh_reply(0, 'Blog Helper Typecho 插件运行正常', array(
        'plugin' => 'BlogHelper-Typecho',
        'version' => '2.0.2',
        'usage' => 'POST JSON {action:ping|steps|status,...}（由中央服务器转发调用）',
    ));
}

$action = bh_param($body, 'action');
$openid = bh_param($body, 'openid');
$timestamp = bh_param($body, 'timestamp');
$nonce = bh_param($body, 'nonce');
$sign = bh_param($body, 'sign');

if ($action === '' || $openid === '' || $sign === '') {
    bh_reply(4001, '参数异常（action/openid/sign 缺失）');
}
if (!preg_match('/^[A-Za-z0-9_-]{6,64}$/', $openid)) {
    bh_reply(4001, 'openid 格式异常');
}
if (!ctype_digit((string)$timestamp) || abs(time() - (int)$timestamp) > 300) {
    bh_reply(4002, '请求已过期，请校准服务器时间');
}
if (strlen($nonce) < 8) {
    bh_reply(4001, 'nonce 参数异常');
}

// keyfield：参与签名的业务字段
switch ($action) {
    case 'ping':
        $keyfield = '';
        break;
    case 'steps':
        $keyfield = isset($body['steps']) ? (string)(int)$body['steps'] : '';
        break;
    case 'status':
        $keyfield = bh_param($body, 'emojiId');
        break;
    case 'storys':
        $keyfield = bh_param($body, 'title');
        break;
    default:
        bh_reply(4004, 'action[' . $action . '] 暂未开放');
}

$expected = hash('sha256', $action . $openid . $timestamp . $nonce . $keyfield . $secret);
if (!hash_equals($expected, $sign)) {
    bh_reply(403, '签名校验失败，请检查授权密令是否一致');
}

// ---------------------------------------------------------------------------
// 5. 业务入库（统一走 $bhInsert，写入当前模式的数据库）
// ---------------------------------------------------------------------------
$bhLog = function ($logAction, $logData, $logResult) use ($bhInsert) {
    try {
        call_user_func($bhInsert, 'blog_helper_log', array(
            'openid' => '',
            'action' => bh_cut($logAction, 32),
            'request_data' => bh_cut(json_encode($logData, JSON_UNESCAPED_UNICODE), 2000),
            'result' => $logResult ? 1 : 0,
            'created' => date('Y-m-d H:i:s'),
        ));
    } catch (\Throwable $e) {
        // 日志失败不影响主流程
    }
};

switch ($action) {
    case 'ping':
        bh_reply(0, 'pong', array('plugin' => 'BlogHelper-Typecho', 'version' => '2.0.2'));
        break;

    case 'steps':
        $steps = (int)$body['steps'];
        try {
            call_user_func($bhInsert, 'blog_helper_wechat', array(
                'openid' => $openid,
                'steps' => $steps,
                'step_date' => date('Y-m-d'),
                'created' => date('Y-m-d H:i:s'),
            ));
            bh_reply(0, '步数同步成功');
        } catch (\Throwable $e) {
            bh_reply(500, '步数入库失败：' . $e->getMessage());
        }
        break;

    case 'status':
        try {
            call_user_func($bhInsert, 'blog_helper_status', array(
                'openid' => $openid,
                'emoji_id' => bh_cut(bh_param($body, 'emojiId'), 32),
                'emoji_name' => bh_cut(bh_param($body, 'emojiName'), 32),
                'emoji' => bh_cut(bh_param($body, 'emoji'), 32),
                'custom_text' => bh_cut(bh_param($body, 'customText'), 100),
                'created' => date('Y-m-d H:i:s'),
            ));
            bh_reply(0, '状态同步成功');
        } catch (\Throwable $e) {
            bh_reply(500, '状态入库失败：' . $e->getMessage());
        }
        break;

    case 'storys':
        bh_storys($openid, $body, $settings, $bhDal);
        break;

    default:
        bh_reply(4000, '未知错误');
}

// ---------------------------------------------------------------------------
// 6. 说说发布（storys）：下载图片本地化 → 发布为「说说分类」下的文章
// ---------------------------------------------------------------------------
function bh_slug($text)
{
    $text = trim((string)$text);
    if ($text === '') {
        return '';
    }
    if (class_exists('\Typecho\Common') && method_exists('\Typecho\Common', 'slugName')) {
        return \Typecho\Common::slugName($text);
    }
    $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $text));
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : md5($text);
}

// 并行下载远程图片到 Typecho 上传目录（curl_multi，9 张同时拉取，单张超时 12 秒）
// 返回附件信息数组（保持传入顺序）；单张失败自动跳过
function bh_fetch_images(array $urls)
{
    $urls = array_slice(array_values($urls), 0, 9);
    if (!$urls) {
        return array();
    }
    $allowed = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif');

    // 1) 并行抓取原始数据
    $raw = array();
    if (function_exists('curl_multi_init')) {
        $mh = curl_multi_init();
        $handles = array();
        foreach ($urls as $i => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
            ));
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 0.2);
            }
        } while ($status === CURLM_CALL_MULTI_PERFORM || $active);
        foreach ($handles as $i => $ch) {
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $raw[$i] = ($code >= 200 && $code < 300) ? curl_multi_getcontent($ch) : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    } else {
        foreach ($urls as $i => $url) {
            $ctx = stream_context_create(array('http' => array('timeout' => 12, 'ignore_errors' => true)));
            $raw[$i] = @file_get_contents($url, false, $ctx);
        }
    }

    // 2) 校验 + 落盘
    $root = defined('__TYPECHO_ROOT_DIR__') ? __TYPECHO_ROOT_DIR__ : dirname(__DIR__, 4);
    $sub = date('Ym');
    $dir = $root . '/usr/uploads/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return array();
    }
    $out = array();
    foreach ($urls as $i => $url) {
        $data = isset($raw[$i]) ? $raw[$i] : null;
        if (!$data || strlen($data) < 32 || strlen($data) > 10 * 1024 * 1024) {
            continue;
        }
        // 魔数校验：只接受真实图片内容（同时兜底 HTML 错误页/伪造内容）
        $ext = '';
        if (strncmp($data, "\xFF\xD8\xFF", 3) === 0) {
            $ext = 'jpg';
        } elseif (strncmp($data, "\x89PNG", 4) === 0) {
            $ext = 'png';
        } elseif (strncmp($data, 'GIF8', 4) === 0) {
            $ext = 'gif';
        } elseif (strncmp($data, 'RIFF', 4) === 0 && substr($data, 8, 4) === 'WEBP') {
            $ext = 'webp';
        }
        if ($ext === '' || !isset($allowed[$ext])) {
            continue;
        }
        $name = bin2hex(random_bytes(6)) . '.' . $ext;
        if (@file_put_contents($dir . '/' . $name, $data) === false) {
            continue;
        }
        $out[] = array(
            'name' => $name,
            'path' => '/usr/uploads/' . $sub . '/' . $name,
            'size' => strlen($data),
            'mime' => $allowed[$ext],
        );
    }
    return $out;
}

// 按布局生成图片 HTML（none 为 markdown 原图；其余带排版类名，主题可自定义样式）
function bh_storys_html($layout, $imgs, $lightbox = '1')
{
    if (!$imgs) {
        return '';
    }
    $lightboxOn = ($lightbox === '1' || $lightbox === 1 || $lightbox === true);
    if ($layout === 'none') {
        $parts = array();
        foreach ($imgs as $im) {
            $parts[] = $lightboxOn
                ? '[![' . $im['name'] . '](' . $im['path'] . ')](' . $im['path'] . ')'
                : '![' . $im['name'] . '](' . $im['path'] . ')';
        }
        return implode("\n", $parts);
    }
    $wrapMap = array(
        'grid9' => array('chrison_grid_9', 'chrison_grid_item'),
        'grid4' => array('chrison_grid_4', 'chrison_grid_item'),
        'row' => array('chrison_row', 'chrison_row_item'),
        'column' => array('chrison_column', 'chrison_column_item'),
    );
    list($wrap, $item) = $wrapMap[$layout];
    $html = '<div class="' . $wrap . '">';
    foreach ($imgs as $im) {
        $imgTag = '<img src="' . $im['path'] . '" alt="' . $im['name'] . '" />';
        $html .= '<div class="' . $item . '">'
            . ($lightboxOn
                ? '<a class="chrison_image_a" href="' . $im['path'] . '" target="_blank" rel="noopener">' . $imgTag . '</a>'
                : $imgTag)
            . '</div>';
    }
    $html .= '</div>';
    // !!! 为 Typecho markdown 的原样输出标记：裸 <style> 会被解析器吞掉，必须包裹（与官方旧插件一致）
    $style = '!!!'
        . "\n<style>"
        . '.chrison_grid_9,.chrison_grid_4,.chrison_row,.chrison_column{display:flex;flex-wrap:wrap;gap:4px;margin:12px 0}'
        . '.chrison_grid_9 .chrison_grid_item,.chrison_grid_4 .chrison_grid_item{width:calc((100% - 8px)/3);padding-top:calc((100% - 8px)/3*0.66);position:relative;overflow:hidden}'
        . '.chrison_grid_4 .chrison_grid_item{width:calc((100% - 4px)/2);padding-top:calc((100% - 4px)/2*0.66)}'
        . '.chrison_grid_9 .chrison_grid_item img,.chrison_grid_4 .chrison_grid_item img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}'
        . '.chrison_grid_item .chrison_image_a{position:absolute;inset:0;display:block}'
        . '.chrison_row .chrison_row_item{flex:1 1 100%}.chrison_row .chrison_row_item img{width:100%;display:block}'
        . '.chrison_column .chrison_column_item{flex:1 1 30%;min-width:120px}.chrison_column .chrison_column_item img{width:100%;display:block}'
        . '.chrison_row_item .chrison_image_a,.chrison_column_item .chrison_image_a{display:block}'
        . '</style>'
        . "\n!!!";
    return $style . "\n" . $html;
}

function bh_storys($openid, $body, $settings, array $bhDal)
{
    // 分类ID（插件设置）
    $mid = isset($settings['storys_mid']) ? (int)$settings['storys_mid'] : 0;
    if ($mid <= 0) {
        bh_reply(4005, '请先在 Typecho 插件设置中配置「说说分类ID」');
    }
    if (!$bhDal['selectOne']('metas', 'mid', array('mid' => $mid, 'type' => 'category'))) {
        bh_reply(4005, '说说分类ID不存在，请检查插件设置');
    }

    // 基础字段
    $title = bh_param($body, 'title');
    if ($title === '') {
        $title = '未命名';
    }
    $title = bh_cut($title, 100);
    $content = bh_cut(bh_param($body, 'content'), 2000);

    // 标签
    $tags = array();
    if (isset($body['tags']) && is_array($body['tags'])) {
        foreach ($body['tags'] as $t) {
            $t = bh_cut(trim((string)$t), 30);
            if ($t !== '') {
                $tags[] = $t;
            }
        }
    } elseif (bh_param($body, 'tags') !== '') {
        foreach (preg_split('/[\s、；;，,]+/u', bh_param($body, 'tags')) as $t) {
            $t = bh_cut(trim($t), 30);
            if ($t !== '') {
                $tags[] = $t;
            }
        }
    }
    $tags = array_slice(array_values(array_unique($tags)), 0, 10);

    // 图片下载本地化
    $images = isset($body['images']) && is_array($body['images']) ? array_slice($body['images'], 0, 9) : array();
    $imgs = bh_fetch_images($images);

    $position = in_array(bh_param($body, 'imagePosition', 'bottom'), array('top', 'bottom'), true)
        ? bh_param($body, 'imagePosition', 'bottom') : 'bottom';
    $layout = in_array(bh_param($body, 'imageLayout', 'none'), array('none', 'grid9', 'grid4', 'row', 'column'), true)
        ? bh_param($body, 'imageLayout', 'none') : 'none';

    $loc = isset($body['location']) && is_array($body['location']) ? $body['location'] : array();
    $locName = bh_cut(trim((string)($loc['name'] ?? '')), 100);
    $locAddr = bh_cut(trim((string)($loc['address'] ?? '')), 200);
    $locLat = is_numeric($loc['latitude'] ?? null) ? (float)$loc['latitude'] : null;
    $locLng = is_numeric($loc['longitude'] ?? null) ? (float)$loc['longitude'] : null;
    if ($locLat !== null && ($locLat < -90 || $locLat > 90)) {
        $locLat = null;
    }
    if ($locLng !== null && ($locLng < -180 || $locLng > 180)) {
        $locLng = null;
    }

    // 唯一 slug
    $slug = bh_slug($title);
    if ($slug === '') {
        $slug = 'talk-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
    if ($bhDal['selectOne']('contents', 'cid', array('slug' => $slug, 'type' => 'post'))) {
        $slug .= '-' . time();
    }

    // 组装正文（markdown + 图片块）
    $block = bh_storys_html($layout, $imgs, isset($settings['lightbox']) ? $settings['lightbox'] : '1');
    $text = '<!--markdown-->';
    if ($position === 'top' && $block !== '') {
        $text .= $block . "\n\n";
    }
    $text .= $content;
    if ($position === 'bottom' && $block !== '') {
        $text .= "\n\n" . $block;
    }

    // 发布文章
    try {
        $bhDal['insert']('contents', array(
            'title' => $title,
            'slug' => $slug,
            'created' => time(),
            'modified' => time(),
            'text' => $text,
            'authorId' => 1,
            'type' => 'post',
            'status' => 'publish',
            'commentsNum' => 0,
            'allowComment' => 1,
            'allowPing' => 1,
            'allowFeed' => 1,
            'parent' => 0,
        ));
    } catch (\Throwable $e) {
        bh_reply(500, '文章创建失败：' . $e->getMessage());
    }
    $row = $bhDal['selectOne']('contents', 'cid', array('slug' => $slug, 'type' => 'post'), 'cid');
    $cid = $row ? (int)$row['cid'] : 0;
    if ($cid <= 0) {
        bh_reply(500, '文章创建失败：未获取到文章ID');
    }

    // 图片作为附件挂到文章
    foreach ($imgs as $im) {
        try {
            $bhDal['insert']('contents', array(
                'title' => $im['name'],
                'slug' => uniqid('bh'),
                'created' => time(),
                'modified' => time(),
                'text' => json_encode($im, JSON_UNESCAPED_UNICODE),
                'authorId' => 1,
                'type' => 'attachment',
                'status' => 'publish',
                'parent' => $cid,
            ));
        } catch (\Throwable $e) {
            // 附件失败不影响文章
        }
    }

    // 分类归属（count 采用 读-改-写，兼容查询构造器）
    try {
        $bhDal['insert']('relationships', array('cid' => $cid, 'mid' => $mid));
        $catRow = $bhDal['selectOne']('metas', 'count', array('mid' => $mid, 'type' => 'category'));
        $bhDal['update']('metas', array('count' => (int)($catRow ? $catRow['count'] : 0) + 1), array('mid' => $mid, 'type' => 'category'));
    } catch (\Throwable $e) {
    }

    // 标签
    foreach ($tags as $tag) {
        try {
            $tagSlug = bh_slug($tag);
            if ($tagSlug === '') {
                $tagSlug = md5($tag);
            }
            $exist = $bhDal['selectOne']('metas', array('mid', 'count'), array('slug' => $tagSlug, 'type' => 'tag'));
            if ($exist) {
                $tagMid = (int)$exist['mid'];
                $bhDal['update']('metas', array('count' => (int)$exist['count'] + 1), array('mid' => $tagMid, 'type' => 'tag'));
            } else {
                $bhDal['insert']('metas', array(
                    'name' => $tag,
                    'slug' => $tagSlug,
                    'type' => 'tag',
                    'description' => '',
                    'count' => 1,
                    'order' => 0,
                    'parent' => 0,
                ));
                $newRow = $bhDal['selectOne']('metas', 'mid', array('slug' => $tagSlug, 'type' => 'tag'));
                $tagMid = $newRow ? (int)$newRow['mid'] : 0;
            }
            if ($tagMid > 0 && !$bhDal['selectOne']('relationships', 'cid', array('cid' => $cid, 'mid' => $tagMid))) {
                $bhDal['insert']('relationships', array('cid' => $cid, 'mid' => $tagMid));
            }
        } catch (\Throwable $e) {
            // 单个标签失败不影响发布
        }
    }

    // 自定义字段：来源与位置
    try {
        $fields = array('chrison_via' => '微信小程序');
        if ($locName !== '') {
            $fields['chrison_location_name'] = $locName;
        }
        if ($locAddr !== '') {
            $fields['chrison_location_address'] = $locAddr;
        }
        if ($locLat !== null) {
            $fields['chrison_location_latitude'] = (string)$locLat;
        }
        if ($locLng !== null) {
            $fields['chrison_location_longitude'] = (string)$locLng;
        }
        foreach ($fields as $name => $value) {
            $bhDal['insert']('fields', array(
                'cid' => $cid,
                'name' => $name,
                'type' => 'str',
                'str_value' => $value,
                'int_value' => 0,
                'float_value' => 0,
            ));
        }
    } catch (\Throwable $e) {
        // 字段失败不影响发布
    }

    bh_reply(0, '发布成功', array('cid' => $cid));
}
