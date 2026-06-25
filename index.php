<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

// 允许跨域访问（插件需要）
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// HTML 不缓存，确保链接变更后及时生效
header('Cache-Control: no-cache');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() - 86400) . ' GMT');

// 获取 Bing 背景图
$bing_bg = '';
$json = @file_get_contents('https://www.bing.com/HPImageArchive.aspx?format=js&idx=0&n=1');
if ($json) {
  $data = json_decode($json, true);
  if (isset($data['images'][0]['url'])) {
    $bing_bg = 'https://cn.bing.com' . $data['images'][0]['url'];
  }
}

// 读取链接配置
$linksConfig = json_decode(@file_get_contents('links.json'), true);

// 如果插件请求纯 JSON 数据（同步用），直接返回
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');
    echo json_encode($linksConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$categories = $linksConfig['categories'] ?? [];

// 解锁密码（与 admin.php 保持一致，修改时两处都要改）
$UNLOCK_PASSWORD = 'YOUR_PASSWORD_HERE';  // ← 修改为你的密码

// 构建带分类信息的图标列表
$allIcons = [];
$catMeta = [];
foreach ($categories as $catIdx => $cat) {
  $catColor = $cat['color'] ?? '';
  $catMeta[] = [
    'idx'    => $catIdx,
    'name'   => $cat['name'],
    'hidden' => !empty($cat['hidden']),
    'cols'   => $cat['cols'] ?? null
  ];
  foreach ($cat['links'] as $link) {
    $linkColor = (!empty($link['color'])) ? $link['color'] : $catColor;
    $allIcons[] = [
      'cat'    => $catIdx,
      'link'   => $link,
      'color'  => $linkColor
    ];
  }
}
// 哪些分类属于隐藏
$hiddenCatIdxs = [];
foreach ($catMeta as $cm) {
  if ($cm['hidden']) $hiddenCatIdxs[] = $cm['idx'];
}

// AJAX 解锁验证
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_password'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($_POST['unlock_password'] === $UNLOCK_PASSWORD) {
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <meta name="referrer" content="no-referrer">
  <meta name="theme-color" content="#ffffff">
  <link rel="icon" href="img/logo.png">
  <link rel="apple-touch-icon" href="img/logo.png">
  <meta name="msapplication-TileImage" content="img/logo.png">
  <title>龍导航</title>
  <link href="style.css?v=<?php echo filemtime('style.css'); ?>" rel="stylesheet">
</head>

<body>
  <!-- 壁纸层（模糊） -->
  <div class="wallpaper-bg"<?php if ($bing_bg): ?> style="background-image:url('<?php echo $bing_bg; ?>')"<?php endif; ?>>
    <div class="wallpaper-bg-mask"></div>
  </div>

  <!-- 主内容 -->
  <div class="main-wrap">
    <!-- 搜索栏 -->
    <div class="search-box">
      <form action="https://www.bing.com/search" method="get" target="_blank" class="search-form">
        <div class="search-engine-icon">
          <div class="engine-logo" style="background-image:url(img/bing.svg)"></div>
          <svg class="arrow-down-sm" viewBox="0 0 8 7"><polygon points="0,0 8,0 4,7"/></svg>
        </div>
        <input type="search" class="search-input" placeholder="请输入搜索内容" name="q" lang="zh-CN" autocomplete="off">
        <button type="submit" class="search-btn">
          <svg viewBox="0 0 20 20" fill="none" width="22" height="22"><circle cx="8.5" cy="8.5" r="5.5" stroke="#fff" stroke-width="2"/><line x1="12.5" y1="12.5" x2="17" y2="17" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>
        </button>
      </form>
    </div>

    <!-- 分类标签栏 -->
    <div class="tab-bar" id="tab-bar"></div>

    <!-- 图标区域 -->
    <div class="icons-scroll">
      <div class="pages-track-v" id="pages-track">
      </div>
    </div>

    <!-- 分页圆点 -->
    <div class="swiper-pagination" id="swiper-dots"></div>

    <!-- 底部 -->
    <div class="footer" id="footer-secret">
      <div><span>龍导航</span></div>
      <div>
        <a href="admin.php" target="_blank">管理</a>
      </div>
    </div>
  </div>

  <!-- 密码解锁弹窗 -->
  <div class="unlock-overlay" id="unlock-overlay">
    <div class="unlock-dialog">
      <h3>权限验证</h3>
      <input type="password" id="unlock-input" placeholder="请输入解锁密码" autocomplete="off">
      <p class="unlock-error" id="unlock-error" style="display:none">密码错误</p>
      <div class="unlock-actions">
        <button type="button" id="unlock-cancel" class="btn-unlock btn-unlock-cancel">取消</button>
        <button type="button" id="unlock-submit" class="btn-unlock btn-unlock-submit">确认</button>
      </div>
    </div>
  </div>

  <template id="icon-tpl">
    <div class="icon-card">
      <a target="_blank">
        <div class="icon-thumb">
          <img class="icon-img" src="" alt="" style="display:none;">
        </div>
        <div class="icon-label"></div>
      </a>
    </div>
  </template>

  <script>
  (function(){
    // === PHP 注入的数据 ===
    var allIcons = <?php echo json_encode($allIcons); ?>;
    var catMeta = <?php echo json_encode($catMeta); ?>;
    var hiddenCatIdxs = <?php echo json_encode($hiddenCatIdxs); ?>;
    var maxCols = <?php echo json_encode($linksConfig['cols'] ?? 8); ?>;
    var favCols = <?php echo json_encode($linksConfig['fav_cols'] ?? ($linksConfig['cols'] ?? 8)); ?>;
    var allCols = <?php echo json_encode($linksConfig['all_cols'] ?? ($linksConfig['cols'] ?? 8)); ?>;

    // === 解锁状态 ===
    var unlocked = localStorage.getItem('nav_unlocked') === 'true';

    var currentCat = 'favorites';
    var currentPage = 0;
    var totalPages = 1;
    var busy = false;
    var cols = maxCols;

    var track = document.getElementById('pages-track');
    var dotsEl = document.getElementById('swiper-dots');
    var scrollEl = document.querySelector('.icons-scroll');
    var tabBar = document.getElementById('tab-bar');
    var tpl = document.getElementById('icon-tpl');

    // === 渲染分类标签 ===
    function renderTabs() {
      var html = '<span class="tab-item active" data-cat="favorites">热门</span>';
      html += '<span class="tab-item" data-cat="all">全部</span>';
      catMeta.forEach(function(cm) {
        if (cm.hidden && !unlocked) return;
        html += '<span class="tab-item" data-cat="' + cm.idx + '">' + cm.name + '</span>';
      });
      tabBar.innerHTML = html;

      // 重新绑定事件
      var tabs = tabBar.querySelectorAll('.tab-item');
      tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
          tabs.forEach(function(t) { t.classList.remove('active'); });
          tab.classList.add('active');
          currentCat = tab.dataset.cat;
          rebuildPages();
        });
      });
    }

    // === 计算列数 ===
    function calcCols(cat) {
      var base;
      if (cat === 'favorites') base = favCols;
      else if (cat === 'all') base = allCols;
      else {
        var cm = catMeta.find(function(m) { return String(m.idx) === String(cat); });
        base = (cm && cm.cols) ? cm.cols : maxCols;
      }
      var w = window.innerWidth;
      if (w >= 1500) return base;
      if (w >= 1300) return Math.min(base, 9);
      if (w >= 1000) return Math.min(base, 7);
      if (w >= 700)  return Math.min(base, 6);
      if (w >= 500)  return Math.min(base, 5);
      return Math.min(base, 4);
    }

    // === 构建一个图标卡片 DOM ===
    function buildCard(item) {
      var clone = tpl.content.cloneNode(true);
      var card = clone.querySelector('.icon-card');
      var a = card.querySelector('a');
      var thumb = card.querySelector('.icon-thumb');
      var img = card.querySelector('.icon-img');
      var label = card.querySelector('.icon-label');

      card.dataset.cat = item.cat;
      a.href = item.link.url;
      a.title = item.link.name;
      label.textContent = item.link.name;

      if (item.color) {
        // 将纯色转为带透明度的 rgba，保持壁纸可见
        var r = parseInt(item.color.slice(1,3), 16);
        var g = parseInt(item.color.slice(3,5), 16);
        var b = parseInt(item.color.slice(5,7), 16);
        thumb.style.backgroundColor = 'rgba(' + r + ',' + g + ',' + b + ',0.85)';
      }

      var icon = item.link.icon || '';
      var isFile = /\.(png|svg)$/i.test(icon);
      var isText = /^([\u4e00-\u9fa5]{1,2}|[a-zA-Z]{1,2})$/.test(icon);

      if (isFile) {
        img.src = 'img/favicons/' + icon;
        img.alt = item.link.name;
        img.style.display = '';
      } else if (isText) {
        var txt = document.createElement('span');
        txt.className = 'thumb-fallback';
        txt.textContent = icon;
        thumb.appendChild(txt);
      }
      return card;
    }

    // === 过滤可见图标 ===
    function getVisibleIcons() {
      var list = allIcons;
      // 未解锁时过滤隐藏
      if (!unlocked) {
        list = list.filter(function(item) {
          if (hiddenCatIdxs.indexOf(item.cat) !== -1) return false; // 分类隐藏
          return true;
        });
      }
      if (currentCat === 'all') return list;
      if (currentCat === 'favorites') {
        return list.filter(function(item) { return item.link.favorite === true; });
      }
      return list.filter(function(item) { return String(item.cat) === currentCat; });
    }

    // === 重建分页 ===
    function rebuildPages() {
      cols = calcCols(currentCat);
      var visible = getVisibleIcons();
      var isFav = (currentCat === 'favorites');

      // 行数根据屏幕高度自适应，最少3行，最多6行
      var availableH = window.innerHeight - 190;
      var rowH = 64 + 10 + 18 + 20;
      var rows = Math.max(3, Math.min(6, Math.floor(availableH / rowH)));
      if (isFav) {
        rows = Math.max(rows, Math.ceil(visible.length / cols));
      }
      var perPage = cols * rows;
      totalPages = Math.max(1, Math.ceil(visible.length / perPage));

      track.innerHTML = '';
      dotsEl.innerHTML = '';
      document.documentElement.style.setProperty('--icon-cols', cols);
      var colGap = (cols >= 4 && cols <= 10) ? 10 : 18;
      track.style.setProperty('--col-gap', colGap + 'px');

      for (var p = 0; p < totalPages; p++) {
        var page = document.createElement('div');
        page.className = 'snap-page';

        var start = p * perPage;
        var pageIcons = visible.slice(start, start + perPage);
        pageIcons.forEach(function(item) {
          page.appendChild(buildCard(item));
        });

        track.appendChild(page);

        var dot = document.createElement('span');
        dot.className = 'dot' + (p === 0 ? ' active' : '');
        dot.dataset.page = p;
        dotsEl.appendChild(dot);
      }

      if (totalPages <= 1) dotsEl.style.display = 'none';
      else dotsEl.style.display = '';

      // 根据实际渲染高度设置容器高度
      scrollEl.style.height = 'auto';
      var firstPage = track.querySelector('.snap-page');
      if (firstPage) {
        // 先用 auto 让内容撑开，再取真实高度
        scrollEl.style.height = firstPage.offsetHeight + 'px';
      }

      goToPage(0, true);

      setTimeout(function() {
        track.querySelectorAll('.icon-card').forEach(function(card, i) {
          setTimeout(function() {
            card.classList.add('loaded');
          }, i * 30);
        });
      }, 50);
    }

    // === 翻页 ===
    function goToPage(target, instant) {
      if (target < 0 || target >= totalPages) return;
      currentPage = target;
      track.style.transition = instant ? 'none' : 'transform 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94)';
      track.style.transform = 'translateY(-' + (currentPage * 100) + '%)';

      var dots = dotsEl.querySelectorAll('.dot');
      dots.forEach(function(d, i) {
        d.classList.toggle('active', i === currentPage);
      });
    }

    function slide(dir) {
      if (busy || totalPages <= 1) return;
      var target = currentPage + dir;
      if (target < 0 || target >= totalPages) return;
      busy = true;
      goToPage(target, false);
      setTimeout(function() { busy = false; }, 360);
    }

    // === 分页圆点点击 ===
    dotsEl.addEventListener('click', function(e) {
      var dot = e.target.closest('.dot');
      if (!dot) return;
      var p = parseInt(dot.dataset.page);
      if (p !== currentPage) {
        busy = true;
        goToPage(p, false);
        setTimeout(function() { busy = false; }, 360);
      }
    });

    // === 滚轮翻页 ===
    var wheelTimer = 0;
    if (scrollEl) {
      scrollEl.addEventListener('wheel', function(e) {
        if (totalPages <= 1) return;
        e.preventDefault();
        clearTimeout(wheelTimer);
        wheelTimer = setTimeout(function() {
          if (e.deltaY > 0) slide(1);
          else slide(-1);
        }, 60);
      }, { passive: false });
    }

    // === 触摸滑动翻页 ===
    var touchStartY = 0;
    var touchStarted = false;
    if (scrollEl) {
      scrollEl.addEventListener('touchstart', function(e) {
        if (totalPages <= 1) return;
        if (e.touches.length === 1) {
          touchStartY = e.touches[0].clientY;
          touchStarted = true;
        }
      }, { passive: true });

      scrollEl.addEventListener('touchmove', function(e) {
        if (!touchStarted || totalPages <= 1) return;
        e.preventDefault();
      }, { passive: false });

      scrollEl.addEventListener('touchend', function(e) {
        if (!touchStarted || totalPages <= 1) return;
        touchStarted = false;
        var dy = (e.changedTouches[0] || {}).clientY - touchStartY;
        if (Math.abs(dy) > 40) {
          if (dy > 0) slide(-1);
          else slide(1);
        }
      });
    }

    // === 窗口resize重建 ===
    var resizeTimer = 0;
    window.addEventListener('resize', function() {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function() {
        var newCols = calcCols(currentCat);
        if (newCols !== cols) rebuildPages();
      }, 250);
    });

    // ==== 隐藏分类解锁 ====
    var unlockOverlay = document.getElementById('unlock-overlay');
    var unlockError = document.getElementById('unlock-error');
    var footerEl = document.getElementById('footer-secret');
    var clickCount = 0;
    var clickTimer = null;

    // 连续点击底部页脚 3 次弹出解锁框
    footerEl.addEventListener('click', function(e) {
      if (unlocked) return;
      clickCount++;
      clearTimeout(clickTimer);
      if (clickCount >= 3) {
        showUnlock();
        clickCount = 0;
      }
      clickTimer = setTimeout(function() { clickCount = 0; }, 2000);
    });

    function showUnlock() {
      if (unlockOverlay.style.display === 'flex') return;
      unlockOverlay.style.display = 'flex';
      unlockError.style.display = 'none';
      document.getElementById('unlock-input').value = '';
      setTimeout(function() {
        document.getElementById('unlock-input').focus();
      }, 100);
    }

    function hideUnlock() {
      unlockOverlay.style.display = 'none';
    }

    document.getElementById('unlock-submit').addEventListener('click', function() {
      var val = document.getElementById('unlock-input').value;
      // 通过 AJAX 验证密码哈希
      var xhr = new XMLHttpRequest();
      xhr.open('POST', window.location.href, true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.onload = function() {
        var resp = JSON.parse(xhr.responseText);
        if (resp.ok) {
          localStorage.setItem('nav_unlocked', 'true');
          window.location.reload();
        } else {
          unlockError.style.display = 'block';
        }
      };
      xhr.send('unlock_password=' + encodeURIComponent(val));
    });

    document.getElementById('unlock-cancel').addEventListener('click', hideUnlock);

    document.getElementById('unlock-input').addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        document.getElementById('unlock-submit').click();
      }
    });

    // 点击遮罩关闭
    unlockOverlay.addEventListener('click', function(e) {
      if (e.target === unlockOverlay) hideUnlock();
    });

    // === 初始构建 ===
    renderTabs();
    rebuildPages();
  })();
  </script>
</body>
</html>
