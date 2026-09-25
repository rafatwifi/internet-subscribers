<?php

/**
 * تبويبات صفحة الإعدادات (مشتركة مع الباقات وغيرها)
 */
function render_settings_tabs($current = 'general')
{
    global $lang;
    $isEn = (isset($lang) && $lang === 'en');
    $can = function ($p) {
        return function_exists('user_can') ? user_can($p) : true;
    };
    $tidTab = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    $agencySas = ($tidTab > 1);
    $platformTabs = function_exists('is_super_admin_user') && is_super_admin_user();
    ?>
<div class="tabs">
    <?php if ($platformTabs && $can('settings')): ?>
    <a class="<?php echo $current === 'general' ? 'on' : ''; ?>" href="settings.php?tab=general"><?php echo e(t('settings_general')); ?></a>
    <?php endif; ?>
    <?php if ($platformTabs && $can('users')): ?>
    <a class="<?php echo $current === 'users' ? 'on' : ''; ?>" href="settings.php?tab=users"><?php echo e($isEn ? 'Roles' : 'الأدوار'); ?></a>
    <?php endif; ?>
    <?php if ($can('plans')): ?>
    <a class="<?php echo $current === 'plans' ? 'on' : ''; ?>" href="plans.php"><?php echo e(t('plans')); ?></a>
    <?php endif; ?>
    <?php if (!$platformTabs && ($can('cards') || $can('agents') || (function_exists('is_agent_user') && is_agent_user()) || (function_exists('is_group_manager_user') && is_group_manager_user()))): ?>
    <a class="<?php echo $current === 'prices' ? 'on' : ''; ?>" href="agent_prices.php"><?php echo e($isEn ? 'Package prices' : 'تسعير الباقات'); ?></a>
    <?php endif; ?>
    <?php if ($can('settings')): ?>
    <a class="<?php echo $current === 'rental' ? 'on' : ''; ?>" href="settings.php?tab=rental"><?php echo e($isEn ? 'Rental devices' : 'أجهزة الإيجار'); ?></a>
    <a class="<?php echo $current === 'whatsapp' ? 'on' : ''; ?>" href="settings.php?tab=whatsapp"><?php echo e(t('settings_whatsapp')); ?></a>
    <?php if (function_exists('is_super_admin_user') && is_super_admin_user()): ?>
    <a class="<?php echo $current === 'maintenance' ? 'on' : ''; ?>" href="settings.php?tab=maintenance"><?php echo e($isEn ? 'Maintenance' : 'صيانة النظام'); ?></a>
    <?php endif; ?>
    <a class="<?php echo $current === 'sas' ? 'on' : ''; ?>" href="settings.php?tab=sas"><?php
        echo e($agencySas ? ($isEn ? 'SAS accounts' : 'حسابات الساس') : t('settings_sas'));
    ?></a>
    <?php if (function_exists('is_super_admin_user') && is_super_admin_user()): ?>
    <a class="<?php echo $current === 'saas' ? 'on' : ''; ?>" href="settings.php?tab=saas"><?php echo e($isEn ? 'SaaS / ZainCash' : 'الاستضافة / زين كاش'); ?></a>
    <?php endif; ?>
    <?php if (function_exists('is_super_admin_user') && is_super_admin_user()): ?>
    <a class="<?php echo $current === 'update' ? 'on' : ''; ?>" href="settings.php?tab=update"><?php echo e($isEn ? 'System update' : 'تحديث النظام'); ?></a>
    <?php if ($can('clear_data')): ?>
    <a class="<?php echo $current === 'sensitive' ? 'on' : ''; ?>" href="settings.php?tab=sensitive"><?php echo e($isEn ? 'Sensitive data' : 'بيانات حساسة'); ?></a>
    <?php endif; ?>
    <?php endif; ?>
    <?php elseif ($agencySas): ?>
    <a class="<?php echo $current === 'sas' ? 'on' : ''; ?>" href="settings.php?tab=sas"><?php echo e($isEn ? 'SAS accounts' : 'حسابات الساس'); ?></a>
    <?php if (function_exists('is_agent_user') && is_agent_user()): ?>
    <a class="<?php echo $current === 'whatsapp' ? 'on' : ''; ?>" href="settings.php?tab=whatsapp"><?php echo e(t('settings_whatsapp')); ?></a>
    <?php endif; ?>
    <?php elseif (function_exists('is_agent_user') && is_agent_user()): ?>
    <a class="<?php echo $current === 'whatsapp' ? 'on' : ''; ?>" href="settings.php?tab=whatsapp"><?php echo e(t('settings_whatsapp')); ?></a>
    <?php endif; ?>
    <?php if ($platformTabs && $can('backup')): ?>
    <a class="<?php echo $current === 'backup' ? 'on' : ''; ?>" href="backup.php"><?php echo e(t('backup')); ?></a>
    <?php endif; ?>
</div>
    <?php
}
