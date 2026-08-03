-- منع تكرار اسم المشترك (الهاتف يبقى مسموح تكراره)
-- نفّذ بعد حل أي أسماء مكررة يدوياً
ALTER TABLE subscribers ADD UNIQUE KEY uq_name (name);
