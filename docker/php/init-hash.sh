#!/bin/sh
# รอให้ MySQL พร้อมก่อน
echo "⏳ Waiting for MySQL to be ready..."
until php -r "
new PDO('mysql:host=law_management_db;dbname=law_system', 'law_user', 'law_password');
" 2>/dev/null; do
  sleep 2
done

echo "✅ MySQL ready. Setting password hash..."

php -r "
\$pdo = new PDO('mysql:host=law_management_db;dbname=law_system', 'law_user', 'law_password');

// Check if hash already set
\$row = \$pdo->query(\"SELECT password_hash FROM users WHERE email='admin@lawfirm.com'\")->fetch();
if (\$row && \$row['password_hash'] === 'PENDING_HASH') {
    \$hash = password_hash('admin1234', PASSWORD_BCRYPT);
    \$pdo->prepare(\"UPDATE users SET password_hash=? WHERE email='admin@lawfirm.com'\")->execute([\$hash]);
    echo '✅ Admin password hash set successfully' . PHP_EOL;
} else {
    echo '✅ Password hash already set, skipping.' . PHP_EOL;
}
"

# ตั้ง permission uploads folder
mkdir -p /var/www/html/uploads/contracts
chmod -R 775 /var/www/html/uploads
chown -R www-data:www-data /var/www/html/uploads
echo "✅ Upload directory permissions set"