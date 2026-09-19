# Blog Helper - Typecho 插件（小程序对接端）

接收「目的地-Destination博客助手」小程序推送的步数与心情状态，自动建表存储。
适用于 **Typecho 1.2 / 1.3+**（命名空间结构）；推送入口为独立文件，不依赖 Typecho 类库与路由，不受版本升级影响。

## 安装（2 分钟）

1. 把 `BlogHelper` 整个文件夹上传到网站 `usr/plugins/` 目录；
2. Typecho 后台「控制台 → 插件」启用 **Blog Helper**（启用时自动创建三张表，无需手动连数据库）：
   - `{前缀}blog_helper_wechat` 运动明细表
   - `{前缀}blog_helper_status` 心情状态明细表
   - `{前缀}blog_helper_log` 日志表
3. 进入插件设置，复制页面上显示的两项：
   - **接口地址**：`https://你的域名/usr/plugins/BlogHelper/push.php`
   - **授权密令**（启用后自动生成）
4. **说说发布**：在插件设置中填写「说说分类ID」（管理 → 分类 → 编辑页地址栏 mid=XXX），小程序「说说」发射后即发布为该分类下的文章（图片自动下载到 usr/uploads 并作为附件，含位置自定义字段）。
4. 打开小程序 →「我的」→ 粘贴这两项 → 保存 → 点「测试博客连通」，显示 pong 即全部打通。

## 验证部署

启用后用浏览器直接访问推送入口（`https://你的域名/usr/plugins/BlogHelper/push.php`），应显示
`{"code":0,"msg":"Blog Helper Typecho 插件运行正常",...}` 即部署成功；真实推送由中央服务器以 POST+签名调用，无需人工验证签名。

## 主题前端调用

心情图标已内置在插件 `assets/status/`（35 张白色线稿 PNG，与小程序一致）。
**注意：图标是白色线稿，显示时请放在深色/彩色背景上**，例如 `<img src="..." style="background:#4A5568;border-radius:20%">`。

主题模板中直接调用（`TypechoPlugin\BlogHelper\Api` 类随插件自动加载，**开头需要反斜杠**）：

```php
<?php
use TypechoPlugin\BlogHelper\Api;

// 最新步数
$s = Api::latestSteps();          // 传 openid 可取指定用户
if ($s) echo $s['steps'] . ' 步（' . $s['step_date'] . '）';

// 最新状态（含 icon_url 图标地址；emoji 推送无图标，用 $s['emoji'] 渲染字符）
$status = Api::latestStatus();
if ($status) {
    if ($status['icon_url']) {
        echo '<img src="' . $status['icon_url'] . '" style="background:#4A5568;border-radius:20%;width:32px;height:32px">';
    } else {
        echo $status['emoji'];
    }
    echo ' ' . $status['emoji_name'] . ' ' . $status['custom_text'];
}
?>
```

可用方法：`latestSteps($openid='')`、`latestStatus($openid='')`、`iconUrl($emojiId)`、`iconCode($emojiId)`。
调用示例也会显示在插件设置页，方便随时复制。

## 说明

- 推送入口为独立文件 `push.php`：直接读取 Typecho 配置连接数据库，不注册路由、不依赖 Typecho 类库，**Typecho 升级不影响接口**；
- 签名协议带 timestamp+nonce 防重放（sha256），需配合新版中央服务器使用；
- 停用插件**不删除数据表**；
- 密钥泄露处理：插件设置中清空密钥并保存 → 刷新设置页自动生成新密钥 → 同步更新小程序端；
- 主题调用类为命名空间类，模板中使用时注意开头 `\`（如 `\TypechoPlugin\BlogHelper\Api::latestSteps()`）。
