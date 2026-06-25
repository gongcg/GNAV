# 龍导航

一个简洁的个人导航网站，支持 Bing 每日壁纸、分类管理、图标裁剪上传、隐藏分类密码解锁，响应式设计适配手机和电脑。

## 项目结构

```
导航/
├── index.php          # 前台首页
├── admin.php          # 后台管理
├── links.json         # 数据配置
├── style.css          # 样式文件
├── img/
│   ├── logo.png       # 网站图标
│   ├── bing.svg       # 搜索引擎图标
│   └── favicons/      # 链接图标（27个）
└── README.md          # 本文档
```

## 环境要求

- PHP 7.0+
- PHP GD 扩展（图标裁剪上传需要）
- `links.json` 和 `img/favicons/` 目录可写

## 快速配置

### 1. 修改密码

两个文件需保持一致：

```php
// admin.php（管理登录密码）
$admin_password = '你的密码';

// index.php（隐藏分类解锁密码）
$UNLOCK_PASSWORD = '你的密码';
```

### 2. 修改网站标题

```php
// index.php
<title>龍导航</title>
```

### 3. 修改页脚

```php
// index.php 第 129 行
<div><span>龍导航</span></div>
```

## 数据格式 (links.json)

```json
{
    "cols": 8,
    "fav_cols": 8,
    "all_cols": 8,
    "categories": [
        {
            "name": "分类名",
            "color": "#ffffff",
            "hidden": false,
            "cols": 6,
            "links": [
                {
                    "name": "链接名",
                    "url": "https://example.com",
                    "icon": "icon.png",
                    "color": "#ffffff",
                    "favorite": true
                }
            ]
        }
    ]
}
```

| 字段 | 说明 |
|------|------|
| `cols` | 默认每行列数（1-10） |
| `fav_cols` | 热门页列数 |
| `all_cols` | 全部页列数 |
| `hidden` | 分类是否隐藏 |
| `favorite` | 链接是否显示在热门 |

## 后台管理

访问 `admin.php` 输入密码登录。

| 功能 | 操作 |
|------|------|
| 添加分类 | 输入名称 → 点击添加 |
| 编辑分类 | 修改字段 → 点击保存 |
| 删除分类 | 点击删除 → 确认 |
| 添加链接 | 选分类 → 填名称和URL → 添加 |
| 编辑链接 | 修改字段 → 点击保存 |
| 删除链接 | 点击删除 → 确认 |
| 排序 | 拖动 `☰` 或 `➤` 图标 |
| 上传图标 | 点击上传 → 裁剪 → 确认 |

## 前台功能

| 功能 | 操作 |
|------|------|
| 切换分类 | 点击顶部标签 |
| 翻页 | 鼠标滚轮/触摸滑动 |
| 搜索 | 输入关键词回车 |
| 解锁隐藏分类 | 点击页脚3次 → 输入密码 |

## API

```
GET index.php?format=json
```

返回 `links.json` 的 JSON 数据。

## 图标格式

- **图片文件**：icon 填文件名（如 `logo.png`），放 `img/favicons/`
- **文字图标**：填 1-2 个汉字或字母（如 `工具`）
- **默认图标**：留空或填其他

## 常见问题

| 问题 | 解决 |
|------|------|
| 图标上传失败 | 检查 GD 扩展和目录权限 |
| 配置保存失败 | 检查 `links.json` 写入权限 |
| 手机显示异常 | 清除缓存，检查 viewport |
