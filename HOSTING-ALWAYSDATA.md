# Putting Setlo online for free on alwaysdata (24/7, update with one command)

alwaysdata's free plan gives you PHP, a database, free HTTPS and SSH, with no credit card and no time limit. Your phone opens `https://YOURNAME.alwaysdata.net` and installs it like an app. Updating later is: push to GitHub on your PC, then run one command on the server.

> **Check these two things as you go.** I wrote this from alwaysdata's public descriptions and haven't used your account, so a few menu names may differ slightly, and I couldn't confirm that the free plan lets PHP call outside services (Gemini, PayMongo, Google). Step 9 tests that. If it's blocked, use `DEPLOY.md` (Google Cloud) instead.
> The free plan is for personal projects and uses the `*.alwaysdata.net` address.

Replace `YOURNAME` below with your alwaysdata account name.

## 1. Sign up
Create a free account at https://www.alwaysdata.com and confirm your email. Pick the **free** plan ("Public Cloud, free 100 MB / 1 GB").

## 2. Make the database
In the alwaysdata admin panel: **Databases → MySQL → Add a database**. Name it `setlo` (it becomes `YOURNAME_setlo`) and add a user with a strong password.
- If it asks for a server type, choose **MariaDB** (not MySQL 8). Setlo's `migrate.php` uses MariaDB syntax.
- Note the host, normally `mysql-YOURNAME.alwaysdata.net`.

## 3. Turn on SSH
**Remote access → SSH**: enable it for your user and set a password. You can now log in from your PC's terminal:
```
ssh YOURNAME@ssh-YOURNAME.alwaysdata.net
```

## 4. Download Setlo onto the server
```
git clone https://github.com/vanmwa/setlo-splitmate.git ~/www/setlo
```
Private repository? Use a GitHub personal access token as the password when it asks, or make the repository public. It contains no passwords or API keys (those live in `config/local.php`, which git ignores).

## 5. Point the website at it
**Web → Sites → Add a site** (or edit the default one):
- **Addresses:** `YOURNAME.alwaysdata.net`
- **Type:** PHP, version 8.2 or newer
- **Root directory:** `/www/setlo/`
- Save. HTTPS is turned on automatically.

## 6. Your keys and settings (not on GitHub)
Create the settings file on the server:
```
nano ~/www/setlo/config/local.php
```
Paste this, fill in your values, then save (Ctrl+O, Enter, Ctrl+X):
```php
<?php
return [
    'DB_HOST'             => 'mysql-YOURNAME.alwaysdata.net',
    'DB_NAME'             => 'YOURNAME_setlo',
    'DB_USER'             => 'YOURNAME_setlo',
    'DB_PASS'             => 'the database password from step 2',
    'APP_URL'             => 'https://YOURNAME.alwaysdata.net',
    'GEMINI_API_KEY'      => '',   // receipt scanning
    'GROQ_API_KEY'        => '',   // Setlo Buddy chat
    'GOOGLE_CLIENT_ID'    => '',   // Continue with Google
    'PAYMONGO_SECRET_KEY' => '',   // Pay online (use a test key sk_test_...)
];
```
Copy the values from your PC's `config/local.php`. The password-reset email (SMTP) is left out because free hosts usually block that port.

## 7. Create the tables and your admin account
```
cd ~/www/setlo
php database/setup.php --hosted
```
It asks you to choose an **admin password** (10+ characters, with a letter and a number). The default `admin12345` is refused on purpose. Run `setup.php` only once: running it again wipes everything.
Then:
```
php database/migrate.php
```
(If it stops with an SQL error because the server is MySQL rather than MariaDB, that's fine for a first install; the tables are already complete.)

## 8. Upload sizes
In the site's settings (**Web → Sites → your site → PHP settings**), add:
```
upload_max_filesize = 8M
post_max_size = 10M
max_execution_time = 200
```

## 9. Test that PHP can reach the outside services
```
curl -sS -o /dev/null -w "Gemini:  %{http_code}\n" https://generativelanguage.googleapis.com/
curl -sS -o /dev/null -w "PayMongo: %{http_code}\n" https://api.paymongo.com/
curl -sS -o /dev/null -w "Google:  %{http_code}\n" https://www.googleapis.com/
```
Any number (even 403 or 404) means the connection works. "Could not resolve host" or a timeout means that service is blocked on this plan.
Then open the site, make a bill and scan a receipt to be sure.

## 10. Google sign-in
In Google Cloud Console → Credentials → your OAuth client, add `https://YOURNAME.alwaysdata.net` under **Authorized JavaScript origins**.

## 11. Use it on your phone
Open `https://YOURNAME.alwaysdata.net/pages/login`.
- **Android (Chrome):** menu ⋮ → **Install app**.
- **iPhone (Safari):** Share → **Add to Home Screen**.

## Updating later
On your PC, commit and push your changes to GitHub. Then on the server:
```
ssh YOURNAME@ssh-YOURNAME.alwaysdata.net
bash ~/www/setlo/tools/update.sh
```
It pulls the new code and brings the database up to date. It never wipes data and never touches your uploads or `config/local.php`. If it says files on the server clash with GitHub, someone edited a file by hand on the server: run `git status`, undo with `git checkout -- <file>`, and run the update again.

## Backups (do this now and then)
```
mysqldump -h mysql-YOURNAME.alwaysdata.net -u YOURNAME_setlo -p YOURNAME_setlo > ~/setlo-backup.sql
```
The receipt photos are in `~/www/setlo/uploads/`.

## If something goes wrong
| Problem | Fix |
|---|---|
| White page or "500" | Check the error log in the admin panel (**Web → Sites → logs**), and that PHP is 8.2 or newer. |
| Pages show "404" or won't open without `.php` | The `.htaccess` rules aren't running. Make sure the site type is **PHP (Apache)** and `Root directory` is `/www/setlo/`. |
| Photos won't upload | The PHP settings in step 8 aren't applied, or the free storage is full. |
| Receipt scanning says it isn't set up, or step 9 fails | The Gemini key is missing in `config/local.php`, or this plan blocks outside calls. Use `DEPLOY.md` (Google Cloud) instead. |
| "Continue with Google" fails | The site address isn't in Google's authorized origins (step 10). |
