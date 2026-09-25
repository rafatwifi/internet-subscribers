<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$isEn = ($lang === 'en');
$tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
if (function_exists('ensure_tenants_schema')) {
    ensure_tenants_schema($pdo, $config);
}
$row = function_exists('tenant_row') ? tenant_row($pdo, $tid) : null;
if (!$row) {
    flash('error', $isEn ? 'Company not found' : 'الشركة غير موجودة');
    redirect('index.php');
}

$isPortalStaff = function_exists('is_super_admin_user') && is_super_admin_user();
$isAgentView = !$isPortalStaff;
$canEdit = $isPortalStaff && function_exists('user_can') && (user_can('settings') || user_can('users'));
if ($isAgentView) {
    $tid = 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $isEn ? 'Invalid request' : 'طلب غير صالح');
        redirect('company.php');
    }
    $logoPath = isset($row['company_logo']) ? (string) $row['company_logo'] : '';
    if (!empty($_FILES['company_logo']['tmp_name']) && is_uploaded_file($_FILES['company_logo']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, array('png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'), true)) {
            flash('error', $isEn ? 'Invalid logo type' : 'نوع الشعار غير مدعوم');
            redirect('company.php');
        }
        $dir = dirname(__DIR__) . '/public/uploads/company';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $fname = 't' . $tid . '_' . time() . '.' . $ext;
        $dest = $dir . '/' . $fname;
        if (@move_uploaded_file($_FILES['company_logo']['tmp_name'], $dest)) {
            $logoPath = 'uploads/company/' . $fname;
        }
    }
    $data = array(
        'name' => trim((string) post('name', $row['name'])),
        'contact_phone' => trim((string) post('contact_phone', '')),
        'company_email' => trim((string) post('company_email', '')),
        'company_address' => trim((string) post('company_address', '')),
        'company_about' => trim((string) post('company_about', '')),
        'company_logo' => $logoPath,
        'company_map_url' => trim((string) post('company_map_url', '')),
    );
    if ($data['name'] === '') {
        flash('error', $isEn ? 'Name required' : 'اسم الشركة مطلوب');
        redirect('company.php');
    }
    if (tenant_save($pdo, $tid, $data)) {
        flash('success', t('saved'));
    } else {
        flash('error', $isEn ? 'Save failed' : 'فشل الحفظ');
    }
    redirect('company.php');
}

$row = function_exists('tenant_row') ? tenant_row($pdo, $tid) : null;
if (!$row || !is_array($row)) {
    $row = array('name' => '', 'company_logo' => '', 'company_about' => '', 'contact_phone' => '', 'company_email' => '', 'company_address' => '', 'company_map_url' => '');
}
if ($isAgentView && trim((string) $row['name']) === '' && !empty($config['site_name'])) {
    $row['name'] = (string) $config['site_name'];
}
$logoUrl = !empty($row['company_logo']) ? (string) $row['company_logo'] : '';
if ($logoUrl === '' && function_exists('brand_icon_url') && $tid <= 1) {
    $logoUrl = brand_icon_url($settings);
}

render_header($isEn ? 'Company' : 'عن الشركة', 'company');
?>
<style>
.company-hero {
  display:flex; gap:22px; align-items:flex-start; flex-wrap:wrap;
  padding:8px 0 6px;
}
.company-logo {
  width:112px; height:112px; border-radius:18px; object-fit:cover;
  background:#e8eef4; border:1px solid #d5dee8; flex:0 0 112px;
}
.company-logo.placeholder {
  display:flex; align-items:center; justify-content:center;
  font-weight:800; color:#64748b; font-size:28px;
}
.company-meta h2 { margin:0 0 8px; font-size:1.45rem; }
.company-meta p { margin:4px 0; color:#334155; }
.company-meta .label { color:#64748b; font-weight:600; margin-inline-end:6px; }
.company-about {
  margin-top:18px; padding-top:14px; border-top:1px solid #e2e8f0;
  white-space:pre-wrap; line-height:1.7; color:#1e293b;
}
</style>

<?php if ($isAgentView): ?>
<style>
.portal-co {
  position: relative;
  overflow: hidden;
  border-radius: 22px;
  background: #fff;
  border: 1px solid #e2e8f0;
  box-shadow: 0 18px 40px rgba(15, 23, 42, .08);
}
.portal-co-band {
  height: 148px;
  background:
    radial-gradient(circle at 12% 20%, rgba(255,255,255,.35), transparent 42%),
    linear-gradient(120deg, #0f766e 0%, #155e75 48%, #1e3a5f 100%);
}
.portal-co-body { padding: 0 28px 28px; margin-top: -52px; }
.portal-co-logo {
  width: 104px; height: 104px; border-radius: 26px; object-fit: cover;
  background: #fff; border: 4px solid #fff;
  box-shadow: 0 10px 24px rgba(15, 23, 42, .18);
}
.portal-co-logo.ph {
  display: flex; align-items: center; justify-content: center;
  font-size: 36px; font-weight: 800; color: #0f766e;
}
.portal-co h2 { margin: 14px 0 6px; font-size: 1.7rem; color: #0f172a; }
.portal-co .kicker { margin: 0; color: #0f766e; font-weight: 800; font-size: 13px; letter-spacing: .04em; }
.portal-co-about {
  margin: 14px 0 0; line-height: 1.85; color: #334155; white-space: pre-wrap; font-size: 15px;
}
.portal-co-pills { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 18px; }
.portal-co-pills a, .portal-co-pills span {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 12px; border-radius: 999px;
  background: #f0fdfa; color: #115e59; text-decoration: none; font-weight: 700; font-size: 13px;
  border: 1px solid #99f6e4;
}
</style>
<div class="portal-co">
    <div class="portal-co-band"></div>
    <div class="portal-co-body">
        <?php if ($logoUrl !== ''): ?>
            <img class="portal-co-logo" src="<?php echo e($logoUrl); ?>" alt="">
        <?php else: ?>
            <div class="portal-co-logo ph"><?php echo e(function_exists('mb_substr') ? mb_substr(isset($row['name']) ? $row['name'] : '?', 0, 1, 'UTF-8') : substr(isset($row['name']) ? $row['name'] : '?', 0, 1)); ?></div>
        <?php endif; ?>
        <p class="kicker"><?php echo e($isEn ? 'The portal' : 'البوابة'); ?></p>
        <h2><?php echo e(isset($row['name']) ? $row['name'] : ''); ?></h2>
        <?php if (!empty($row['company_about'])): ?>
            <p class="portal-co-about"><?php echo e($row['company_about']); ?></p>
        <?php endif; ?>
        <div class="portal-co-pills">
            <?php if (!empty($row['contact_phone'])): ?>
                <span class="ltr"><?php echo e($row['contact_phone']); ?></span>
            <?php endif; ?>
            <?php if (!empty($row['company_email'])): ?>
                <span class="ltr"><?php echo e($row['company_email']); ?></span>
            <?php endif; ?>
            <?php if (!empty($row['company_address'])): ?>
                <span><?php echo e($row['company_address']); ?></span>
            <?php endif; ?>
            <?php if (!empty($row['company_map_url'])): ?>
                <a class="ltr" target="_blank" rel="noopener" href="<?php echo e($row['company_map_url']); ?>"><?php echo e($isEn ? 'Map' : 'الخريطة'); ?></a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php else: ?>
<div class="panel">
    <div class="company-hero">
        <?php if ($logoUrl !== ''): ?>
            <img class="company-logo" src="<?php echo e($logoUrl); ?>" alt="">
        <?php else: ?>
            <div class="company-logo placeholder"><?php echo e(substr(isset($row['name']) ? $row['name'] : '?', 0, 1)); ?></div>
        <?php endif; ?>
        <div class="company-meta">
            <h2><?php echo e(isset($row['name']) ? $row['name'] : ''); ?></h2>
            <?php if (!empty($row['company_address'])): ?>
                <p><span class="label"><?php echo e($isEn ? 'Address' : 'العنوان'); ?>:</span><?php echo e($row['company_address']); ?>
                <?php if (!empty($row['company_map_url'])): ?>
                    — <a class="ltr" target="_blank" rel="noopener" href="<?php echo e($row['company_map_url']); ?>"><?php echo e($isEn ? 'Map' : 'الخريطة'); ?></a>
                <?php endif; ?>
                </p>
            <?php elseif (!empty($row['company_map_url'])): ?>
                <p><a target="_blank" rel="noopener" href="<?php echo e($row['company_map_url']); ?>"><?php echo e($isEn ? 'Open map' : 'فتح الخريطة'); ?></a></p>
            <?php endif; ?>
            <?php if (!empty($row['contact_phone'])): ?>
                <p><span class="label"><?php echo e($isEn ? 'Phone' : 'الهاتف'); ?>:</span>
                    <span class="ltr"><?php echo e($row['contact_phone']); ?></span></p>
            <?php endif; ?>
            <?php if (!empty($row['company_email'])): ?>
                <p><span class="label"><?php echo e($isEn ? 'Email' : 'البريد'); ?>:</span>
                    <span class="ltr"><?php echo e($row['company_email']); ?></span></p>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!empty($row['company_about'])): ?>
        <div class="company-about"><?php echo e($row['company_about']); ?></div>
    <?php elseif (!$canEdit): ?>
        <p class="meta"><?php echo e($isEn ? 'No company profile yet.' : 'ماكو نبذة عن الشركة بعد.'); ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="panel">
    <h2><?php echo e($isEn ? 'Edit company profile' : 'تعديل ملف الشركة'); ?></h2>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <div class="form-grid cols-2">
            <div>
                <label><?php echo e($isEn ? 'Company name' : 'اسم الشركة'); ?></label>
                <input name="name" required value="<?php echo e(isset($row['name']) ? $row['name'] : ''); ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Phone' : 'رقم الهاتف'); ?></label>
                <input class="ltr" name="contact_phone" value="<?php echo e(isset($row['contact_phone']) ? $row['contact_phone'] : ''); ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Email' : 'البريد الإلكتروني'); ?></label>
                <input class="ltr" type="email" name="company_email" value="<?php echo e(isset($row['company_email']) ? $row['company_email'] : ''); ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Address' : 'العنوان'); ?></label>
                <input name="company_address" value="<?php echo e(isset($row['company_address']) ? $row['company_address'] : ''); ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Map link' : 'رابط الخريطة'); ?></label>
                <input class="ltr" name="company_map_url" placeholder="https://maps.google.com/..."
                       value="<?php echo e(isset($row['company_map_url']) ? $row['company_map_url'] : ''); ?>">
            </div>
            <div style="grid-column:1/-1">
                <label><?php echo e($isEn ? 'About' : 'نبذة'); ?></label>
                <textarea name="company_about" rows="5"><?php echo e(isset($row['company_about']) ? $row['company_about'] : ''); ?></textarea>
            </div>
            <div>
                <label><?php echo e($isEn ? 'Logo' : 'الشعار (لوكو)'); ?></label>
                <input type="file" name="company_logo" accept="image/*">
            </div>
        </div>
        <div class="actions" style="margin-top:12px">
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
        </div>
    </form>
</div>
<?php endif; ?>
<?php render_footer(); ?>
