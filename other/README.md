# 其他博客类型 · API 对接文档（自建接收端）

> 📖 **完整使用文档**：<https://chrison.cn/work/520.html>

适用于**非 Typecho、非 WordPress** 的自建博客/网站系统（Java/Python/Node.js/Go/.NET 等任何能跑 HTTP 服务的环境）。
按本文档实现一个接收接口，即可让「目的地-Destination博客助手」小程序把**微信运动步数、心情状态、说说（文字+图片+位置）**推送到你的系统。

> 小程序里选择博客类型「其他」（或 SpringBoot），接口地址填你实现的完整 URL 即可完成对接。

---

## 一、协议总览

中央服务器会把用户的每一次「发射」以 **POST + JSON** 转发到你配置的接口地址：

```
POST https://你的域名/你的接收路径
Content-Type: application/json

{
  "action": "steps",              // ping | steps | status | storys
  "openid": "oXxxxxxxxxxxxxxxxx", // 用户唯一标识（64位内，字母数字_-）
  "timestamp": "1694000000",      // 转发时刻的 Unix 秒级时间戳（字符串）
  "nonce": "a1b2c3d4e5f6g7h8",    // 16位随机字符串
  ...业务字段（见下）...,
  "sign": "sha256签名（64位小写hex）"
}
```

**签名算法**（防伪造 + 防重放）：

```
sign = sha256( action + openid + timestamp + nonce + keyfield + secret )   // 小写hex
```

- `keyfield` 随 action 不同：
  | action | keyfield |
  |---|---|
  | `ping` | 空字符串 `""` |
  | `steps` | 步数的**十进制字符串**，如 `"6666"` |
  | `status` | `emojiId` 原始字符串 |
  | `storys` | `title` 原始字符串 |
- `secret` = 你自定义的授权密令（建议 32 位以上随机字符串），需同时填入小程序「我的」页。
- 校验规则（建议与官方插件一致）：
  - `timestamp` 与你的服务器时间相差 **≤ 300 秒**，否则返回 4002；
  - `nonce` 长度 **≥ 8**；
  - `openid` 匹配 `^[A-Za-z0-9_-]{6,64}$`；
  - 签名比对使用**恒定时间比较**（如 PHP `hash_equals`、Java `MessageDigest.isEqual`）。
- **注意**：参与签名的 `keyfield` 必须使用**原始值**（未做任何清洗/转义），否则特殊字符会导致校验失败。

**统一响应**（HTTP 200，JSON）：

```json
{ "code": 0, "msg": "ok", "data": { } }
```

| code | 含义 |
|---|---|
| 0 | 成功 |
| 4001 | 参数异常 |
| 4002 | 请求过期（timestamp 偏差过大） |
| 4003 | 配置缺失 |
| 403 | 签名校验失败 |
| 4004 | action 未开放/未知 |
| 4005 | 业务配置缺失（如说说分类ID未配置） |
| 500 | 服务器内部错误 |

`code != 0` 时 `msg` 会原样显示在小程序发射结果的弹窗中，请返回用户能看懂的中文。

---

## 二、签名实现示例（可直接复制）

**PHP**

```php
$expected = hash('sha256', $action . $openid . $timestamp . $nonce . $keyfield . $secret);
if (!hash_equals($expected, $sign)) { /* 403 */ }
```

**Java**

```java
MessageDigest digest = MessageDigest.getInstance("SHA-256");
byte[] hash = digest.digest(text.getBytes(StandardCharsets.UTF_8));
// 转小写hex后与 sign 用 MessageDigest.isEqual 比较
```

**Python**

```python
import hashlib
expected = hashlib.sha256((action + openid + timestamp + nonce + keyfield + secret).encode('utf-8')).hexdigest()
# hmac.compare_digest(expected, sign)
```

**Node.js**

```js
const expected = require('crypto').createHash('sha256')
  .update(action + openid + timestamp + nonce + keyfield + secret).digest('hex');
```

**签名自测向量**（实现后先用它验证，必须一致）：

```
action    = steps
openid    = oTest
timestamp = 1694000000
nonce     = deadbeefdeadbeef
keyfield  = 6666          （steps=6666 的字符串）
secret    = mysecret
sign      = a8727d6c325d5386907c6e1350fe6c7ab808d5a255c0dae543d1e0c290187548
```

---

## 三、四个 action 详解

### 1. ping — 连通测试

小程序「我的」页点「测试博客连通」时触发，用于部署验证。

- 额外字段：无（keyfield 为空串）
- 成功响应建议：`{"code":0,"msg":"pong","data":{"plugin":"你的系统名"}}`
- 收到任何合法签名的 ping 都返回 pong 即可，无需入库。

```bash
# 联调示例（secret=mysecret）
curl -X POST https://你的域名/你的接收路径 -H 'Content-Type: application/json' \
  -d '{"action":"ping","openid":"oTest","timestamp":1694000000,"nonce":"deadbeefdeadbeef","sign":"<按算法计算>"}'
```

### 2. steps — 微信运动步数

| 字段 | 类型 | 说明 |
|---|---|---|
| `steps` | int | 当日步数（0 ~ 100000+） |

建议存储表（前缀 `blog_helper_` 防冲突）：

```sql
CREATE TABLE IF NOT EXISTS blog_helper_wechat (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    openid VARCHAR(64) NOT NULL,
    steps INT UNSIGNED NOT NULL DEFAULT 0,
    step_date DATE NOT NULL,
    created DATETIME NOT NULL,
    PRIMARY KEY (id), KEY idx_created (created), KEY idx_openid (openid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

成功响应：`{"code":0,"msg":"步数同步成功"}`

### 3. status — 心情状态

| 字段 | 类型 | 说明 |
|---|---|---|
| `emojiId` | string(32) | 心情标识，如 `xqxf-mzz`；纯 emoji 选择时为 `"emoji"` |
| `emojiName` | string(32) | 心情名，如 `美滋滋`；emoji 时为 emoji 对应名称 |
| `emoji` | string(32) | emoji 字符（如 `😀`），选图标时为空串 |
| `customText` | string(100) | 附带文字，可能为空 |

建议存储表：

```sql
CREATE TABLE IF NOT EXISTS blog_helper_status (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    openid VARCHAR(64) NOT NULL,
    emoji_id VARCHAR(32) NOT NULL DEFAULT '',
    emoji_name VARCHAR(32) NOT NULL DEFAULT '',
    emoji VARCHAR(32) NOT NULL DEFAULT '',
    custom_text VARCHAR(100) NOT NULL DEFAULT '',
    created DATETIME NOT NULL,
    PRIMARY KEY (id), KEY idx_created (created), KEY idx_openid (openid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

成功响应：`{"code":0,"msg":"状态同步成功"}`

### 4. storys — 说说（文字 + 图片 + 位置）

| 字段 | 类型 | 说明 |
|---|---|---|
| `title` | string(100) | 标题，用户未填时为 `"未命名"` |
| `content` | string(≤1000) | 正文（小程序限 500 字） |
| `tags` | array | 标签数组，最多 10 个，如 `["生活","记录"]` |
| `images` | array | 图片 URL 数组，**最多 9 张**（存储在中央服务器 `uploads/`，公网可访问；已压缩至朋友圈水平：长边 ≤1440px、JPEG 质量 78） |
| `imagePosition` | string | `top`（正文开头）\| `bottom`（正文结尾，默认） |
| `imageLayout` | string | `none`（无样式）\| `grid9`（九宫格）\| `grid4`（四宫格）\| `row`（一行）\| `column`（一列） |
| `location` | object | 位置打卡：`{name(100), address(200), latitude(-90~90), longitude(-180~180)}`，未打卡时字段值为空串/null |

**推荐的两种处理方式（任选或并存）**：

1. **发布为文章**（与官方 Typecho/WordPress 插件行为一致）：
   - 把 `images` 下载到本地（推荐，图片随博客永久保留）或直接外链；
   - 按 `imageLayout` 生成图片 HTML，按 `imagePosition` 拼在正文开头/结尾；
   - 发布到你配置的分类（分类ID由你在自己系统里决定，无需通知小程序）。
2. **仅存表**（像步数/状态一样入库，前端自行渲染）：

```sql
CREATE TABLE IF NOT EXISTS blog_helper_talk (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    openid VARCHAR(64) NOT NULL,
    title VARCHAR(100) NOT NULL DEFAULT '',
    content TEXT NULL,
    tags VARCHAR(255) NOT NULL DEFAULT '',          -- 逗号分隔
    images TEXT NULL,                                -- JSON 数组字符串
    image_position VARCHAR(10) NOT NULL DEFAULT 'bottom',
    image_layout VARCHAR(10) NOT NULL DEFAULT 'none',
    location_name VARCHAR(100) NOT NULL DEFAULT '',
    location_address VARCHAR(200) NOT NULL DEFAULT '',
    location_latitude DECIMAL(10,6) NULL,
    location_longitude DECIMAL(10,6) NULL,
    created DATETIME NOT NULL,
    PRIMARY KEY (id), KEY idx_created (created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

成功响应：`{"code":0,"msg":"发布成功"}`

**图片排版参考**（与官方插件一致的类名，可直接复用样式）：

```html
<!-- grid9 / grid4：宫格；row：一行；column：一列 -->
<div class="chrison_grid_9">
  <div class="item"><img src="图片地址" /></div>
  ...
</div>
<style>
.chrison_grid_9,.chrison_grid_4,.chrison_row,.chrison_column{display:flex;flex-wrap:wrap;gap:4px;margin:12px 0}
.chrison_grid_9 .item,.chrison_grid_4 .item{width:calc((100% - 8px)/3);padding-top:calc((100% - 8px)/3*0.66);position:relative;overflow:hidden}
.chrison_grid_4 .item{width:calc((100% - 4px)/2);padding-top:calc((100% - 4px)/2*0.66)}
.chrison_grid_9 .item img,.chrison_grid_4 .item img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.chrison_row .item{flex:1 1 100%}.chrison_row .item img{width:100%;display:block}
.chrison_column .item{flex:1 1 30%;min-width:120px}.chrison_column .item img{width:100%;display:block}
</style>
```

---

## 四、心情图标目录（渲染 status 时使用）

图标为 **35 张白色线稿 PNG（96×96）**。获取方式二选一：

- **热链中央服务器**：`https://wechat.chrison.cn/moods/{code}.png`；
- 从 Typecho/WordPress 插件包的 `assets/status/` 目录复制到自己服务器。

**显示注意**：图标是白色线稿，必须放在深色/彩色背景上，例如
`<img src=".../mzz.png" style="background:#4A5568;border-radius:20%">`。

**emojiId → 图标代码 → 名称** 对照表（`code` 即 URL 文件名；`emoji` 推送无图标，直接渲染 `emoji` 字符）：

| emojiId | code | 名称 | | emojiId | code | 名称 |
|---|---|---|---|---|---|---|
| xqxf-mzz | mzz | 美滋滋 | | gzxx-bz | bz | 搬砖 |
| xqxf-lk | lk | 裂开 | | gzxx-cmxx | cmxx | 沉迷学习 |
| xqxf-qjl | qjl | 求锦鲤 | | gzxx-m | m | 忙 |
| xqxf-dqt | dqt | 等天晴 | | gzxx-my | my | 摸鱼 |
| xqxf-pb | pb | 疲惫 | | gzxx-cc | cc | 出差 |
| xqxf-fd | fd | 发呆 | | gzxx-fbhj | fbhj | 飞奔回家 |
| xqxf-c | c | 冲 | | gzxx-wrms | wrms | 勿扰模式 |
| xqxf-emo | emo | emo | | hd-l | l | 浪 |
| xqxf-hslx | hslx | 胡思乱想 | | hd-dk | dk | 打卡 |
| xqxf-yqmm | yqmm | 元气满满 | | hd-yd | yd | 运动 |
| xqxf-bot | bot | bot | | hd-hkf | hkf | 喝咖啡 |
| hd-gf | gf | 干饭 | | hd-hnc | hnc | 喝奶茶 |
| hd-dw | dw | 带娃 | | hd-zjsj | zjsj | 拯救世界 |
| hd-zp | zp | 自拍 | | xx-bg | bg | 闭关 |
| xx-z | z | 宅 | | xx-sj | sj | 睡觉 |
| xx-xm | xm | 吸猫 | | xx-lg | lg | 遛狗 |
| xx-wyx | wyx | 玩游戏 | | xx-tg | tg | 听歌 |
| diy | diy | 自定义 | | | | |

解析规则：`emojiId` 取**最后一个 `-` 之后的部分**即为 code（如 `gzxx-cmxx → cmxx`）。

---

## 五、完整 storys 联调示例

```bash
# 1. 计算签名（secret=mysecret，title=未命名）
SIGN=$(printf 'storysoTest1694000000deadbeefdeadbeef未命名mysecret' | sha256sum | cut -d' ' -f1)

# 2. 发起推送
curl -X POST https://你的域名/你的接收路径 -H 'Content-Type: application/json' -d '{
  "action": "storys",
  "openid": "oTest",
  "timestamp": "1694000000",
  "nonce": "deadbeefdeadbeef",
  "title": "未命名",
  "content": "今天天气不错",
  "tags": ["生活", "记录"],
  "images": ["https://wechat.chrison.cn/uploads/202609/xxxx.png"],
  "imagePosition": "bottom",
  "imageLayout": "grid9",
  "location": {"name": "长江国际雅园", "address": "江苏省无锡市新吴区", "latitude": 31.5, "longitude": 120.3},
  "sign": "'$SIGN'"
}'
```

> 注意：实际转发时 `timestamp` 是当前时间，需在 ±300 秒内有效，上例仅演示字段结构。

---

## 六、安全清单（务必逐条落实）

1. **secret 妥善保管**：不要写进前端/客户端代码；泄露后在你的系统中更换，并同步更新小程序「我的」页。
2. **全程 HTTPS**：拒绝 http 回调地址（中央服务器默认也强制 https）。
3. **先验签再处理**：任何业务处理前完成 `sign + timestamp + nonce + openid格式` 四项校验。
4. **openid 与参数长度**：按第一节规则校验，入库字段做长度截断。
5. **SQL 一律参数化**（预处理语句），禁止字符串拼接。
6. **图片下载安全**（storys 本地化时）：仅接受 http(s) URL、限制超时（建议 ≤12 秒且**并行下载**）、限制大小（≤10MB）、校验 HTTP 状态码与文件魔数（PNG/GIF/WEBP/JPEG）后再保存，禁止把下载内容直接当脚本文件存放。
7. **幂等建议**：可用 `openid + timestamp + nonce` 做去重键，防止极端情况下的重复转发。

## 七、接入检查清单

- [ ] 实现了 POST JSON 接收端点，返回统一 `{code,msg,data}` 信封
- [ ] 用第二节的**自测向量**验证签名算法一致
- [ ] 实现了 `ping`（小程序「测试博客连通」能显示 pong）
- [ ] 按需实现 `steps` / `status` / `storys`
- [ ] 生成了 32 位以上随机 secret，并填入小程序「我的」页（接口地址 + 密令）
- [ ] 小程序选择博客类型「其他」，接口地址填完整 URL，保存后测试连通
