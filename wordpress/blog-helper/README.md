# Blog Helper - WordPress 插件（小程序对接端）

接收「目的地-Destination博客助手」小程序推送的步数与心情状态，自动建表存储。

## 安装（2 分钟）

1. 把 `blog-helper` 整个文件夹上传到 `wp-content/plugins/`（或压缩后在后台“插件 → 安装插件 → 上传”）；
2. 后台启用 **Blog Helper**（启用时自动创建三张表，无需手动连数据库）：
   - `{前缀}blog_helper_wechat` 运动明细表
   - `{前缀}blog_helper_status` 心情状态明细表
   - `{前缀}blog_helper_log` 日志表
3. 进入「设置 → Blog Helper」，复制：
   - **接口地址**：`https://你的域名/wp-json/blog-helper/v1/push`
     （如提示 404，使用 `https://你的域名/index.php?rest_route=/blog-helper/v1/push`）
   - **授权密令**（启用时自动生成）
4. **说说发布**：在 设置 → Blog Helper 的「说说发布设置」中填写「说说分类ID」（文章 → 分类目录，链接里 tag_ID=XX），保存；小程序「说说」发射后即发布为该分类下的文章（图片自动下载到媒体库，位置存入文章 meta）。
5. 打开小程序 →「我的」→ 粘贴这两项 → 保存 → 点「测试博客连通」，显示 pong 即全部打通。

## 验证部署

启用后用浏览器直接访问接口地址（`/wp-json/blog-helper/v1/push` 或 `index.php?rest_route=` 等价地址），应显示
`{"code":0,"msg":"Blog Helper WordPress 插件运行正常",...}` 即部署成功；真实推送由中央服务器以 POST+签名调用，无需人工验证签名。

## 前端调用（短代码）

插件已内置心情图标（`assets/status/`，35 张白色线稿 PNG，短代码会自动衬深色底显示）。

在文章/页面/小工具中直接粘贴（设置页也有同样的代码和一键复制按钮）：

- `[blog_helper_steps]` —— 最新步数，可选：`label`（前缀文字）、`unit`（单位）、`show_date`（yes/no）、`openid`
- `[blog_helper_status]` —— 最新心情状态（图标+名称+文字），可选：`size`（图标像素，默认 40）、`show_name`（yes/no）、`openid`

主题 PHP 中调用模板函数：

```php
<?php
$s = blog_helper_get_latest_steps();      // 传 openid 可取指定用户
$status = blog_helper_get_latest_status(); // 含 icon_url
$icon = blog_helper_status_icon_url('xqxf-mzz'); // → 插件图标 URL
?>
```

## 说明

- 密钥泄露处理：设置页点「重新生成密钥」，然后同步更新小程序端；
- 停用插件不删表，卸载前请自行备份数据；
- 协议为带 timestamp+nonce 防重放的 sha256 签名，需配合新版中央服务器使用。
