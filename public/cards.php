<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();
require_perm('subscribers');

$isEn = ($lang === 'en');
$groups = array();
$err = '';
$sasReady = function_exists('sas_is_ready') && sas_is_ready($config);

if ($sasReady && function_exists('sas_page_connector')) {
    if (function_exists('set_time_limit')) {
        @set_time_limit(90);
    }
    try {
        $api = sas_page_connector($config);
        if ($api && method_exists($api, 'setTimeout')) {
            $api->setTimeout(45);
        }
        if ($api && method_exists($api, 'listCardsInventory')) {
            $groups = $api->listCardsInventory(16);
        } elseif ($api && function_exists('sas_unused_cards_grouped')) {
            $raw = sas_unused_cards_grouped($api);
            foreach ($raw as $g) {
                $groups[] = array(
                    'name' => isset($g['name']) ? $g['name'] : '',
                    'profile_id' => isset($g['profile_id']) ? (int) $g['profile_id'] : 0,
                    'total' => isset($g['count']) ? (int) $g['count'] : 0,
                    'used' => 0,
                    'unused' => isset($g['count']) ? (int) $g['count'] : 0,
                    'cards' => array(),
                );
            }
        } else {
            $err = $isEn ? 'Cards API unavailable' : 'واجهة الكروت غير متاحة';
        }
    } catch (Exception $e) {
        $err = $isEn ? 'Failed to load cards' : 'تعذر جلب الكروت';
    }
} else {
    $err = $isEn ? 'Enable SAS in settings first' : 'فعّل ربط SAS من الإعدادات أولاً';
}

$sumTotal = 0;
$sumUsed = 0;
$sumUnused = 0;
foreach ($groups as $g0) {
    $sumTotal += isset($g0['total']) ? (int) $g0['total'] : 0;
    $sumUsed += isset($g0['used']) ? (int) $g0['used'] : 0;
    $sumUnused += isset($g0['unused']) ? (int) $g0['unused'] : 0;
}

render_header($isEn ? 'Cards' : 'الكارتات', 'cards');
?>
<style>
.cards-page .cards-summary {
  display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 14px;
}
.cards-page .sum-pill {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 12px; border-radius: 999px; font-size: 12px; font-weight: 800;
  background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0;
}
.cards-page .sum-pill.ok { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
.cards-page .sum-pill.bad { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
.cards-page .cat-block {
  border: 1px solid #d8dee8;
  border-radius: 12px;
  background: #fff;
  margin-bottom: 10px;
  overflow: hidden;
}
.cards-page .cat-head {
  display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
  gap: 8px; padding: 11px 14px; background: #f4f7fb; cursor: pointer; user-select: none;
  border: 0; width: 100%; text-align: inherit; font: inherit; color: inherit;
}
.cards-page .cat-head:hover { background: #eef3f9; }
.cards-page .cat-head h3 {
  margin: 0; font-size: 14px; color: #0f172a; display: flex; align-items: center; gap: 8px;
}
.cards-page .cat-chevron {
  display: inline-block; width: 18px; height: 18px; line-height: 18px; text-align: center;
  border-radius: 6px; background: #e2e8f0; color: #475569; font-size: 12px; font-weight: 800;
  transition: transform .15s ease;
}
.cards-page .cat-block.is-open .cat-chevron { transform: rotate(90deg); }
.cards-page .cat-meta { display: flex; gap: 6px; flex-wrap: wrap; font-size: 11px; font-weight: 700; }
.cards-page .pill {
  display: inline-block; padding: 3px 8px; border-radius: 999px; background: #e2e8f0; color: #334155;
}
.cards-page .pill.ok { background: #dcfce7; color: #166534; }
.cards-page .pill.bad { background: #fee2e2; color: #991b1b; }
.cards-page .cat-body { display: none; border-top: 1px solid #e2e8f0; padding: 10px 12px 12px; }
.cards-page .cat-block.is-open .cat-body { display: block; }
.cards-page .chip-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(118px, 1fr));
  gap: 6px;
}
.cards-page .chip {
  border: 1px solid #e2e8f0; border-radius: 8px; padding: 7px 8px;
  background: #f8fafc; font-size: 12px; line-height: 1.25;
}
.cards-page .chip.used { background: #fff1f2; border-color: #fecdd3; }
.cards-page .chip .pin {
  font-family: Consolas, Monaco, monospace; font-weight: 800;
  direction: ltr; unicode-bidi: isolate; display: block; margin-bottom: 2px;
}
.cards-page .chip .st { font-size: 11px; font-weight: 800; }
.cards-page .chip .st.free { color: #15803d; }
.cards-page .chip .st.used { color: #b91c1c; }
.cards-page .chip .by { font-size: 10px; color: #64748b; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cards-page .empty-cat { padding: 4px 2px; color: #64748b; font-weight: 600; font-size: 13px; }
.cards-page .filter-row { display: flex; gap: 6px; flex-wrap: wrap; margin: 0 0 12px; }
.cards-page .filter-row button {
  border: 1px solid #d2d6de; background: #fff; border-radius: 999px;
  padding: 6px 12px; font-size: 12px; font-weight: 700; cursor: pointer; color: #334155;
}
.cards-page .filter-row button.is-on { background: #1e293b; color: #fff; border-color: #1e293b; }
</style>

<div class="panel cards-page">
    <div class="actions" style="margin-top:0;justify-content:space-between;flex-wrap:wrap;gap:8px">
        <div>
            <h2 style="margin:0"><?php echo e($isEn ? 'Cards by package' : 'الكارتات حسب الفئة'); ?></h2>
            <p class="meta" style="margin:6px 0 0">
                <?php echo e($isEn
                    ? 'Tap a category to expand. Compact view — used vs available.'
                    : 'اضغط الفئة لفتحها. عرض مختصر — مستخدم أو شاغر.'); ?>
            </p>
        </div>
        <a class="btn secondary sm" href="cards.php"><?php echo e($isEn ? 'Refresh' : 'تحديث'); ?></a>
    </div>

    <?php if ($err !== ''): ?>
        <p style="color:#dd4b39;font-weight:700"><?php echo e($err); ?></p>
    <?php elseif (!$groups): ?>
        <p style="font-weight:700;color:#64748b"><?php echo e($isEn ? 'No cards found' : 'ماكو كروت'); ?></p>
    <?php else: ?>
        <div class="cards-summary">
            <span class="sum-pill"><?php echo e($isEn ? 'Categories' : 'فئات'); ?>: <?php echo count($groups); ?></span>
            <span class="sum-pill"><?php echo e($isEn ? 'Total' : 'الكل'); ?>: <?php echo (int) $sumTotal; ?></span>
            <span class="sum-pill ok"><?php echo e($isEn ? 'Unused' : 'شاغر'); ?>: <?php echo (int) $sumUnused; ?></span>
            <span class="sum-pill bad"><?php echo e($isEn ? 'Used' : 'مستخدم'); ?>: <?php echo (int) $sumUsed; ?></span>
        </div>
        <div class="filter-row" id="cardsFilter">
            <button type="button" class="is-on" data-f="all"><?php echo e($isEn ? 'All' : 'الكل'); ?></button>
            <button type="button" data-f="free"><?php echo e($isEn ? 'Unused only' : 'الشواغر فقط'); ?></button>
            <button type="button" data-f="used"><?php echo e($isEn ? 'Used only' : 'المستخدم فقط'); ?></button>
            <button type="button" data-f="expand"><?php echo e($isEn ? 'Expand all' : 'فتح الكل'); ?></button>
            <button type="button" data-f="collapse"><?php echo e($isEn ? 'Collapse' : 'طي الكل'); ?></button>
        </div>

        <?php foreach ($groups as $gi => $g): ?>
            <?php
            $gName = isset($g['name']) ? (string) $g['name'] : '';
            $total = isset($g['total']) ? (int) $g['total'] : 0;
            $used = isset($g['used']) ? (int) $g['used'] : 0;
            $unused = isset($g['unused']) ? (int) $g['unused'] : 0;
            $cards = (isset($g['cards']) && is_array($g['cards'])) ? $g['cards'] : array();
            $openFirst = ($gi === 0);
            ?>
            <div class="cat-block<?php echo $openFirst ? ' is-open' : ''; ?>" data-cat>
                <button type="button" class="cat-head" data-toggle-cat>
                    <h3>
                        <span class="cat-chevron">›</span>
                        <?php echo e($gName !== '' ? $gName : '—'); ?>
                    </h3>
                    <div class="cat-meta">
                        <span class="pill"><?php echo (int) $total; ?></span>
                        <span class="pill ok"><?php echo e($isEn ? 'Free' : 'شاغر'); ?> <?php echo (int) $unused; ?></span>
                        <span class="pill bad"><?php echo e($isEn ? 'Used' : 'مستخدم'); ?> <?php echo (int) $used; ?></span>
                    </div>
                </button>
                <div class="cat-body">
                    <?php if (!$cards): ?>
                        <div class="empty-cat">
                            <?php echo e($isEn
                                ? 'Summary only — pin list unavailable for this series.'
                                : 'ملخص فقط — تفاصيل الأرقام غير متوفرة لهذه السلسلة.'); ?>
                        </div>
                    <?php else: ?>
                        <div class="chip-grid">
                            <?php foreach ($cards as $c):
                                $isUsed = !empty($c['used']);
                                ?>
                                <div class="chip<?php echo $isUsed ? ' used' : ''; ?>" data-used="<?php echo $isUsed ? '1' : '0'; ?>">
                                    <span class="pin"><?php echo e(isset($c['pin']) ? $c['pin'] : ''); ?></span>
                                    <span class="st <?php echo $isUsed ? 'used' : 'free'; ?>">
                                        <?php echo e($isUsed ? ($isEn ? 'Used' : 'مستخدم') : ($isEn ? 'Available' : 'شاغر')); ?>
                                    </span>
                                    <?php if (!empty($c['used_by'])): ?>
                                        <div class="by" title="<?php echo e($c['used_by']); ?>"><?php echo e($c['used_by']); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<script>
(function () {
  var filter = 'all';
  function applyFilter() {
    document.querySelectorAll('.cards-page .chip[data-used]').forEach(function (el) {
      var used = el.getAttribute('data-used') === '1';
      var show = filter === 'all' || (filter === 'used' && used) || (filter === 'free' && !used);
      el.style.display = show ? '' : 'none';
    });
  }
  document.querySelectorAll('[data-toggle-cat]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var block = btn.closest('[data-cat]');
      if (block) block.classList.toggle('is-open');
    });
  });
  var bar = document.getElementById('cardsFilter');
  if (bar) {
    bar.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-f]');
      if (!b) return;
      var f = b.getAttribute('data-f');
      if (f === 'expand') {
        document.querySelectorAll('[data-cat]').forEach(function (el) { el.classList.add('is-open'); });
        return;
      }
      if (f === 'collapse') {
        document.querySelectorAll('[data-cat]').forEach(function (el) { el.classList.remove('is-open'); });
        return;
      }
      filter = f;
      bar.querySelectorAll('button[data-f="all"],button[data-f="free"],button[data-f="used"]').forEach(function (x) {
        x.classList.toggle('is-on', x.getAttribute('data-f') === filter);
      });
      applyFilter();
    });
  }
})();
</script>
<?php render_footer(); ?>
