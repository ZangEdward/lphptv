# LPHPTV — 纯 PHP 影视聚合播放站

一个**纯 PHP、零依赖、零配置**的在线影视聚合播放站。把项目丢到任意能跑 PHP 的地方，访问 `install.php` 装好，填几个资源源，就能像主流"聚合搜索"站点一样在线搜索、切换多源、分集播放。

- 参考 **LibreTV** 的聚合 UX 与播放体验；
- 复用 **苹果CMS (maccms10) V10 资源接口** 标准做采集/解析；
- 跑在**纯 PHP 主机**（虚拟主机 / VPS / 自有服务器）上即可；
- 搜索时**实时并发**拉取你配置的资源源、聚合去重、出结果网格；
- 详情页多源 / 分集切换，DPlayer + HLS.js 播放；
- 内置 **PHP HLS/TS 代理**（`proxy.php`）解决跨域并改写 m3u8 分片地址；
- SQLite 存储源配置 / 搜索缓存 / 收藏，免数据库建表。

---

## 一、思路来源（为什么做这个）

最初的需求是在一台 **DirectAdmin 免费共享主机**（`ssh=OFF`、`php=ON`、`git=ON`、`mysql=2`）上搭一个"在线看片 + 网盘"类服务。

- 先调研了 **OpenList**：它是 Go 编译型程序，需要能跑二进制 / 有 SSH 或自定义进程的環境，免费共享主机的 `ssh=OFF` 直接劝退。
- 再调研了 **可道云（KodBox）**：纯 PHP 能跑，但体积大、定位是"网盘"而非"影视聚合"，且远程部署（FTP 强推）体验差，放弃。
- 最终形态转向 **"影视聚合播放站"**：用户手里有采集源（苹果CMS V10 资源接口），希望"在线搜索 → 出结果 → 直接播放"。

这类站点最成熟的参考是 **LibreTV**：纯前端聚合搜索、并发拉取多个来源、来源徽章、HLS 代理。但它依赖一个 Node 服务端（`server.mjs`）。而用户的环境只有 PHP。于是思路收敛为：

> **用 LibreTV 的"聚合 + 搜索 UX + 播放"思路，用苹果CMS 的 PHP 采集/解析标准，落地成一个纯 PHP 后端（SQLite 零配置）跑在共享主机上。**

这就有了 LPHPTV：前端是 LibreTV 那种单页聚合壳，后端是 PHP 实现的 V10 接口客户端 + 并发聚合 + HLS 代理。

## 二、代码参考（站在谁肩膀上）

| 来源 | 地址 | 借鉴了什么 |
| --- | --- | --- |
| **LibreSpark/LibreTV** | https://github.com/LibreSpark/LibreTV | 聚合搜索的产品形态（并发请求、超时控制、来源徽章）、m3u8 提取正则、DPlayer + HLS.js 播放链路；**采集 / 获取视频数据的流程**（搜索与详情 URL、请求头、去重、排序、播放地址解析、m3u8 兜底）已逐条对齐，见 §2.1。HLS 代理仅作思路参考——LPHPTV 用自带增强版（`proxy.php` 带 SSRF 防护），见 §2.1 末尾 |
| **maccmspro/maccms10**（苹果CMS V10） | https://github.com/maccmspro/maccms10 | V10 资源接口标准（`ac=videolist&wd=` 搜索 / `&ids=` 详情）、`vod_play_from` / `vod_play_url` 的多源、分集（`#` 分集、`$` 分隔"集名$地址"）解析格式、XML/JSON 双格式兼容 |
| **ZangEdward/DecoTV** | https://github.com/ZangEdward/DecoTV | **源订阅 `.txt` 格式**：Base58（比特币字母表）编码 JSON，结构 `{ api_site: { key: { name, api, detail?, is_adult? } } }`。LPHPTV 的 `inc/sources_txt.php` 采用**完全一致**的解析逻辑（Base58 解码 → JSON → `api_site`），后台「导入 txt 源」与「内置默认源 jingjian.txt」均复用此格式 |

> 本项目**未直接拷贝**上述仓库代码（它们分别是 Node/JS 与完整 CMS），而是按它们的接口与交互标准用纯 PHP 重写了一遍后端，前端为自研单页。所有视频数据均来自你配置的第三方资源接口。

### 2.1 采集与「获取视频数据」流程（与 LibreTV 完全对齐）

LPHPTV 后端按 `LibreTV/js/api.js` 的 `searchVideos` 与 `getVideoDetail` 逐条用纯 PHP 重写（PHP 用 `curl_multi` 并发等价于 JS 的 `Promise.all`，**传输层不限定 curl**）。逐环节对照：

| 环节 | LibreTV (`js/api.js`) | LPHPTV (PHP) |
| --- | --- | --- |
| 搜索 URL | `api + '?ac=videolist&wd=' + encodeURIComponent(q) + '&pg=N'` | `api + '?ac=videolist&wd=' + rawurlencode(q) + '&pg=N'`（`rawurlencode` ≡ `encodeURIComponent`） |
| 详情 URL | `api + '?ac=videolist&ids=' + id`（无 `&pg`） | 同左 |
| 请求头 | `User-Agent: Chrome/122` + `Accept: application/json` | 同（`http_get` / `multi_get` 默认带） |
| 超时 | 搜索 8000ms / 详情 10000ms | 搜索 8000ms（`curl_multi`）/ 详情 10000ms |
| 并发 | `Promise.all` 并发打多源 | `curl_multi_init` 并发 |
| 失败隔离 | 单源失败返回空，不影响其它源 | 同（单源失败返回空，照常出其它源结果） |
| 去重 | 键 `${source}_${vod_id}`，不同源同一影片分别保留（便于切源） | 键 `源ID_视频ID`，同 |
| 排序 | 按 `vod_name` → `source_name`（`localeCompare`） | 同（`strcmp` 名称 → 来源） |
| 播放地址解析 | `vod_play_url.split('$$$')` → 每源 `split('#')` → `ep.split('$')[1]` 取 URL → 过滤 `http(s)` | 同（`parse_play`：`$$$` 分源 / `#` 分集 / `$` 取索引 1 为地址，过滤 `http(s)`） |
| m3u8 兜底 | `episodes` 为空且 `vod_content` 含裸 `.m3u8` → 正则 `/\$(https?:\/\/[^"'\s]+?\.m3u8)/g` 提取 | 同（`vod_detail` 中若无任何播放地址则扫 `vod_content` 提取） |
| 默认播放 | 首个源的首集 | 同（首源首集） |

> **关于多源**：LibreTV 详情默认只取 `playSources[0]`（第一个播放源）；LPHPTV 解析**全部 `$$$` 源**并支持前端切换，是 LibreTV 该行为的**超集**，默认播放仍是首源首集，体验一致。如需严格只取首源，改 `inc/vodapi.php` 的 `parse_play` 即可。

> **关于代理**：`proxy.php` 是 LPHPTV **自研增强版**，比 LibreTV 的 `server.mjs` 代理多了 **SSRF 防护**（仅放行公网 `http/https`，屏蔽内网）。LibreTV 代理无 SSRF，且其 token 为 `sha256(password)`（前端可推导，较弱），故 LPHPTV 改用「随机 token + 同源 Referer/Origin 校验」。播放链路（DPlayer + HLS.js）则与 LibreTV 一致。

---

## 三、功能特性

- 多源实时聚合搜索（并发请求、超时控制、来源徽章）
- 详情：海报 / 简介 / 主演 / 导演 + 多播放源切换 + 分集列表
- 播放器：HLS(m3u8) 自动识别，普通 mp4 直接播；支持拖拽（Range 透传）
- 收藏夹（本地 SQLite）
- 后台：资源管理（增删改查 / 一键测试）、系统设置、改密、清缓存
- 安全：安装向导设密码、代理 token 鉴权 + 同源校验 + SSRF 防护、敏感目录 `.htaccess` 禁止访问

## 四、目录结构

```
lphptv/
├── index.php          # 入口与路由（页面壳 + JSON API）
├── proxy.php          # HLS/TS 代理（跨域 + m3u8 重写 + 安全校验）
├── admin.php          # 后台管理
├── install.php        # 安装向导
├── inc/
│   ├── bootstrap.php  # 常量/会话/鉴权辅助
│   ├── db.php         # SQLite 数据层
│   ├── util.php       # 网络请求/解析/SSRF/代理 URL
│   └── vodapi.php     # V10 接口客户端（搜索/详情/解析）
├── static/
│   ├── style.css      # 深色 UI
│   └── app.js         # 前端单页逻辑
├── data/              # SQLite 数据库（运行时生成）+ .htaccess 保护
└── .gitignore         # 忽略 data/*.db 等运行时文件
```

## 五、资源源格式（重要）

本项目只认 **苹果CMS V10 资源接口** 格式。一个源只需要填它的「接口基地址」，形如：

```
https://你的源域名/api.php/provide/vod
```

程序会自动拼接：
- 搜索：`{基地址}?ac=videolist&wd=关键词&pg=页码`
- 详情：`{基地址}?ac=videolist&ids=视频ID`

返回 JSON（或旧版 XML）即可被解析。绝大多数中文影视资源站、以及你手里的采集源都是这个格式。
**在你给出的采集源里，挑出这种「接口基地址」填进后台即可。**

### 5.1 一键导入「订阅 txt」（与 DecoTV 同源格式）

后台「**导入 txt 源**」支持直接粘贴或上传 **DecoTV / LunaTV 配置订阅** 的 `.txt`，无需手动拆接口地址。

该 `.txt` 的编码与 `DecoTV` 完全一致（`src/app/api/admin/config_subscription/fetch/route.ts`）：

1. `.txt` 内容是 **Base58（比特币字母表）** 编码的 JSON 串；
2. Base58 解码后得到 JSON：

```json
{
  "api_site": {
    "iqiyizyapi.com": {
      "name": "🎬-爱奇艺-",
      "api": "https://iqiyizyapi.com/api.php/provide/vod",
      "detail": "https://iqiyizyapi.com",
      "is_adult": false
    }
  },
  "custom_category": [],
  "lives": {}
}
```

- `api_site` 为对象，键即「源 key」；每条含 `name` / `api`（必填）/ `detail`（可选）/ `is_adult`（可选）。
- 也兼容**直接粘贴已解码的 JSON 文本**（程序自动探测，Base58 失败则按 JSON 解析）。
- 导入时按「源 key」合并：已存在的同名 key 会**更新**而不会重复；手动添加的源以接口主机名作为 key 兜底去重。

### 5.2 内置默认源（jingjian.txt）

项目自带 `sources/jingjian.txt`（即你的 LunaTV / DecoTV 订阅源，Base58 编码，含 49 个 V10 源）：

- **全新安装**时，`install.php` 会自动把它灌入源表；
- **已有站点首次进入后台**也会自动补入（仅一次、且源表为空时）；
- 任何时候都可在后台点「**恢复内置默认源**」重新灌入 / 更新。
- `sources/` 目录已用 `.htaccess` 禁止 Web 直接访问，仅 PHP 后端可读取。

> 注意：LPHPTV 用 `api` 同时承担「搜索」与「详情」请求（标准 V10 提供接口），`detail` 字段仅作记录保留、不直接用于取详情，以保证各类源的详情链路稳定。

---

## 六、部署方法

> ⚠️ **PHP 运行时说明（务必先读）**
> 本项目是**纯 PHP**，必须有能执行 PHP 的运行时。下面五种"部署"里：
> - ✅ **虚拟主机部署**、**服务器本地部署**：原生支持，把文件传上去就能跑。
> - 🔧 **GitHub Action 部署**：Action 本身不能托管 PHP，它是 **CI/CD 工具**——用来在 `git push` 后自动把代码同步到你的 PHP 主机（VPS / 虚拟主机 FTP）。
> - ☁️ **Cloudflare / EdgeOne 部署**：这两家的**边缘函数（Workers / 边缘函数）是 JS 运行时，不能直接跑 PHP**。可行做法是把 PHP 跑在自有源站，再用 Cloudflare / EdgeOne 做 **CDN + 安全防护前置**（推荐）；若想纯边缘运行，需要把后端移植成 Node/JS（不在本项目范围）。
>
> 简言之：**纯 PHP 的部分始终需要一个 PHP 源站**，Cloudflare/EdgeOne 只是"前置加速与防护"。

### 1. 虚拟主机部署（DirectAdmin / cPanel 等共享主机）—— 最推荐

适合 `php=ON` 的免费/付费共享主机。

1. 把整个项目目录（根目录的 `index.php` / `proxy.php` / `admin.php` / `install.php` / `inc/` / `static/` / `data/` …）上传到网站根目录
   （如 DirectAdmin 的 `domains/你的域名/public_html/`，或建个子目录 `public_html/lphptv/`）。
2. 浏览器访问 `https://你的域名/lphptv/install.php`（若放在根目录则 `https://你的域名/install.php`）
   - 设置站点名称 + 管理员密码（≥6 位）→ 安装完成（自动建 SQLite 库并生成代理 token）。
3. 访问 `https://你的域名/lphptv/admin.php` 登录后台：
   - 「资源管理」→ 添加你的资源源（名称 + 接口基地址），点「测试」确认可用，启用。
4. 回到首页 `index.php`：搜索即实时聚合播放。

> 确保 `data/` 目录**可写**（PHP 要在里面建 `app.db`）。多数主机默认可写；若报"无法建库"，把 `data/` 权限设为 `755` 或 `777`（视主机而定）。

### 2. 服务器本地部署（VPS / 自有服务器，Apache 或 Nginx + PHP）

适合有一台能装 PHP 的服务器（推荐 PHP 8.x）。

**Apache 示例：**

```bash
# Debian/Ubuntu
sudo apt update && sudo apt install -y apache2 php php-curl php-sqlite3
sudo cp -r /path/to/lphptv/* /var/www/html/
sudo chown -R www-data:www-data /var/www/html/data
sudo systemctl restart apache2
```

然后访问 `http://服务器IP/install.php` 完成安装，再到 `admin.php` 配源。

**Nginx + PHP-FPM 示例（关键配置）：**

```nginx
server {
    listen 80;
    server_name tv.example.com;
    root /var/www/lphptv;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    # 禁止访问敏感目录
    location ~ ^/(data|inc)/ { deny all; }
}
```

上传文件 → 设 `data/` 可写（`chown -R www-data`）→ 访问 `install.php` 安装 → 后台配源。

### 3. GitHub Action 部署（推送即自动上线到你的 PHP 主机）

利用 GitHub Actions 在每次 push 到 `main` 后，自动把代码同步到你的 PHP 主机。两种常用通道：

**A. 同步到支持 SSH 的 VPS（rsync over SSH）**

在项目里新建 `.github/workflows/deploy.yml`：

```yaml
name: Deploy to PHP Server
on:
  push:
    branches: [main]
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Deploy via rsync
        uses: burnett01/rsync-deployments@7.0.1
        with:
          switches: -avzr --delete --exclude="data/*.db" --exclude=".git"
          path: ./
          remote_path: /var/www/lphptv/
          remote_host: ${{ secrets.HOST }}
          remote_user: ${{ secrets.USER }}
          remote_key: ${{ secrets.SSH_KEY }}
```

在仓库 **Settings → Secrets** 里添加 `HOST`、`USER`、`SSH_KEY`（你的私钥）。注意：不要同步 `data/*.db`（数据库应留在服务器上，避免覆盖线上配置与缓存）。

**B. 同步到虚拟主机（FTP）**

若你的主机只有 FTP（如 DirectAdmin 免费主机），可用 FTP 动作：

```yaml
name: Deploy via FTP
on:
  push:
    branches: [main]
jobs:
  ftp-deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: FTP Deploy
        uses: SamKirkland/FTP-Deploy-Action@v4.3.5
        with:
          server: ${{ secrets.FTP_SERVER }}
          username: ${{ secrets.FTP_USER }}
          password: ${{ secrets.FTP_PASS }}
          local-dir: ./
          server-dir: /domains/你的域名/public_html/lphptv/
          exclude: |
            **/.git*
            data/*.db
            .gitignore
```

> 提示：FTP 部署同样**不要覆盖服务器上的 `data/app.db`**，否则会清空线上配置。首次部署后再改源，只传代码文件即可。

### 4. Cloudflare 部署（作为 PHP 源站的前置 CDN / 防护）

Cloudflare **不能运行 PHP**，所以正确姿势是：PHP 先跑在上面的"虚拟主机 / VPS"上，再把域名接入 Cloudflare 做加速与防护。

1. 按「1 / 2」先把 PHP 站部署到你的源站（得到一个能访问的源站 IP / 主机）。
2. 在 Cloudflare 添加该域名，把 DNS 记录**橙色云（Proxied）**指向源站。
3. 在 Cloudflare 面板开启：
   - **Speed → Optimization**：自动压缩、Brotli；
   - **Security → WAF / Bot Fight Mode**：基础防护（HLS 代理接口若被刷，可加速率限制 Rate Limiting）；
   - **Caching**：静态资源（`static/` 下的 css/js）可设较长缓存。
4. 源站只需对外暴露 80/443，Cloudflare 负责边缘缓存与隐藏源站 IP。

> 如果你的目标是"纯边缘、无源站"：纯 PHP 无法在 Cloudflare Workers 上运行，需要把后端（搜索聚合 + HLS 代理）用 Node/JS 重写部署为 Worker。那是另一条技术路线，本项目不提供，可参考 LibreTV 原版 Node 服务端。

### 5. EdgeOne 部署（腾讯云边缘安全加速，同样作前置）

EdgeOne 与 Cloudflare 定位类似：**边缘函数不支持 PHP**。用法一致——PHP 跑在源站，EdgeOne 做加速 + 安全前置。

1. 先把 PHP 站部署到源站（虚拟主机 / 腾讯云 CVM 等）。
2. 在 **EdgeOne 控制台**添加站点并接入域名，将域名解析指向 EdgeOne 分配的 CNAME（开启代理）。
3. 在 EdgeOne 配置：
   - **站点加速**：智能路由、协议优化、Brotli 压缩；
   - **安全防护**：托管规则 / Bot 管理 / 速率限制（保护 `proxy.php` 不被滥用）；
   - **缓存规则**：对 `static/*.css`、`static/*.js` 设较长缓存，对 `*.php` 设不缓存（动态）。
4. 源站仅暴露必要端口，EdgeOne 隐藏源站并实现就近加速。

> 同样，若想用 **EdgeOne Pages**（静态托管）部署：Pages 也不支持 PHP，只能托管前端静态资源；后端 PHP 仍需独立源站。直接用 EdgeOne 的"站点加速"接入你的 PHP 源站即可。

---

## 七、主机环境要求

- PHP ≥ 7.0（推荐 8.x；代码已避开 7.4+ 才有的箭头函数等写法以提高兼容）
- 扩展：**curl**（必选，拉取资源 / 代理）、**pdo_sqlite**（必选，存储）
- `openssl` 建议开启（HTTPS 资源拉取；代码已设 `SSL_VERIFYPEER=false` 兜底）

如果访问空白页，请开启 PHP 错误显示排查；多数情况是缺 `pdo_sqlite` 或 `curl` 扩展。

## 八、安全提示

- **改密码**：安装后若曾把密码写在别处，请到后台「修改密码」。
- `data/` 与 `inc/` 已用 `.htaccess`（Apache）禁止 Web 访问；Nginx 用户请用上面第 2 节的 `location ~ ^/(data|inc)/ { deny all; }`。
- 代理 `proxy.php` 要求同源 Referer/Origin，且只放行公网 http/https（屏蔽内网，防 SSRF）。
  代理 token 对访客可见（前端播放需要），同源校验是主要防线；**不建议把站点完全公开**给陌生人用。
- 本项目仅供个人学习，所有视频均来自第三方接口；请遵守当地法律法规，勿作公开商业用途。

## 九、常见调整

- 搜索太慢 / 源太多：后台调大「单源超时」或只启用少数源。
- 想预缓存热门内容：可用主机 cron 定时请求 `index.php?r=api_search&q=关键词` 预热（可选）。
- 播放卡顿：多为源站带宽问题；可换源或在后台调大代理超时（`proxy.php` 内 `CURLOPT_TIMEOUT`）。
- 数据库位置：`data/app.db`（SQLite），已加入 `.gitignore`，不会误提交到仓库。
