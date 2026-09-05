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
    ?>
<div class="tabs">
    <?php if ($can('settings')): ?>
    <a class="<?php echo $current === 'general' ? 'on' : ''; ?>" href="settings.php?tab=general"><?php echo e(t('settings_general')); ?></a>
    <?php endif; ?>
    <?php if ($can('users')): ?>
    <a class="<?php echo $current === 'users' ? 'on' : ''; ?>" href="settings.php?tab=users"><?php echo e($isEn ? 'Users' : 'المستخدمين'); ?></a>
    <?php endif; ?>
    <?php if ($can('plans')): ?>
    <a class="<?php echo $current === 'plans' ? 'on' : ''; ?>" href="plans.php"><?php echo e(t('plans')); ?></a>
    <?php endif; ?>
    <?php if ($can('settings')): ?>
    <a class="<?php echo $current === 'rental' ? 'on' : ''; ?>" href="settings.php?tab=rental"><?php echo e($isEn ? 'Rental devices' : 'أجهزة الإيجار'); ?></a>
    <a class="<?php echo $current === 'whatsapp' ? 'on' : ''; ?>" href="settings.php?tab=whatsapp"><?php echo e(t('settings_whatsapp')); ?></a>
    <a class="<?php echo $current === 'sas' ? 'on' : ''; ?>" href="settings.php?tab=sas"><?php echo e(t('settings_sas')); ?></a>
    <a class="<?php echo $current === 'schedule' ? 'on' : ''; ?>" href="settings.php?tab=schedule"><?php echo e($isEn ? 'Periodic jobs' : 'الجدول الدوري'); ?></a>
    <?php if ($can('clear_data')): ?>
    <a class="<?php echo $current === 'sensitive' ? 'on' : ''; ?>" href="settings.php?tab=sensitive"><?php echo e($isEn ? 'Sensitive data' : 'بيانات حساسة'); ?></a>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($can('backup')): ?>
    <a class="<?php echo $current === 'backup' ? 'on' : ''; ?>" href="backup.php"><?php echo e(t('backup')); ?></a>
    <?php endif; ?>
</div>
    <?php
}
