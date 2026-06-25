<?php
session_start();
$config_file = 'links.json';

// 管理密码（直接修改这里即可）
$admin_password = 'YOUR_PASSWORD_HERE';  // ← 修改为你的密码

$linksConfig = json_decode(@file_get_contents($config_file), true) ?: ['categories' => []];

$is_logged = isset($_SESSION['admin']) && $_SESSION['admin'] === true;

// 处理登录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === $admin_password) {
        $_SESSION['admin'] = true;
        header('Location: admin.php');
        exit;
    } else {
        $login_error = '密码错误';
    }
}

// 处理登出
if (isset($_GET['logout'])) {
    unset($_SESSION['admin']);
    header('Location: admin.php');
    exit;
}

// 未登录显示登录页面
if (!$is_logged): ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>导航管理 - 登录</title>
  <link href="style.css?v=<?php echo filemtime('style.css'); ?>" rel="stylesheet">
</head>
<body class="admin-login-body">
  <div class="login-wrapper">
    <div class="login-card">
      <div class="login-icon">&#x1F6E1;</div>
      <h2>导航管理</h2>
      <p class="login-sub">请输入管理密码</p>
      <?php if (isset($login_error)): ?>
        <p class="login-error"><?php echo $login_error; ?></p>
      <?php endif; ?>
      <form method="POST">
        <input class="login-input" type="password" name="password" placeholder="密码" required autofocus>
        <button class="login-btn" type="submit">登 录</button>
      </form>
    </div>
  </div>
</body>
</html>
<?php exit; endif;

// 保存配置（自动备份）
function saveConfig($config) {
    global $config_file;
    // 写入临时文件后原子替换，避免写入中断导致数据损坏
    $tmpFile = $config_file . '.tmp';
    $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    file_put_contents($tmpFile, $json);
    rename($tmpFile, $config_file);
}

// 图标文件夹
define('ICON_DIR', 'img/favicons/');

// 确保图标文件夹存在
function ensureIconDir() {
    if (!is_dir(ICON_DIR)) @mkdir(ICON_DIR, 0755, true);
}

// 裁剪图标上传（接收已裁剪的 PNG blob 或原始图片+裁剪参数）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['png_icon'])) {
    ensureIconDir();
    $file = $_FILES['png_icon'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // 新流程：客户端已裁剪好正方形，服务器只需缩放到64x64
    if (isset($_POST['is_cropped']) && $_POST['is_cropped'] === '1') {
        $allowed = ['png', 'jpg', 'jpeg'];
        if (!in_array($ext, $allowed) || $file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'error' => '文件格式不支持']);
            exit;
        }
        if ($ext === 'png') {
            $src = @imagecreatefrompng($file['tmp_name']);
        } else {
            $src = @imagecreatefromjpeg($file['tmp_name']);
        }
        if (!$src) {
            echo json_encode(['ok' => false, 'error' => '图片解析失败']);
            exit;
        }
        // 创建 64x64 输出
        $dst = imagecreatetruecolor(64, 64);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);
        
        $srcW = imagesx($src);
        $srcH = imagesy($src);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, 64, 64, $srcW, $srcH);
        
        $rawName = trim($_POST['icon_name'] ?? 'icon');
        // 只保留字母数字、下划线、连字符
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $rawName);
        if ($safeName === '') $safeName = 'icon';
        $filename = $safeName . '.png';
        imagepng($dst, ICON_DIR . $filename);

        echo json_encode(['ok' => true, 'filename' => $filename]);
        exit;
    }
    
    // 旧流程兼容：直接保存PNG
    if ($ext === 'png' && $file['error'] === UPLOAD_ERR_OK) {
        $dest = ICON_DIR . basename($file['name']);
        move_uploaded_file($file['tmp_name'], $dest);
        echo json_encode(['ok' => true, 'filename' => basename($file['name'])]);
    } else {
        echo json_encode(['ok' => false, 'error' => '仅支持 PNG 文件']);
    }
    exit;
}

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // 保存页面设置
    if ($action === 'save_settings') {
        copy($config_file, $config_file . '.bak');
        $linksConfig['fav_cols'] = max(1, min(10, intval($_POST['fav_cols'] ?? 8)));
        $linksConfig['all_cols'] = max(1, min(10, intval($_POST['all_cols'] ?? 8)));
        saveConfig($linksConfig);
    }

    // 保存排序（只重排顺序，不修改任何属性）
    if ($action === 'save_order') {
        copy($config_file, $config_file . '.bak');
        $order = json_decode($_POST['order'], true);
        $newCategories = [];
        $origLinksByUrl = [];
        foreach ($linksConfig['categories'] as $cat) {
            foreach ($cat['links'] as $link) {
                $origLinksByUrl[$link['url']] = $link;
            }
        }
        $origCatsByName = [];
        foreach ($linksConfig['categories'] as $cat) {
            $origCatsByName[$cat['name']] = $cat;
        }
        foreach ($order as $item) {
            $catName = $item['cat'];
            $origCat = $origCatsByName[$catName] ?? null;
            if (!$origCat) continue;
            $newLinks = [];
            foreach ($item['links'] as $url) {
                if (isset($origLinksByUrl[$url])) {
                    $newLinks[] = $origLinksByUrl[$url];
                }
            }
            $origCat['links'] = $newLinks;
            $newCategories[] = $origCat;
        }
        saveConfig(['categories' => $newCategories]);
        exit;
    }

    if ($action === 'add_category') {
        copy($config_file, $config_file . '.bak');
        $cols = intval($_POST['cols'] ?? 0);
        $linksConfig['categories'][] = [
            'name' => trim($_POST['name']),
            'color' => trim($_POST['color'] ?? ''),
            'hidden' => !empty($_POST['hidden']),
            'cols' => ($cols >= 1 && $cols <= 10) ? $cols : null,
            'links' => []
        ];
        saveConfig($linksConfig);
    }
    
    if ($action === 'edit_category') {
        copy($config_file, $config_file . '.bak');
        $catIndex = intval($_POST['cat_index']);
        if (isset($linksConfig['categories'][$catIndex])) {
            $linksConfig['categories'][$catIndex]['name'] = trim($_POST['name'] ?? $linksConfig['categories'][$catIndex]['name']);
            $linksConfig['categories'][$catIndex]['color'] = trim($_POST['color'] ?? '');
            $linksConfig['categories'][$catIndex]['hidden'] = !empty($_POST['hidden']);
            $cols = intval($_POST['cols'] ?? 0);
            $linksConfig['categories'][$catIndex]['cols'] = ($cols >= 1 && $cols <= 10) ? $cols : null;
            saveConfig($linksConfig);
        }
    }
    
    if ($action === 'add_link') {
        copy($config_file, $config_file . '.bak');
        $catIndex = intval($_POST['category']);
        if (isset($linksConfig['categories'][$catIndex])) {
            $linksConfig['categories'][$catIndex]['links'][] = [
                'name' => trim($_POST['name']),
                'url' => trim($_POST['url']),
                'icon' => trim($_POST['icon'] ?? ''),
                'color' => trim($_POST['color'] ?? ''),
                'favorite' => !empty($_POST['favorite'])
            ];
            saveConfig($linksConfig);
        }
    }
    
    if ($action === 'edit_link') {
        copy($config_file, $config_file . '.bak');
        $catIndex = intval($_POST['cat_index']);
        $linkIndex = intval($_POST['link_index']);
        if (isset($linksConfig['categories'][$catIndex]['links'][$linkIndex])) {
            $linksConfig['categories'][$catIndex]['links'][$linkIndex] = [
                'name' => trim($_POST['name']),
                'url' => trim($_POST['url']),
                'icon' => trim($_POST['icon'] ?? ''),
                'color' => trim($_POST['color'] ?? ''),
                'favorite' => !empty($_POST['favorite'])
            ];
            saveConfig($linksConfig);
        }
    }

    // 删除操作（AJAX 调用，返回 JSON）
    if ($action === 'delete_link') {
        $catIndex = intval($_POST['cat_index'] ?? 0);
        $linkIndex = intval($_POST['link_index'] ?? 0);
        if (isset($linksConfig['categories'][$catIndex]['links'][$linkIndex])) {
            copy($config_file, $config_file . '.bak');
            array_splice($linksConfig['categories'][$catIndex]['links'], $linkIndex, 1);
            saveConfig($linksConfig);
            echo json_encode(['ok' => true]);
            exit;
        }
        echo json_encode(['ok' => false]);
        exit;
    }

    if ($action === 'delete_category') {
        $catIndex = intval($_POST['cat_index'] ?? 0);
        if (isset($linksConfig['categories'][$catIndex])) {
            copy($config_file, $config_file . '.bak');
            array_splice($linksConfig['categories'], $catIndex, 1);
            saveConfig($linksConfig);
            echo json_encode(['ok' => true]);
            exit;
        }
        echo json_encode(['ok' => false]);
        exit;
    }
    
    // 添加/编辑/设置类操作：处理完后跳转回管理页
    header('Location: admin.php');
    exit;
}

// CSRF Token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>导航管理</title>
  <link href="style.css?v=<?php echo filemtime('style.css'); ?>" rel="stylesheet">
</head>
<body class="admin-body">
  <div class="admin-container">
    <div class="admin-header">
      <a href="index.php" target="_blank" class="header-btn">首页</a>
      <span class="admin-title">导航管理</span>
      <a href="?logout" class="header-btn">退出</a>
    </div>
  
  <div class="panel">
    <h2>页面设置</h2>
    <form method="POST">
      <input type="hidden" name="action" value="save_settings">
      <div class="form-row">
        <span class="form-label">热门每行列数</span>
        <input type="number" name="fav_cols" value="<?php echo $linksConfig['fav_cols'] ?? 8; ?>" min="1" max="10" style="width:60px;text-align:center;">
        <span class="form-label">全部每行列数</span>
        <input type="number" name="all_cols" value="<?php echo $linksConfig['all_cols'] ?? 8; ?>" min="1" max="10" style="width:60px;text-align:center;">
        <button type="submit" class="btn btn-primary btn-sm">保存</button>
      </div>
    </form>
  </div>

  <div class="panel">
    <h2>添加分类</h2>
    <form method="POST">
      <input type="hidden" name="action" value="add_category">
      <div class="form-row">
        <input type="text" name="name" placeholder="分类名称" required>
        <button type="submit" class="btn btn-primary">添加</button>
      </div>
    </form>
  </div>
  
  <div class="panel">
    <h2>添加链接</h2>
    <form method="POST">
      <input type="hidden" name="action" value="add_link">
      <div class="form-row">
        <select name="category" required>
          <?php foreach($linksConfig['categories'] as $i => $cat): ?>
            <option value="<?php echo $i; ?>"><?php echo $cat['name']; ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="name" placeholder="链接名称" required>
        <input type="url" name="url" placeholder="https://..." required>
      </div>
      <div class="form-row" style="margin-top:8px;">
        <input type="text" name="icon" placeholder="1-2个汉字或字母 | 上传PNG/SVG">
        <button type="button" class="btn btn-primary btn-sm" onclick="openCropModal(this)" title="上传裁剪图标">上传</button>
        <button type="submit" class="btn btn-success">添加</button>
      </div>
    </form>
  </div>
  
  <!-- ===== 裁切图标弹窗 ===== -->
  <div class="crop-overlay" id="crop-overlay">
    <div class="crop-modal">
      <div class="crop-header">
        <span>裁切图标</span>
        <button class="crop-close" id="crop-close">&times;</button>
      </div>
      <div class="crop-body">
        <div class="crop-stage-wrap">
          <div class="crop-stage" id="crop-stage">
            <img id="crop-img" src="" draggable="false">
            <div class="crop-grid-line crop-grid-h"></div>
            <div class="crop-grid-line crop-grid-v"></div>
            <div class="crop-border"></div>
          </div>
        </div>
        <div class="crop-right">
          <div class="crop-preview-box">
            <span class="crop-preview-label">预览</span>
            <div class="crop-preview-wrap">
              <canvas id="crop-preview" width="64" height="64"></canvas>
            </div>
          </div>
          <div class="crop-controls">
            <label class="crop-ctrl-label">缩放</label>
            <div class="crop-zoom-row">
              <button type="button" id="crop-zoom-out" title="缩小">&minus;</button>
              <input type="range" id="crop-zoom" min="30" max="300" value="100">
              <button type="button" id="crop-zoom-in" title="放大">+</button>
            </div>
            <span class="crop-hint">拖拽移动 &bull; 滚轮缩放</span>
          </div>
          <div class="crop-name-row">
            <label class="crop-ctrl-label">文件名</label>
            <input type="text" id="crop-filename" class="crop-filename-input" value="icon">
            <span class="crop-filename-ext">.png</span>
            <span class="crop-name-hint">同名文件将被覆盖</span>
          </div>
          <div class="crop-actions">
            <button type="button" class="btn btn-cancel" id="crop-cancel">取消</button>
            <button type="button" class="btn btn-primary" id="crop-confirm">确认裁切</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="panel">
    <h2>管理分类和链接 <small style="font-weight:normal;color:#999;">（拖动后自动保存）</small></h2>
    <div id="category-list">
    <?php foreach($linksConfig['categories'] as $catIndex => $cat): ?>
      <div class="category" data-cat-index="<?php echo $catIndex; ?>">
        <form method="POST" class="category-edit-form">
          <input type="hidden" name="action" value="edit_category">
          <input type="hidden" name="cat_index" value="<?php echo $catIndex; ?>">
          <div class="cat-inline-row">
            <span class="drag-handle">☰</span>
            <label class="checkbox-label"><input type="checkbox" name="hidden" value="1" <?php if(!empty($cat['hidden'])) echo 'checked'; ?>> 显隐</label>
            <span class="field-label">每行列数</span>
            <input type="text" name="cols" value="<?php echo ($cat['cols'] ?? 8); ?>" placeholder="列数" title="每行列数(1-10)">
            <span class="field-label">分类名称</span>
            <input type="text" name="name" value="<?php echo htmlspecialchars($cat['name']); ?>" required>
            <input type="color" name="color" value="<?php echo ($cat['color'] ?? '') ?: '#ffffff'; ?>" title="吸取颜色">
            <button type="submit" class="btn btn-primary btn-sm">保存</button>
            <button type="button" class="btn btn-danger btn-sm delete-cat-btn" data-cat-index="<?php echo $catIndex; ?>">删除</button>
          </div>
        </form>
        <div class="links-container" data-cat-index="<?php echo $catIndex; ?>">
        <?php foreach($cat['links'] as $linkIndex => $link): ?>
          <div class="link" data-link-index="<?php echo $linkIndex; ?>">
            <form method="POST" class="link-edit-form">
              <input type="hidden" name="action" value="edit_link">
              <input type="hidden" name="cat_index" value="<?php echo $catIndex; ?>">
              <input type="hidden" name="link_index" value="<?php echo $linkIndex; ?>">
              <div class="link-inline-row">
                <span class="drag-handle link-drag">➤</span>
                <label class="checkbox-label"><input type="checkbox" name="favorite" value="1" <?php if(!empty($link['favorite'])) echo 'checked'; ?>> 热门</label>
                <input type="text" name="name" value="<?php echo htmlspecialchars($link['name']); ?>" required>
                <input type="url" name="url" value="<?php echo htmlspecialchars($link['url']); ?>" required>
                <input type="text" name="icon" value="<?php echo htmlspecialchars($link['icon']); ?>" placeholder="汉字字母文件名">
                <button type="button" class="btn btn-primary btn-sm" onclick="openCropModal(this)" title="上传裁剪图标">上传</button>
                <input type="color" name="color" value="<?php echo ($link['color'] ?? '') ?: '#ffffff'; ?>" title="链接底色">
                <button type="submit" class="btn btn-success btn-sm">保存</button>
                <a href="?action=delete_link&cat_index=<?php echo $catIndex; ?>&link_index=<?php echo $linkIndex; ?>" class="btn btn-danger btn-sm delete-link-btn" data-cat-index="<?php echo $catIndex; ?>" data-link-index="<?php echo $linkIndex; ?>">删除</a>
              </div>
            </form>
          </div>
        <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
  
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
  <script>
    var csrfToken = <?php echo json_encode($csrf_token); ?>;

    // 恢复上次保存时的滚动位置
    (function() {
      var y = sessionStorage.getItem('adminScrollY');
      if (y) { window.scrollTo(0, parseInt(y)); sessionStorage.removeItem('adminScrollY'); }
    })();

    // ===== AJAX 通用辅助函数 =====
    function ajaxPost(data, callback) {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', 'admin.php');
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.onload = function() { if (callback) callback(xhr.responseText); };
      var parts = [];
      for (var key in data) {
        parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
      }
      xhr.send(parts.join('&'));
    }

    function showToast(msg) {
      var t = document.createElement('div');
      t.textContent = msg;
      t.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);background:#333;color:#fff;padding:10px 24px;border-radius:6px;z-index:9999;font-size:14px;transition:opacity 0.4s';
      document.body.appendChild(t);
      setTimeout(function() { t.style.opacity = '0'; }, 1200);
      setTimeout(function() { t.remove(); }, 1700);
    }

    // ===== 请求锁，防止重复提交 =====
    var _ajaxLocked = false;
    var origAjaxPost = ajaxPost;
    ajaxPost = function(data, callback) {
      if (_ajaxLocked) return;
      _ajaxLocked = true;
      origAjaxPost(data, function(res) {
        _ajaxLocked = false;
        if (callback) callback(res);
      });
    };

    // ===== 表单 AJAX 提交（保存后记忆滚动位置）=====
    document.querySelectorAll('form').forEach(function(form) {
      form.addEventListener('submit', function(e) {
        e.preventDefault();
        if (_ajaxLocked) return;
        sessionStorage.setItem('adminScrollY', window.scrollY);
        var formData = new FormData(form);
        var data = {};
        formData.forEach(function(v, k) { data[k] = v; });
        ajaxPost(data, function() {
          showToast('保存成功');
          location.reload();
        });
      });
    });

    // ===== 删除操作 POST（替代 <a> GET 跳转）=====
    document.querySelectorAll('a.btn-danger.delete-link-btn').forEach(function(link) {
      link.addEventListener('click', function(e) {
        e.preventDefault();
        if (!confirm('确认删除此链接？')) return;
        var catIdx = link.getAttribute('data-cat-index');
        var linkIdx = link.getAttribute('data-link-index');
        var data = {
          action: 'delete_link',
          cat_index: catIdx,
          link_index: linkIdx,
          csrf_token: csrfToken
        };
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'admin.php');
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
          showToast('删除成功');
          var row = link.closest('.link-inline-row');
          if (row) {
            var parent = row.closest('.link');
            if (parent) parent.remove();
          }
        };
        var parts = [];
        for (var key in data) {
          parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
        }
        xhr.send(parts.join('&'));
      });
    });

    document.querySelectorAll('button.delete-cat-btn').forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        if (!confirm('确认删除此分类及其所有链接？')) return;
        var catIdx = btn.getAttribute('data-cat-index');
        var data = {
          action: 'delete_category',
          cat_index: catIdx,
          csrf_token: csrfToken
        };
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'admin.php');
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
          showToast('删除成功');
          var category = btn.closest('.category');
          if (category) category.remove();
        };
        var parts = [];
        for (var key in data) {
          parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
        }
        xhr.send(parts.join('&'));
      });
    });

    // ===== 拖动排序 =====
    function saveOrder() {
      var orderData = [];
      document.querySelectorAll('#category-list > .category').forEach(function(catEl) {
        var catName = catEl.querySelector('.category-edit-form input[name="name"]').value;
        var links = [];
        catEl.querySelectorAll('.links-container .link').forEach(function(linkEl) {
          links.push(linkEl.querySelector('.link-edit-form input[name="url"]').value);
        });
        orderData.push({ cat: catName, links: links });
      });
      ajaxPost({ action: 'save_order', order: JSON.stringify(orderData) }, function() {
        showToast('排序已保存');
      });
    }

    // 初始化拖动排序
    (function() {
      var el = document.getElementById('category-list');
      new Sortable(el, {
        animation: 150,
        handle: '.drag-handle',
        ghostClass: 'sortable-ghost',
        onEnd: function() { saveOrder(); }
      });
      
      var linkContainers = document.querySelectorAll('.links-container');
      linkContainers.forEach(function(container) {
        new Sortable(container, {
          group: 'shared-links',
          animation: 150,
          handle: '.link-drag',
          ghostClass: 'sortable-ghost',
          onEnd: function() { saveOrder(); }
        });
      });
    })();

    // ===== 复选框自动保存：显隐 / 热门 =====
    document.querySelectorAll('.category-edit-form input[name="hidden"]').forEach(function(cb) {
      cb.addEventListener('change', function() {
        var form = cb.closest('form');
        var data = { action: 'edit_category' };
        new FormData(form).forEach(function(v, k) { data[k] = v; });
        data.hidden = cb.checked ? '1' : '0';
        ajaxPost(data, function() { showToast('已保存'); });
      });
    });

    document.querySelectorAll('.link-edit-form input[name="favorite"]').forEach(function(cb) {
      cb.addEventListener('change', function() {
        var form = cb.closest('form');
        var data = { action: 'edit_link' };
        new FormData(form).forEach(function(v, k) { data[k] = v; });
        data.favorite = cb.checked ? '1' : '0';
        ajaxPost(data, function() { showToast('已保存'); });
      });
    });

    // ===== 分类折叠/展开 =====
    document.querySelectorAll('#category-list .category .drag-handle').forEach(function(handle) {
      handle.style.cursor = 'pointer';
      handle.title = '点击折叠/展开';
      handle.addEventListener('click', function(e) {
        e.stopPropagation();
        var category = handle.closest('.category');
        category.classList.toggle('collapsed');
      });
    });

    // ===== 图标裁剪上传 =====
    var cropOverlay = document.getElementById('crop-overlay');
    var cropImg = document.getElementById('crop-img');
    var cropStage = document.getElementById('crop-stage');
    var cropZoom = document.getElementById('crop-zoom');
    var cropPreview = document.getElementById('crop-preview');
    var cropPrevCtx = cropPreview.getContext('2d');
    
    var cropTargetInput = null;    // 裁剪完成后填充到哪个 input
    var cropSourceImage = null;    // 原始 Image 对象
    var cropNaturalW = 0;
    var cropNaturalH = 0;
    
    // 变换参数（相对于 stage 中心）
    var cropScale = 1;    // 显示 300px 边长所需的初始缩放
    var cropTx = 0;       // translateX（相对于居中位置）
    var cropTy = 0;
    
    var cropDragging = false;
    var cropDragStart = { x: 0, y: 0, tx: 0, ty: 0 };

    function openCropModal(btn) {
      // 创建隐藏的 file input
      var fileInput = document.createElement('input');
      fileInput.type = 'file';
      fileInput.accept = 'image/png,image/jpeg,image/webp,image/gif,image/svg+xml';
      fileInput.onchange = function() {
        var file = fileInput.files[0];
        if (!file) return;
        // 用上传文件的名字作为默认文件名
        var uploadedName = file.name.replace(/\.(png|jpg|jpeg|gif|webp|svg)$/i, '');
        if (cropTargetInput && cropTargetInput.value) {
          // 已有图标名则沿用，方便覆写
          uploadedName = cropTargetInput.value.replace(/\.(png|jpg|jpeg|gif|webp)$/i, '');
        }
        document.getElementById('crop-filename').value = uploadedName || 'icon';
        var reader = new FileReader();
        reader.onload = function(e) {
          var img = new Image();
          img.onload = function() {
            cropSourceImage = img;
            cropImg.src = e.target.result;
            cropNaturalW = img.naturalWidth;
            cropNaturalH = img.naturalHeight;
            // 初始缩放：让短边填满 300px
            cropScale = 300 / Math.min(cropNaturalW, cropNaturalH);
            cropTx = 0;
            cropTy = 0;
            updateCropView();
            cropOverlay.style.display = 'flex';
            document.body.style.overflow = 'hidden';
          };
          img.src = e.target.result;
        };
        reader.readAsDataURL(file);
      };
      // 找到对应的 icon input
      var row = btn.closest('.form-row') || btn.closest('.link-inline-row') || btn.closest('form');
      cropTargetInput = row ? row.querySelector('input[name="icon"]') : null;
      fileInput.click();
    }

    function updateCropView() {
      if (!cropSourceImage) return;
      var imgW = cropNaturalW * cropScale;
      var imgH = cropNaturalH * cropScale;
      // 图片中心对齐 stage 中心(150,150)，cropTx/cropTy 用于拖拽偏移
      var cx = 150 - imgW / 2 + cropTx;
      var cy = 150 - imgH / 2 + cropTy;
      cropImg.style.width = imgW + 'px';
      cropImg.style.height = imgH + 'px';
      cropImg.style.transform = 'translate(' + cx + 'px,' + cy + 'px)';
      cropImg.style.transformOrigin = '0 0';
      cropZoom.value = Math.round(cropScale / (300 / Math.min(cropNaturalW, cropNaturalH)) * 100);
      updatePreview();
    }

    function updatePreview() {
      var pw = cropPreview.width;
      var ph = cropPreview.height;
      cropPrevCtx.clearRect(0, 0, pw, ph);
      if (!cropSourceImage) return;
      // 计算 stage 中 300x300 区域对应的原图像素区域
      var srcW = 300 / cropScale;
      var srcH = 300 / cropScale;
      var srcX = (cropNaturalW / 2) - cropTx / cropScale - srcW / 2;
      var srcY = (cropNaturalH / 2) - cropTy / cropScale - srcH / 2;
      cropPrevCtx.drawImage(cropSourceImage, srcX, srcY, srcW, srcH, 0, 0, pw, ph);
    }

    // 拖拽
    cropStage.addEventListener('mousedown', function(e) {
      cropDragging = true;
      cropDragStart.x = e.clientX;
      cropDragStart.y = e.clientY;
      cropDragStart.tx = cropTx;
      cropDragStart.ty = cropTy;
      e.preventDefault();
    });

    window.addEventListener('mousemove', function(e) {
      if (!cropDragging) return;
      cropTx = cropDragStart.tx + (e.clientX - cropDragStart.x);
      cropTy = cropDragStart.ty + (e.clientY - cropDragStart.y);
      // 限制不拖出边界太远
      var halfIW = cropNaturalW * cropScale / 2;
      var halfIH = cropNaturalH * cropScale / 2;
      cropTx = Math.max(150 - halfIW - 150, Math.min(150 + halfIW - 150, cropTx));
      cropTy = Math.max(150 - halfIH - 150, Math.min(150 + halfIH - 150, cropTy));
      updateCropView();
    });

    window.addEventListener('mouseup', function() {
      cropDragging = false;
    });

    // 滚轮缩放
    cropStage.addEventListener('wheel', function(e) {
      e.preventDefault();
      var delta = e.deltaY > 0 ? -0.05 : 0.05;
      var newScale = cropScale + cropScale * delta;
      newScale = Math.max(300 / Math.max(cropNaturalW, cropNaturalH) * 0.5, Math.min(300 / Math.min(cropNaturalW, cropNaturalH) * 3, newScale));
      if (newScale !== cropScale) {
        // 以鼠标位置为中心缩放
        var rect = cropStage.getBoundingClientRect();
        var mx = e.clientX - rect.left - 150;
        var my = e.clientY - rect.top - 150;
        var ratio = newScale / cropScale;
        cropTx = mx + ratio * (cropTx - mx);
        cropTy = my + ratio * (cropTy - my);
        cropScale = newScale;
        updateCropView();
      }
    });

    // 缩放滑块
    cropZoom.addEventListener('input', function() {
      var baseScale = 300 / Math.min(cropNaturalW, cropNaturalH);
      cropScale = baseScale * (parseInt(cropZoom.value) / 100);
      updateCropView();
    });

    // 按钮缩放
    document.getElementById('crop-zoom-out').addEventListener('click', function() {
      cropZoom.value = Math.max(30, parseInt(cropZoom.value) - 20);
      cropZoom.dispatchEvent(new Event('input'));
    });
    document.getElementById('crop-zoom-in').addEventListener('click', function() {
      cropZoom.value = Math.min(300, parseInt(cropZoom.value) + 20);
      cropZoom.dispatchEvent(new Event('input'));
    });

    // 关闭弹窗
    document.getElementById('crop-close').addEventListener('click', closeCropModal);
    document.getElementById('crop-cancel').addEventListener('click', closeCropModal);
    cropOverlay.addEventListener('click', function(e) {
      if (e.target === cropOverlay) closeCropModal();
    });

    function closeCropModal() {
      cropOverlay.style.display = 'none';
      document.body.style.overflow = '';
      cropSourceImage = null;
      cropImg.src = '';
    }

    // 确认裁切
    document.getElementById('crop-confirm').addEventListener('click', function() {
      if (!cropSourceImage) return;
      var btn = document.getElementById('crop-confirm');
      btn.disabled = true;
      btn.textContent = '处理中...';

      // 在 canvas 上裁出 300x300 可见区域
      var canvas = document.createElement('canvas');
      canvas.width = 300;
      canvas.height = 300;
      var ctx = canvas.getContext('2d');

      var srcW = 300 / cropScale;
      var srcH = 300 / cropScale;
      var srcX = (cropNaturalW / 2) - cropTx / cropScale - srcW / 2;
      var srcY = (cropNaturalH / 2) - cropTy / cropScale - srcH / 2;

      ctx.drawImage(cropSourceImage, srcX, srcY, srcW, srcH, 0, 0, 300, 300);
      canvas.toBlob(function(blob) {
        var formData = new FormData();
        formData.append('png_icon', blob, 'crop.png');
        formData.append('is_cropped', '1');
        var customName = document.getElementById('crop-filename').value.trim();
        if (customName) formData.append('icon_name', customName);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'admin.php');
        xhr.onload = function() {
          try {
            var res = JSON.parse(xhr.responseText);
            if (res.ok) {
              if (cropTargetInput) cropTargetInput.value = res.filename;
              showToast('图标已保存');
              closeCropModal();
              
              var form = cropTargetInput ? cropTargetInput.closest('form') : null;
              var actionInput = form && form.querySelector('input[name="action"]');
              if (actionInput && actionInput.value === 'edit_link') {
                var data = {};
                new FormData(form).forEach(function(v, k) { data[k] = v; });
                ajaxPost(data, function() {
                  showToast('图标已上传并保存');
                  location.reload();
                });
              }
            } else {
              alert(res.error || '上传失败');
            }
          } catch(e) {
            alert('服务器错误，请检查 PHP GD 扩展是否已启用');
          }
          btn.disabled = false;
          btn.textContent = '确认裁切';
        };
        xhr.send(formData);
      }, 'image/png');
    });

  </script>
  </div>
</body>
</html>
