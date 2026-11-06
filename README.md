# WCU File Upload

> WooCommerce 产品定制上传插件：让客户在产品页上传设计稿，文件跟随购物车、订单、邮件与后台。

**版本 1.2.1** · WordPress 6.2+ · WooCommerce 7.8+ · PHP 7.4+ · GPL-2.0-or-later

---

## 它做什么

客户在商品页上传设计稿或参考图（拖拽 / 点选 / 多文件），文件绑定到购物车项，随订单落库，店长在后台订单页可以直接下载，客户在订单邮件里也能拿到带签名的下载链接。

## 功能

- 拖拽 / 点击上传，支持多文件，Ajax 提交不刷新页面
- 扩展名白名单 + 文件头魔数校验，改后缀传 PHP 会被拒
- 图片像素上限（防图片炸弹），危险扩展名硬性屏蔽
- 按商品分类启用，支持父分类覆盖子分类；产品级三态覆盖（继承 / 强制开 / 强制关）
- 文件大小与数量双端限制（前端 + 服务端）
- 上传记录独立存储，token 只存 SHA-256 哈希，绑定会话
- 每会话 + IP 限流
- 下载三条鉴权路径：管理员 nonce / 订单归属 / 订单号 + order_key + HMAC + 过期时间
- 订单邮件正文自动列出文件名、大小与下载链接（HTML 与纯文本）
- 可选：把文件作为附件塞进「新订单」邮件（20MB 总量上限，默认关闭）
- 每日 cron 清理未成交流程的临时文件
- 兼容 HPOS、Cart/Checkout Blocks
- 提供 `[wcu_upload]` shortcode（注意：必须放在 Add to Cart 表单内部，否则文件 token 不会随表单提交）

## 目录结构

```
.
├── wcu-file-upload.php     # 插件主文件（单文件，无第三方依赖）
├── assets/
│   ├── upload.js           # 前端上传逻辑
│   ├── upload.css          # 前端样式
│   └── admin.css           # 后台样式
├── readme.txt              # WordPress 插件目录格式说明
├── 使用说明.md              # 中文使用文档
├── LICENSE
└── README.md
```

## 安装

**方式一：从 Release 下载 zip**

在 Releases 页面下载 `wcu-file-upload.zip`，后台「插件 → 安装插件 → 上传插件」上传启用。

**方式二：自己打包**

```bash
git clone https://github.com/liujiajiayu/wcu-file-upload.git
cd wcu-file-upload
zip -r ../wcu-file-upload.zip . -x ".git/*" -x "*.zip" -x "README.md" -x "LICENSE"
```

注意压缩包里必须只有一层 `wcu-file-upload/` 目录，否则 WordPress 会报
`Plugin file does not exist.`

## 已知限制

- 不支持 HEIC（iPhone 原图需先转 JPG）
- 不做上传加价、云存储、ZIP 批量打包、在线预览裁剪
- 订单成交后的文件不会自动删除，需自行制定保留策略
- 邮件附件受邮件服务商单封上限约束（Base64 膨胀约 33%）

## 变更记录

### 1.2.1

- 修复：上传后的文件行在部分主题下被压成一字宽，文件名消失、大小逐字换行
- 仅调整 `assets/upload.css` 的列表排版规则；PHP 逻辑、设置、存储、数据格式均未改动

### 1.2.0

- 上传记录改为服务端记录 + 会话绑定的不透明 token
- 增加带过期的订单签名下载链接、订单归属校验
- 增加上传限流、图片像素上限、IIS/Apache 目录防护
- 增加产品级三态覆盖与 Elementor shortcode 防重复渲染

## License

GPL-2.0-or-later，见 [LICENSE](LICENSE)。
