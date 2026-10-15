# Putting Setlo online for free on InfinityFree (no card, always on)

InfinityFree is free PHP + MySQL hosting with free HTTPS and no credit card. It has **no SSH or terminal**, so this setup uses a web installer (`install.php`) and updates by FTP. A GitHub Action can do the FTP part for you: you push to GitHub and the site updates itself.

> I wrote this from InfinityFree's public descriptions and haven't used your account, so a few menu names may differ slightly. Two things are unconfirmed and `install.php` tests them for you: whether PHP on the free plan can call Gemini, PayMongo and Google, and whether the database allows Setlo's automatic updates (it needs MariaDB-style SQL). If calls to Gemini are blocked, receipt scanning can't work there; use `DEPLOY.md` (Google Cloud) instead.
> Free-host limits: the account can be suspended if it breaks their fair-use rules, hits are capped (about 50,000 a day), and there is no cron, so the scheduled reminder script (`tools/send-reminders.php`) won't run.

## 1. Create the account and site
1. Sign up at https://www.infinityfree.com and confirm your email.
2. **Create Account** → pick a free subdomain (for example `mysetlo.rf.gd`) and a password. Wait for the "account ready" email (a few minutes).
3. Open the **control panel** for the account. Note your **FTP details**: host (often `ftpupload.net`), username (like `if0_12345678`) and the account password.
4. **HTTPS:** in the panel, find **SSL Certificates** (or "Free SSL") and request a free certificate for your address. Wait until the address opens with a padlock. The camera, the install-as-app button and the service worker all need HTTPS.

## 2. Create the database
In the panel: **MySQL Databases** → create a database named `setlo`. Note these four values:
- **Host** (like `sql123.infinityfree.com`)
- **Database name** (like `if0_12345678_setlo`)
- **User** (like `if0_12345678`)
- **Password** (your account password)

## 3. Put Setlo on the server
**Option A (recommended, also gives you easy updates later): GitHub Action**
1. In your GitHub repository: **Settings → Secrets and variables → Actions → New repository secret**. Add `FTP_SERVER`, `FTP_USERNAME` and `FTP_PASSWORD` with the FTP details from step 1. (You need admin access to the repository, so `vanmwa`, or the owner, has to add these, or you use your own copy of the repository.)
2. Open the **Actions** tab → **Deploy to InfinityFree** → **Run workflow**. The first upload can take several minutes.

**Option B (manual): FTP program**
Use FileZilla with the FTP details. Upload everything inside the `setlo-splitmate` folder into the server's **`htdocs`** folder, except `tools`, `src`, `.git`, `*.md` and your own `config/local.php`.

## 4. Create your settings file on the server
Your keys never go in GitHub. In the InfinityFree panel open **Online File Manager** → `htdocs/config/` → create a new file `local.php` with this content (fill in your own values):

```php
<?php
return [
    'DB_HOST'             => 'sql123.infinityfree.com',
    'DB_NAME'             => 'if0_12345678_setlo',
    'DB_USER'             => 'if0_12345678',
    'DB_PASS'             => 'your account password',
    'APP_URL'             => 'https://mysetlo.rf.gd',
    'INSTALL_KEY'         => 'make-up-a-long-random-secret-24-or-more-characters',
    'GEMINI_API_KEY'      => '',   // receipt scanning
    'GROQ_API_KEY'        => '',   // Setlo Buddy chat
    'GOOGLE_CLIENT_ID'    => '',   // Continue with Google
    'PAYMONGO_SECRET_KEY' => '',   // Pay online (use a test key, sk_test_...)
];
```
Copy the API keys from your PC's `config/local.php`. The password-reset email (SMTP) is not included because free hosts usually block that port.

## 5. Run the installer
1. Open `https://mysetlo.rf.gd/install.php?key=YOUR_INSTALL_KEY` (the key from step 4).
2. Read the **System check**. Anything marked *Problem* must be fixed first. A "blocked" line for Gemini or PayMongo means that feature won't work on this host.
3. If the database line is green, fill in your **admin email and a password** (10+ characters, with a letter and a number; the default `admin12345` is refused) and press **Install Setlo**.
4. When it says "Installed", **delete `install.php`** from the server's `htdocs` (File Manager). It locks itself too, so a leftover copy can't reinstall, but remove it anyway.

If you see "This database already has Setlo tables", nothing is changed. To start over, drop all the tables in phpMyAdmin first.

## 6. Check it works
- Open `https://mysetlo.rf.gd/pages/login`, sign up as a normal user, make a bill and scan a receipt.
- Open `…/pages/admin-login` and sign in with the admin account from step 5.
- Open `…/pages/login` **without** `.php` in the address. If it works, the `.htaccess` rules are running.

## 7. Google sign-in and PayMongo
- Google sign-in: in Google Cloud Console → Credentials → your OAuth client, add `https://mysetlo.rf.gd` to **Authorized JavaScript origins**.
- PayMongo: use your test key, and keep it in `config/local.php` only.

## 8. Use it on your phone
Open `https://mysetlo.rf.gd/pages/login`.
- **Android (Chrome):** menu ⋮ → **Install app**.
- **iPhone (Safari):** Share → **Add to Home Screen**.

## Updating later (easy)
1. On your PC, change something, then commit and push to GitHub (`main` branch).
2. The **Deploy to InfinityFree** action uploads the changes by itself (watch it in the Actions tab; it takes a minute or two).
3. Setlo updates its own database the first time someone opens the site after an update that changes the tables. Nothing else to do.

Your `config/local.php` and everyone's uploaded photos are never touched by an update. If you don't use the Action, repeat Option B and upload only the files that changed.

## Backups
In the panel, open **phpMyAdmin** → select your database → **Export** and save the `.sql` file now and then. Receipt photos are in `htdocs/uploads/`; download that folder over FTP.

## If something goes wrong
| Problem | Fix |
|---|---|
| `install.php` says "Not ready yet" | `INSTALL_KEY` is missing or shorter than 16 characters in `config/local.php`. |
| "Wrong or missing key" | Use the exact `INSTALL_KEY` from `config/local.php`. |
| Database connection problem | Re-check host, database name, user and password from the panel (step 2); the host is *not* `localhost`. |
| Receipt scanning does nothing | The Gemini key is missing, or the installer showed Gemini as blocked: use `DEPLOY.md` instead. |
| Pages show 404, or only open with `.php` | The `.htaccess` file didn't upload. Make sure hidden files are uploaded (FileZilla: Server → Force showing hidden files). |
| White page / "Something went wrong" after an update | Add `'AUTO_MIGRATE' => '0',` to `config/local.php`, then export your data and ask for help: the host's database may not support Setlo's automatic update. |
| The Action fails on "FTP" | Check the three GitHub secrets, and that the server folder is `htdocs`. Try again; free FTP servers can be slow. |
| The site is suspended | Free hosts have fair-use limits. Read the email from InfinityFree; consider `DEPLOY.md`. |
