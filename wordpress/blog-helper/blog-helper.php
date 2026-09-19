<?php
/**
 * Plugin Name: Blog Helper
 * Plugin URI: https://chrison.cn/work/433.html
 * Description: 一键接收「目的地-Destination博客助手」小程序的推送（微信运动步数 / 心情状态），自动建表存储。启用后到 设置 → Blog Helper 复制接口地址与授权密令，填入小程序即可完成对接。
 * Author: Chrison
 * Author URI: https://chrison.cn
 * Version: 2.0.0
 * Text Domain: blog-helper
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BLOG_HELPER_VERSION', '2.0.0');

// ---------------------------------------------------------------------------
// 激活：创建三张数据表（日志表 / 运动明细表 / 心情状态明细表），无需手动连数据库
// ---------------------------------------------------------------------------
function blog_helper_activate()
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $prefix = $wpdb->prefix . 'blog_helper_';
    $charset = $wpdb->get_charset_collate();

    $sql = [];
    // 运动明细表
    $sql[] = "CREATE TABLE {$prefix}wechat (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        openid VARCHAR(64) NOT NULL,
        steps INT UNSIGNED NOT NULL DEFAULT 0,
        step_date DATE NOT NULL,
        created DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_created (created),
        KEY idx_openid (openid)
    ) $charset";
    // 心情状态明细表
    $sql[] = "CREATE TABLE {$prefix}status (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        openid VARCHAR(64) NOT NULL,
        emoji_id VARCHAR(32) NOT NULL DEFAULT '',
        emoji_name VARCHAR(32) NOT NULL DEFAULT '',
        emoji VARCHAR(32) NOT NULL DEFAULT '',
        custom_text VARCHAR(100) NOT NULL DEFAULT '',
        created DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_created (created),
        KEY idx_openid (openid)
    ) $charset";
    // 日志表
    $sql[] = "CREATE TABLE {$prefix}log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        openid VARCHAR(64) NOT NULL DEFAULT '',
        action VARCHAR(32) NOT NULL DEFAULT '',
        request_data TEXT NULL,
        result TINYINT NOT NULL DEFAULT 0,
        created DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_created (created)
    ) $charset";

    foreach ($sql as $statement) {
        dbDelta($statement);
    }
    // 首次启用生成授权密钥
    $options = get_option('blog_helper_options', []);
    if (empty($options['secret_key'])) {
        $options['secret_key'] = wp_generate_password(32, false, false);
        update_option('blog_helper_options', $options);
    }
}
register_activation_hook(__FILE__, 'blog_helper_activate');

// 停用不删表，保留用户数据

// ---------------------------------------------------------------------------
// REST 接口：POST /wp-json/blog-helper/v1/push（真实推送，由中央服务器调用）
// 浏览器直接访问（GET 或空请求体）会返回插件运行状态，方便确认部署是否成功
// 若固定链接关闭 REST 不可用，等价地址：/index.php?rest_route=/blog-helper/v1/push
// ---------------------------------------------------------------------------
add_action('rest_api_init', function () {
    register_rest_route('blog-helper/v1', '/push', [
        'methods' => ['POST', 'GET'],
        'callback' => 'blog_helper_handle_push',
        'permission_callback' => '__return_true',
    ]);
});

/**
 * 中央服务器转发协议：
 *   {action, openid, timestamp, nonce, ...业务字段, sign}
 *   sign = sha256(action + openid + timestamp + nonce + keyfield + secret) 小写hex
 *   keyfield：steps→步数字符串；status→emojiId；ping→空串；timestamp 偏差 ≤300 秒
 * 响应统一：{"code":0,"msg":"ok","data":{...}}
 */
function blog_helper_handle_push(WP_REST_Request $request)
{
    $body = $request->get_json_params();
    // 浏览器直接访问（GET 或空请求体）：返回插件状态，方便确认部署是否成功
    if (empty($body)) {
        return blog_helper_reply(0, 'Blog Helper WordPress 插件运行正常', [
            'plugin' => 'BlogHelper-WordPress',
            'version' => BLOG_HELPER_VERSION,
            'usage' => 'POST JSON {action:ping|steps|status,...}（由中央服务器转发调用）',
        ]);
    }
    if (!is_array($body)) {
        return blog_helper_reply(400, '请求体不是合法JSON');
    }

    $action = isset($body['action']) ? trim($body['action']) : '';
    $openid = isset($body['openid']) ? trim($body['openid']) : '';
    $timestamp = isset($body['timestamp']) ? trim($body['timestamp']) : '';
    $nonce = isset($body['nonce']) ? trim($body['nonce']) : '';
    $sign = isset($body['sign']) ? trim($body['sign']) : '';

    if ($action === '' || $openid === '' || $sign === '') {
        return blog_helper_reply(4001, '参数异常（action/openid/sign 缺失）');
    }
    if (!preg_match('/^[A-Za-z0-9_-]{6,64}$/', $openid)) {
        return blog_helper_reply(4001, 'openid 格式异常');
    }
    if (!ctype_digit((string)$timestamp) || abs(time() - (int)$timestamp) > 300) {
        return blog_helper_reply(4002, '请求已过期，请校准服务器时间');
    }
    if (strlen($nonce) < 8) {
        return blog_helper_reply(4001, 'nonce 参数异常');
    }

    $options = get_option('blog_helper_options', []);
    $secret = isset($options['secret_key']) ? (string)$options['secret_key'] : '';
    if (strlen($secret) < 16) {
        return blog_helper_reply(500, '插件未正确配置密钥，请到 设置 → Blog Helper 检查');
    }

    switch ($action) {
        case 'ping':
            $keyfield = '';
            break;
        case 'steps':
            $keyfield = isset($body['steps']) ? (string)(int)$body['steps'] : '';
            break;
        case 'status':
            // 签名 keyfield 必须为原始值（与中央服务器签名串一致）
            $keyfield = isset($body['emojiId']) ? trim((string)$body['emojiId']) : '';
            break;
        case 'storys':
            $keyfield = isset($body['title']) ? trim((string)$body['title']) : '';
            break;
        default:
            return blog_helper_reply(4004, 'action[' . sanitize_text_field($action) . '] 暂未开放');
    }

    // 签名校验必须使用原始值（未 sanitize）
    $expected = hash('sha256', $action . $openid . $timestamp . $nonce . $keyfield . $secret);
    if (!hash_equals($expected, $sign)) {
        blog_helper_log($openid, $action, $body, 0);
        return blog_helper_reply(403, '签名校验失败，请检查授权密令是否一致');
    }

    switch ($action) {
        case 'ping':
            return blog_helper_reply(0, 'pong', ['plugin' => 'BlogHelper-WordPress', 'version' => BLOG_HELPER_VERSION]);
        case 'steps':
            return blog_helper_save_steps($openid, (int)$body['steps']);
        case 'status':
            return blog_helper_save_status($openid, $body);
        case 'storys':
            return blog_helper_save_storys($openid, $body);
    }
    return blog_helper_reply(4000, '未知错误');
}

// ---------------------------------------------------------------------------
// 说说发布（storys）：下载图片到媒体库本地化 → 发布为「说说分类ID」下的文章
// ---------------------------------------------------------------------------
function blog_helper_storys_html($layout, $imgs, $lightbox = true)
{
    if (empty($imgs)) {
        return '';
    }
    if ($layout === 'none') {
        $parts = array();
        foreach ($imgs as $im) {
            $imgTag = '<img src="' . esc_url($im['url']) . '" alt="" />';
            $parts[] = '<p>' . ($lightbox
                ? '<a class="chrison_image_a" href="' . esc_url($im['url']) . '" target="_blank" rel="noopener">' . $imgTag . '</a>'
                : $imgTag) . '</p>';
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
        $imgTag = '<img src="' . esc_url($im['url']) . '" alt="" />';
        $html .= '<div class="' . $item . '">'
            . ($lightbox
                ? '<a class="chrison_image_a" href="' . esc_url($im['url']) . '" target="_blank" rel="noopener">' . $imgTag . '</a>'
                : $imgTag)
            . '</div>';
    }
    $html .= '</div>';
    // 注意：不内联 <style> —— 推送走未登录 REST 请求，wp_insert_post 会经 KSES 剥离 <style>；
    // 宫格 CSS 由 blog_helper_grid_css() 在前端按需输出
    return $html;
}

/**
 * 并行抓取说说图片原始数据（curl_multi，保持顺序，单张失败为 null）
 */
function blog_helper_fetch_images(array $urls)
{
    $urls = array_slice(array_values($urls), 0, 9);
    if (empty($urls)) {
        return array();
    }
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
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $raw[$i] = ($code >= 200 && $code < 300) ? curl_multi_getcontent($ch) : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    } else {
        foreach ($urls as $i => $url) {
            $res = wp_remote_get($url, array('timeout' => 12));
            $raw[$i] = is_wp_error($res) ? null : wp_remote_retrieve_body($res);
        }
    }

    $out = array();
    foreach ($urls as $i => $url) {
        $data = isset($raw[$i]) ? $raw[$i] : null;
        if (!$data || strlen($data) < 32 || strlen($data) > 10 * 1024 * 1024) {
            continue;
        }
        // 魔数校验：只接受真实图片内容
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
        if ($ext === '') {
            continue;
        }
        $name = wp_basename((string) parse_url($url, PHP_URL_PATH));
        if ($name === '' || !preg_match('/\.(jpe?g|png|webp|gif)$/i', $name)) {
            $name = uniqid('bh') . '.' . $ext;
        }
        $out[] = array('name' => $name, 'data' => $data);
    }
    return $out;
}

/** 宫格排版 CSS（前端输出用，与小程序所选布局对应） */
function blog_helper_grid_css()
{
    return '.chrison_grid_9,.chrison_grid_4,.chrison_row,.chrison_column{display:flex;flex-wrap:wrap;gap:4px;margin:12px 0}'
        . '.chrison_grid_9 .chrison_grid_item,.chrison_grid_4 .chrison_grid_item{width:calc((100% - 8px)/3);padding-top:calc((100% - 8px)/3*0.66);position:relative;overflow:hidden}'
        . '.chrison_grid_4 .chrison_grid_item{width:calc((100% - 4px)/2);padding-top:calc((100% - 4px)/2*0.66)}'
        . '.chrison_grid_9 .chrison_grid_item img,.chrison_grid_4 .chrison_grid_item img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}'
        . '.chrison_grid_item .chrison_image_a{position:absolute;inset:0;display:block}'
        . '.chrison_row .chrison_row_item{flex:1 1 100%}.chrison_row .chrison_row_item img{width:100%;display:block}'
        . '.chrison_column .chrison_column_item{flex:1 1 30%;min-width:120px}.chrison_column .chrison_column_item img{width:100%;display:block}'
        . '.chrison_row_item .chrison_image_a,.chrison_column_item .chrison_image_a{display:block}';
}

// 文章详情含说说排版类名时，前端注入宫格 CSS（KSES 会剥离内容里的 <style>，故由插件输出）
add_action('wp_head', function () {
    if (!is_singular('post')) {
        return;
    }
    $post = get_post();
    if (!$post || strpos((string)$post->post_content, 'chrison_grid_') === false) {
        return;
    }
    echo '<style id="blog-helper-grid">' . blog_helper_grid_css() . '</style>' . "\n";
});

function blog_helper_save_storys($openid, $body)
{
    $options = get_option('blog_helper_options', array());
    $mid = isset($options['storys_mid']) ? absint($options['storys_mid']) : 0;
    if ($mid <= 0 || get_term($mid, 'category') instanceof WP_Error) {
        return blog_helper_reply(4005, '请先在 设置 → Blog Helper 配置有效的「说说分类ID」');
    }

    // 基础字段
    $title = isset($body['title']) ? trim((string)$body['title']) : '';
    if ($title === '') {
        $title = '未命名';
    }
    $title = mb_substr(sanitize_text_field($title), 0, 100);
    $content = isset($body['content']) ? mb_substr(sanitize_textarea_field((string)$body['content']), 0, 2000) : '';

    // 标签
    $tags = array();
    if (isset($body['tags']) && is_array($body['tags'])) {
        foreach ($body['tags'] as $t) {
            $t = mb_substr(sanitize_text_field((string)$t), 0, 30);
            if ($t !== '') {
                $tags[] = $t;
            }
        }
    } elseif (!empty($body['tags'])) {
        foreach (preg_split('/[\s、；;，,]+/u', (string)$body['tags']) as $t) {
            $t = mb_substr(sanitize_text_field($t), 0, 30);
            if ($t !== '') {
                $tags[] = $t;
            }
        }
    }
    $tags = array_slice(array_values(array_unique($tags)), 0, 10);

    // 图片 URL（仅 http/https，最多 9 张）
    $urls = array();
    if (isset($body['images']) && is_array($body['images'])) {
        foreach ($body['images'] as $u) {
            $u = esc_url_raw((string)$u);
            if ($u !== '' && preg_match('#^https?://#i', $u)) {
                $urls[] = $u;
            }
        }
    }
    $urls = array_slice(array_values(array_unique($urls)), 0, 9);

    $position = in_array(isset($body['imagePosition']) ? $body['imagePosition'] : 'bottom', array('top', 'bottom'), true)
        ? $body['imagePosition'] : 'bottom';
    $layout = in_array(isset($body['imageLayout']) ? $body['imageLayout'] : 'none', array('none', 'grid9', 'grid4', 'row', 'column'), true)
        ? $body['imageLayout'] : 'none';
    $lightbox = !isset($options['lightbox']) || !empty($options['lightbox']);

    $loc = isset($body['location']) && is_array($body['location']) ? $body['location'] : array();
    $locName = isset($loc['name']) ? mb_substr(sanitize_text_field((string)$loc['name']), 0, 100) : '';
    $locAddr = isset($loc['address']) ? mb_substr(sanitize_text_field((string)$loc['address']), 0, 200) : '';
    $locLat = isset($loc['latitude']) && is_numeric($loc['latitude']) ? (float)$loc['latitude'] : null;
    $locLng = isset($loc['longitude']) && is_numeric($loc['longitude']) ? (float)$loc['longitude'] : null;
    if ($locLat !== null && ($locLat < -90 || $locLat > 90)) {
        $locLat = null;
    }
    if ($locLng !== null && ($locLng < -180 || $locLng > 180)) {
        $locLng = null;
    }

    // 图片并行下载到媒体库本地化（curl_multi，9 张同时拉取，单张超时 12 秒）
    if (!empty($urls)) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    $imgs = array();
    foreach (blog_helper_fetch_images($urls) as $imgData) {
        $tmp = tempnam(get_temp_dir(), 'bh');
        if (file_put_contents($tmp, $imgData['data']) === false) {
            continue;
        }
        // wp_handle_sideload 第一参数为按引用传递，必须先赋值变量（字面量直接传在 PHP8 是致命错误）
        $file_array = array('name' => $imgData['name'], 'tmp_name' => $tmp);
        $file = wp_handle_sideload($file_array, array('test_form' => false));
        if (!empty($file['error'])) {
            @unlink($tmp);
            continue;
        }
        $attId = wp_insert_attachment(array(
            'post_mime_type' => $file['type'],
            'post_title' => sanitize_title(pathinfo($imgData['name'], PATHINFO_FILENAME)),
            'post_status' => 'inherit',
        ), $file['file']);
        if (!is_wp_error($attId)) {
            wp_update_attachment_metadata($attId, wp_generate_attachment_metadata($attId, $file['file']));
            $imgs[] = array('url' => wp_get_attachment_url($attId), 'id' => $attId);
        }
    }

    // 组装正文
    $block = blog_helper_storys_html($layout, $imgs, $lightbox);
    $contentHtml = $position === 'top'
        ? $block . "\n\n" . wp_kses_post($content)
        : wp_kses_post($content) . ($block !== '' ? "\n\n" . $block : '');

    $postId = wp_insert_post(array(
        'post_title' => $title,
        'post_content' => $contentHtml,
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_author' => 1,
        'post_category' => array($mid),
        'tags_input' => $tags,
        'comment_status' => 'open',
    ), true);
    if (is_wp_error($postId)) {
        return blog_helper_reply(500, '文章创建失败：' . $postId->get_error_message());
    }

    // 来源与位置 meta
    update_post_meta($postId, 'chrison_via', '微信小程序');
    if ($locName !== '') {
        update_post_meta($postId, 'chrison_location_name', $locName);
    }
    if ($locAddr !== '') {
        update_post_meta($postId, 'chrison_location_address', $locAddr);
    }
    if ($locLat !== null) {
        update_post_meta($postId, 'chrison_location_latitude', (string)$locLat);
    }
    if ($locLng !== null) {
        update_post_meta($postId, 'chrison_location_longitude', (string)$locLng);
    }

    blog_helper_log($openid, 'storys', array('title' => $title, 'images' => count($imgs)), 1);
    return blog_helper_reply(0, '发布成功', array('post_id' => $postId));
}

function blog_helper_save_steps($openid, $steps)
{
    global $wpdb;
    $table = $wpdb->prefix . 'blog_helper_wechat';
    $ok = $wpdb->insert($table, [
        'openid' => $openid,
        'steps' => $steps,
        'step_date' => current_time('Y-m-d'),
        'created' => current_time('mysql'),
    ]);
    if ($ok === false) {
        blog_helper_log($openid, 'steps', ['steps' => $steps], 0);
        return blog_helper_reply(500, '步数入库失败：' . $wpdb->last_error);
    }
    blog_helper_log($openid, 'steps', ['steps' => $steps], 1);
    return blog_helper_reply(0, '步数同步成功');
}

function blog_helper_save_status($openid, $body)
{
    global $wpdb;
    $table = $wpdb->prefix . 'blog_helper_status';
    $row = [
        'openid' => $openid,
        'emoji_id' => mb_substr(sanitize_text_field(wp_unslash(isset($body['emojiId']) ? $body['emojiId'] : '')), 0, 32),
        'emoji_name' => mb_substr(sanitize_text_field(wp_unslash(isset($body['emojiName']) ? $body['emojiName'] : '')), 0, 32),
        'emoji' => mb_substr((string)(isset($body['emoji']) ? $body['emoji'] : ''), 0, 32),
        'custom_text' => mb_substr(sanitize_text_field(wp_unslash(isset($body['customText']) ? $body['customText'] : '')), 0, 100),
        'created' => current_time('mysql'),
    ];
    $ok = $wpdb->insert($table, $row);
    if ($ok === false) {
        blog_helper_log($openid, 'status', $row, 0);
        return blog_helper_reply(500, '状态入库失败：' . $wpdb->last_error);
    }
    blog_helper_log($openid, 'status', $row, 1);
    return blog_helper_reply(0, '状态同步成功');
}

function blog_helper_log($openid, $action, $data, $result)
{
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'blog_helper_log', [
        'openid' => $openid,
        'action' => substr($action, 0, 32),
        'request_data' => wp_json_encode($data),
        'result' => $result ? 1 : 0,
        'created' => current_time('mysql'),
    ]);
}

function blog_helper_reply($code, $msg, $data = null)
{
    $resp = ['code' => $code, 'msg' => $msg];
    if ($data !== null) {
        $resp['data'] = $data;
    }
    $response = new WP_REST_Response($resp, 200);
    return $response;
}

// ---------------------------------------------------------------------------
// 前端调用：模板函数 + 短代码
// 图标说明：心情图标为白色线稿 PNG（插件内置 assets/status/），显示时需放在深色/彩色背景上
// ---------------------------------------------------------------------------

/**
 * emojiId → 内置图标 URL；规则：取最后一段（xqxf-mzz → mzz.png）；emoji / 空 → 返回空串
 */
function blog_helper_status_icon_url($emoji_id)
{
    $id = trim((string)$emoji_id);
    if ($id === '' || $id === 'emoji') {
        return '';
    }
    $code = strpos($id, '-') !== false ? substr($id, strrpos($id, '-') + 1) : $id;
    if (!preg_match('/^[a-z0-9]+$/', $code)) {
        return '';
    }
    $path = plugin_dir_path(__FILE__) . 'assets/status/' . $code . '.png';
    if (!is_file($path)) {
        return '';
    }
    return plugins_url('assets/status/' . $code . '.png', __FILE__);
}

/**
 * 最新一条步数记录（$openid 传空则取全站最新）
 */
function blog_helper_get_latest_steps($openid = '')
{
    global $wpdb;
    $table = $wpdb->prefix . 'blog_helper_wechat';
    if ($openid !== '') {
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE openid = %s ORDER BY created DESC LIMIT 1", $openid), ARRAY_A);
    } else {
        $row = $wpdb->get_row("SELECT * FROM {$table} ORDER BY created DESC LIMIT 1", ARRAY_A);
    }
    return $row ? $row : null;
}

/**
 * 最新一条心情状态（含 icon_url）
 */
function blog_helper_get_latest_status($openid = '')
{
    global $wpdb;
    $table = $wpdb->prefix . 'blog_helper_status';
    if ($openid !== '') {
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE openid = %s ORDER BY created DESC LIMIT 1", $openid), ARRAY_A);
    } else {
        $row = $wpdb->get_row("SELECT * FROM {$table} ORDER BY created DESC LIMIT 1", ARRAY_A);
    }
    if ($row) {
        $row['icon_url'] = blog_helper_status_icon_url($row['emoji_id']);
    }
    return $row ? $row : null;
}

// [blog_helper_steps label="今天走了" unit="步" show_date="yes" openid=""]
add_shortcode('blog_helper_steps', function ($atts) {
    $a = shortcode_atts([
        'label' => '今天走了',
        'unit' => '步',
        'show_date' => 'yes',
        'openid' => '',
    ], $atts, 'blog_helper_steps');
    $row = blog_helper_get_latest_steps($a['openid']);
    if (!$row) {
        return '';
    }
    $out = esc_html($a['label']) . ' <strong style="font-size:1.4em">' . (int)$row['steps'] . '</strong> ' . esc_html($a['unit']);
    if ($a['show_date'] === 'yes') {
        $out .= ' <span style="color:#999;font-size:0.85em">' . esc_html($row['created']) . '</span>';
    }
    return '<span class="blog-helper-steps">' . $out . '</span>';
});

// [blog_helper_status size="40" show_name="yes" openid=""]
add_shortcode('blog_helper_status', function ($atts) {
    $a = shortcode_atts([
        'size' => 40,
        'show_name' => 'yes',
        'openid' => '',
    ], $atts, 'blog_helper_status');
    $row = blog_helper_get_latest_status($a['openid']);
    if (!$row) {
        return '';
    }
    $size = max(20, (int)$a['size']);
    $inner = '';
    if ($row['emoji_id'] === 'emoji' && $row['emoji'] !== '') {
        // emoji 推送：直接渲染 emoji 字符
        $inner = '<span style="font-size:' . ($size + 6) . 'px;line-height:1">' . esc_html($row['emoji']) . '</span>';
    } elseif ($row['icon_url'] !== '') {
        // 白色线稿图标：衬深色圆角底
        $inner = '<img src="' . esc_url($row['icon_url']) . '" alt="' . esc_attr($row['emoji_name']) . '"'
            . ' style="width:' . $size . 'px;height:' . $size . 'px;background:#2F353D;border-radius:24%;padding:' . round($size * 0.12) . 'px;box-sizing:border-box;vertical-align:middle" />';
    } else {
        $inner = '<span style="font-size:' . ($size - 6) . 'px">' . esc_html($row['emoji_name']) . '</span>';
    }
    $out = '<span style="display:inline-flex;align-items:center;gap:8px">' . $inner;
    if ($a['show_name'] === 'yes' && $row['emoji_name'] !== '') {
        $out .= '<span style="font-weight:600">' . esc_html($row['emoji_name']) . '</span>';
    }
    if ($row['custom_text'] !== '') {
        $out .= '<span style="color:#666">' . esc_html($row['custom_text']) . '</span>';
    }
    $out .= '</span>';
    return '<span class="blog-helper-status">' . $out . '</span>';
});

// ---------------------------------------------------------------------------
// 设置页：设置 → Blog Helper
// ---------------------------------------------------------------------------
add_action('admin_menu', function () {
    add_options_page('Blog Helper', 'Blog Helper', 'manage_options', 'blog-helper', 'blog_helper_settings_page');
});

add_action('admin_init', function () {
    // 一键重新生成密钥
    if (isset($_POST['blog_helper_regenerate']) && check_admin_referer('blog_helper_regenerate')) {
        $options = get_option('blog_helper_options', []);
        $options['secret_key'] = wp_generate_password(32, false, false);
        update_option('blog_helper_options', $options);
        add_settings_error('blog_helper', 'regenerated', '授权密令已重新生成，请更新小程序「我的」页中的密钥。', 'updated');
    }
    // 说说分类ID设置
    register_setting('blog_helper_settings', 'blog_helper_options', array(
        'sanitize_callback' => 'blog_helper_sanitize_options',
    ));
});

function blog_helper_sanitize_options($input)
{
    $out = (array) get_option('blog_helper_options', array());
    $out['storys_mid'] = isset($input['storys_mid']) ? absint($input['storys_mid']) : 0;
    $out['lightbox'] = empty($input['lightbox']) ? 0 : 1;
    return $out;
}

function blog_helper_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $options = get_option('blog_helper_options', []);
    $secret = isset($options['secret_key']) ? $options['secret_key'] : '';
    $rest = rest_url('blog-helper/v1/push');
    $restFallback = home_url('/index.php?rest_route=/blog-helper/v1/push');
    settings_errors('blog_helper');
    ?>
    <div class="wrap">
        <h1>Blog Helper 设置</h1>
        <p>小程序「目的地-Destination博客助手」推送对接配置。把下面的两项填入小程序「我的」页并保存即可。</p>
        <p><img src="<?php echo esc_url(plugins_url('assets/qrcode.jpeg', __FILE__)); ?>"
                alt="小程序码" style="width:160px;height:160px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.12)" /><br>
           <span class="description">微信扫码直接打开小程序</span></p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">接口地址</th>
                <td><code><?php echo esc_html($rest); ?></code><br>
                    <p class="description">如提示接口 404，请改用：<code><?php echo esc_html($restFallback); ?></code></p>
                </td>
            </tr>
            <tr>
                <th scope="row">授权密令</th>
                <td><code><?php echo esc_html($secret); ?></code></td>
            </tr>
        </table>
        <form method="post">
            <?php wp_nonce_field('blog_helper_regenerate'); ?>
            <p><button type="submit" name="blog_helper_regenerate" value="1" class="button button-secondary"
                       onclick="return confirm('重新生成后旧密钥立即失效，需要同步更新小程序端，确定吗？');">重新生成密钥</button></p>
        </form>
        <h2>说说发布设置</h2>
        <form method="post" action="options.php">
            <?php settings_fields('blog_helper_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="storys_mid">说说分类ID</label></th>
                    <td>
                        <input name="blog_helper_options[storys_mid]" id="storys_mid" type="number" min="0"
                               value="<?php echo esc_attr(isset($options['storys_mid']) ? $options['storys_mid'] : 0); ?>" class="small-text" />
                        <p class="description">小程序「说说」发射后会发布为该分类下的文章。在「文章 → 分类目录」中把鼠标移到分类上，状态栏链接里的 <code>tag_ID=XX</code> 即为分类ID。留空或填 0 则说说无法发射。</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">图片灯箱链接</th>
                    <td>
                        <label>
                            <input name="blog_helper_options[lightbox]" type="checkbox" value="1"
                                   <?php checked(!isset($options['lightbox']) || !empty($options['lightbox'])); ?> />
                            点击说说图片查看原图
                        </label>
                        <p class="description">开启后说说文章里的图片会包一层指向原图的链接：主题自带灯箱时会接管弹出大图；无灯箱的主题点击则在浏览器新标签页打开原图。仅影响之后新发射的说说。</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('保存设置'); ?>
        </form>

        <h2>前端调用（短代码）</h2>
        <p>把下面的短代码粘贴到文章、页面或小工具中即可展示小程序推送的数据（支持 <code>openid</code> 属性按用户筛选，如 <code>[blog_helper_status openid="oXxxx"]</code>）：</p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">最新步数</th>
                <td>
                    <code id="bh-code-steps" style="user-select:all">[blog_helper_steps]</code>
                    <button type="button" class="button button-small" onclick="bhCopyCode('bh-code-steps', this)">复制</button>
                    <p class="description">可选属性：label（前缀文字）、unit（单位）、show_date（是否显示时间 yes/no）</p>
                </td>
            </tr>
            <tr>
                <th scope="row">最新心情状态</th>
                <td>
                    <code id="bh-code-status" style="user-select:all">[blog_helper_status]</code>
                    <button type="button" class="button button-small" onclick="bhCopyCode('bh-code-status', this)">复制</button>
                    <p class="description">可选属性：size（图标像素，默认40）、show_name（是否显示状态名 yes/no）。心情图标为白色线稿，短代码已自动衬深色底显示</p>
                </td>
            </tr>
            <tr>
                <th scope="row">模板函数（主题 PHP 中调用）</th>
                <td>
                    <code id="bh-code-php" style="user-select:all">&lt;?php $s = blog_helper_get_latest_steps(); echo $s ? $s['steps'] : 0; ?&gt;</code>
                    <button type="button" class="button button-small" onclick="bhCopyCode('bh-code-php', this)">复制</button>
                    <p class="description">另有 blog_helper_get_latest_status()（含 icon_url）、blog_helper_status_icon_url($emoji_id)</p>
                </td>
            </tr>
        </table>
        <script>
            function bhCopyCode(id, btn) {
                var text = document.getElementById(id).innerText;
                var done = function () { btn.textContent = '已复制'; setTimeout(function () { btn.textContent = '复制'; }, 1500); };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done);
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = text; document.body.appendChild(ta); ta.select();
                    document.execCommand('copy'); document.body.removeChild(ta); done();
                }
                return false;
            }
        </script>

        <h2>数据表</h2>
        <p>启用时已自动创建（无需手动连数据库）：<code><?php echo esc_html($GLOBALS['wpdb']->prefix); ?>blog_helper_wechat</code>（运动明细）、
            <code>…blog_helper_status</code>（心情状态明细）、<code>…blog_helper_log</code>（日志）。停用插件不会删除数据。</p>
    </div>
    <?php
}
