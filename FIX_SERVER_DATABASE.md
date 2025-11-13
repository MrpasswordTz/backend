# Commands to Run on Your Server (SSH: root@104.168.4.143)

## Step 1: Start MariaDB

```bash
# SSH into your server
ssh root@104.168.4.143
# Password: mrcyberog@#$

# Navigate to project
cd /home/projects/backend

# Start MariaDB
systemctl start mariadb
systemctl enable mariadb

# Check status
systemctl status mariadb
```

## Step 2: Verify Database Connection

```bash
# Test MySQL connection
mysql -u elliot -pfsociety -h 127.0.0.1 -e "SELECT 1;"

# Check if database exists
mysql -u elliot -pfsociety -h 127.0.0.1 -e "SHOW DATABASES LIKE 'mdukuzi_ai';"

# If database doesn't exist, create it
mysql -u elliot -pfsociety -h 127.0.0.1 -e "CREATE DATABASE IF NOT EXISTS mdukuzi_ai;"
```

## Step 3: Fix .env File (if needed)

```bash
cd /home/projects/backend

# Fix APP_URL typo (remove extra slash)
sed -i 's|APP_URL=http://104.168.4.143/:8000|APP_URL=http://104.168.4.143:8000|' .env

# Verify .env has correct database settings
grep "^DB_" .env
# Should show:
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=mdukuzi_ai
# DB_USERNAME=elliot
# DB_PASSWORD=fsociety
```

## Step 4: Clear Cache and Run Migrations

```bash
cd /home/projects/backend

# Clear config cache
php artisan config:clear
php artisan cache:clear

# Run migrations
php artisan migrate --force
```

## Step 5: Create Admin User

```bash
cd /home/projects/backend

# Use tinker to create admin user
php artisan tinker
```

Then in tinker, paste:
```php
\App\Models\User::create([
    'username' => 'admin',
    'name' => 'Admin User',
    'email' => 'admin@mdukuzi.ai',
    'password' => \Hash::make('password'),
    'role' => 'admin',
]);
exit
```

## Step 6: Test Login

```bash
# Test login endpoint
curl -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@mdukuzi.ai","password":"password"}'
```

You should get a response with `"success": true` and a token.

## Step 7: Verify Backend is Running

```bash
# Check if Laravel server is running
ps aux | grep "artisan serve"

# If not running, start it (make sure it's on 0.0.0.0, not localhost)
php artisan serve --host=0.0.0.0 --port=8000
```

## Quick One-Liner to Fix Everything

```bash
cd /home/projects/backend && \
systemctl start mariadb && \
systemctl enable mariadb && \
sed -i 's|APP_URL=http://104.168.4.143/:8000|APP_URL=http://104.168.4.143:8000|' .env && \
mysql -u elliot -pfsociety -h 127.0.0.1 -e "CREATE DATABASE IF NOT EXISTS mdukuzi_ai;" && \
php artisan config:clear && \
php artisan migrate --force
```

Then create the admin user with tinker as shown in Step 5.

