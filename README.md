# نظام إدارة مشتركي الإنترنت والديون

نظام PHP + MySQL لإدارة المشتركين، الاشتراكات، الديون، وإرسال رسائل واتساب (تفعيل + تذكير).

المسار على جهازك:
`C:\Users\rafat\Desktop\MY-Project\internet-subscribers`

---

## ماذا يفعل النظام؟

- إضافة المشتركين وأرقام الواتساب
- تعريف باقات الإنترنت
- تفعيل اشتراك (نوع الخدمة + تاريخ البداية/النهاية + السعر)
- إنشاء فاتورة/دين لكل شهر
- عرض إجمالي الديون
- تسجيل التسديد
- إرسال رسالة واتساب عند التفعيل
- تذكير تلقائي بعد مدة سماح (مثلاً يومين) عبر Cron

---

## 1) المتطلبات على سيرفر Ubuntu

```bash
sudo apt update
sudo apt install apache2 mysql-server php php-mysql php-curl php-mbstring unzip -y
sudo systemctl enable --now apache2 mysql
```

---

## 2) رفع الملفات

ارفع مجلد المشروع إلى السيرفر، مثلاً:

```bash
sudo mkdir -p /var/www/internet-subscribers
# انسخ الملفات إلى /var/www/internet-subscribers
sudo chown -R www-data:www-data /var/www/internet-subscribers
```

من ويندوز يمكنك استخدام WinSCP أو:

```bash
scp -r internet-subscribers user@YOUR_PUBLIC_IP:/tmp/
```

ثم على السيرفر انقلها إلى `/var/www/`.

---

## 3) إعداد قاعدة البيانات

```bash
sudo mysql
```

ثم نفّذ:

```sql
SOURCE /var/www/internet-subscribers/database/schema.sql;
CREATE USER 'subs_user'@'localhost' IDENTIFIED BY 'PASSWORD_QAWI';
GRANT ALL PRIVILEGES ON internet_subs.* TO 'subs_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

أو يدوياً:

```bash
sudo mysql < /var/www/internet-subscribers/database/schema.sql
```

ثم أنشئ المستخدم كما فوق.

---

## 4) تعديل الإعدادات

```bash
sudo nano /var/www/internet-subscribers/config/config.php
```

غيّر على الأقل:
- `db.pass`
- `admin_password`
- `cron_secret`
- إعدادات واتساب لاحقاً

---

## 5) إعداد Apache

أنشئ VirtualHost:

```bash
sudo nano /etc/apache2/sites-available/internet-subscribers.conf
```

المحتوى:

```apache
<VirtualHost *:80>
    ServerName YOUR_PUBLIC_IP
    DocumentRoot /var/www/internet-subscribers/public

    <Directory /var/www/internet-subscribers/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/subs-error.log
    CustomLog ${APACHE_LOG_DIR}/subs-access.log combined
</VirtualHost>
```

فعّل الموقع:

```bash
sudo a2ensite internet-subscribers.conf
sudo a2enmod rewrite
sudo systemctl reload apache2
```

افتح من المتصفح:

`http://YOUR_PUBLIC_IP/`

كلمة المرور الافتراضية: `admin123`  
**غيّرها فوراً** في `config/config.php`.

---

## 6) واتساب Cloud API

1. ادخل [Meta for Developers](https://developers.facebook.com/)
2. أنشئ تطبيق WhatsApp
3. خذ:
   - Access Token
   - Phone Number ID
4. في `config.php`:

```php
'whatsapp' => [
    'enabled' => true,
    'token' => 'TOKEN_HERE',
    'phone_number_id' => 'PHONE_ID_HERE',
    ...
],
```

ملاحظات مهمة:
- الرسائل النصية العادية تعمل غالباً خلال نافذة 24 ساعة من آخر محادثة مع العميل
- للتذكير خارج هذه النافذة تحتاج **Template Message** معتمد من Meta
- رقم الهاتف يُحفظ بصيغة دولية تلقائياً مثل `9647XXXXXXXXX`

إلى أن تجهز API، اترك `enabled => false` وجرّب باقي النظام.

---

## 7) التذكير التلقائي (Cron)

مدة السماح الافتراضية يومين (`grace_days`).

أضف مهمة يومية:

```bash
sudo crontab -e
```

مثال (كل يوم 10 صباحاً):

```cron
0 10 * * * /usr/bin/php /var/www/internet-subscribers/cron/send_reminders.php >> /var/log/subs-reminders.log 2>&1
```

اختبار يدوي:

```bash
php /var/www/internet-subscribers/cron/send_reminders.php
```

أو من المتصفح:

`http://YOUR_IP/../` لا يُفضل فتح cron من الويب إذا DocumentRoot = public.

للاختبار عبر الويب انقل/اربط الملف أو شغّله من CLI فقط (مستحسن).

---

## 8) طريقة الاستخدام اليومية

1. **الباقات**: أضف سرعات/أسعار الإنترنت
2. **المشتركين**: أضف الاسم ورقم الواتساب
3. **الاشتراكات**: اختر المشترك + الباقة + تاريخ البداية/النهاية
   - فعّل خيار إنشاء فاتورة
   - فعّل إرسال واتساب التفعيل
4. **الديون**:
   - شاهد كل الديون
   - اضغط تسديد عند الدفع
   - أو أرسل تذكير واتساب يدوياً
5. الكرون يرسل تذكير تلقائي بعد انتهاء مدة السماح

---

## هيكل المشروع

```
internet-subscribers/
├── config/
│   ├── config.example.php
│   └── config.php
├── cron/
│   └── send_reminders.php
├── database/
│   └── schema.sql
├── includes/
│   ├── auth.php
│   ├── bootstrap.php
│   ├── db.php
│   ├── helpers.php
│   ├── layout.php
│   └── whatsapp.php
├── public/
│   ├── assets/style.css
│   ├── debts.php
│   ├── index.php
│   ├── login.php
│   ├── logout.php
│   ├── plans.php
│   ├── subscribers.php
│   └── subscriptions.php
└── README.md
```

---

## تشغيل سريع للتجربة على السيرفر (بدون VirtualHost)

إذا أردت تجربة سريعة:

```bash
sudo ln -s /var/www/internet-subscribers/public /var/www/html/subs
```

ثم افتح:

`http://YOUR_PUBLIC_IP/subs/`

---

## الأمان (مهم)

- غيّر `admin_password`
- غيّر `cron_secret` وكلمة مرور MySQL
- لا تجعل DocumentRoot على جذر المشروع؛ استخدم مجلد `public`
- لاحقاً فعّل HTTPS (Let's Encrypt) إذا صار عندك دومين
- لا ترفع `config.php` على Git عام

---

إذا واجهت خطأ أثناء التشغيل، انسخ لي رسالة الخطأ من Apache أو من الصفحة وأصلّحها لك خطوة بخطوة.
