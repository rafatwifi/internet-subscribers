-- السماح بنفس رقم الهاتف لأكثر من مشترك
ALTER TABLE subscribers DROP INDEX uq_phone;
