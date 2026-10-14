-- Setlo database schema (MySQL / MariaDB)
-- Import in phpMyAdmin, or run: php database/setup.php

CREATE DATABASE IF NOT EXISTS setlo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE setlo;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS assistant_messages, purchase_events, purchases, community_translations, community_saves, community_comments, community_reactions, community_photos, community_posts, feedback, settlement_payment_proofs, user_activity, fx_rates, bill_fx_log, game_players, game_sessions, achievement_progress, user_achievements, user_group_members, user_groups, password_resets, login_attempts, notifications, settlement_events, credits, settlement_payments, settlements, item_assignments, receipt_items, receipt_photos, receipts, bill_payments, bill_members, bills, users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name       VARCHAR(100) NOT NULL,
  email           VARCHAR(190) NULL UNIQUE,       -- NULL for guests
  username        VARCHAR(20) NULL UNIQUE,        -- unique lowercase handle (a-z 0-9 _); signs in like the email does; NULL for guests
  password_hash   VARCHAR(255) NOT NULL,
  google_sub      VARCHAR(64) NULL UNIQUE,        -- Google account id when linked via "Continue with Google"
  pay_code        CHAR(12) NULL UNIQUE,           -- personal "Pay me" QR code (pages/pay.php?u=…); NULL for guests
  payment_method  ENUM('GCash','Maya','Bank Transfer','Cash') NOT NULL DEFAULT 'GCash',
  payment_account VARCHAR(60) NULL,               -- e.g. GCash number / account name, shown to people who owe you
  currency        CHAR(3) NOT NULL DEFAULT 'PHP', -- home currency (includes/currency.php CURRENCIES): new bills they pay settle in it
  avatar_color    CHAR(7) NOT NULL DEFAULT '#0d9488',
  avatar          VARCHAR(120) NULL,              -- profile picture: 'pfp/<file>' (assets/pfp) or 'up/<file>' (uploads/avatars); NULL = initials
  featured_achievement VARCHAR(30) NULL,          -- badge shown next to their name (user_achievements.code)
  username_changes TINYINT UNSIGNED NOT NULL DEFAULT 0, -- changes used since the last wait; 5 locks it
  username_locked_until DATETIME NULL,                  -- no username change until then (7 days after the 5th)
  bg_mode         ENUM('badges','receipts','plain','photo') NOT NULL DEFAULT 'badges', -- page backdrop: floating badges, floating receipts + chameleon, none, or their photo
  bg_image        VARCHAR(255) NULL,              -- their backdrop photo (uploads/backgrounds), kept while another mode is picked
  onboarded_at    DATETIME NULL,                  -- finished or skipped the first-visit dashboard tour; NULL = show it
  role            ENUM('user','admin','guest') NOT NULL DEFAULT 'user',  -- guest: name-only bill member, cannot sign in
  status          ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- A Bill is one shared food expense event.
-- draft: created, no items yet · active: items being reviewed/assigned
-- settling: settlements generated, items locked · closed: every settlement confirmed
CREATE TABLE bills (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(120) NOT NULL,          -- for an utang: what the money was for
  kind            ENUM('bill','loan') NOT NULL DEFAULT 'bill', -- loan: an utang between two people, no receipt (api/loans.php)
  loan_amount     DECIMAL(10,2) NULL,             -- loan: how much was lent
  change_payment_id INT UNSIGNED NULL,            -- loan: extra cash kept from this cash payment instead of giving change (includes/payments.php)
  group_id        INT UNSIGNED NULL,              -- saved group it was started from (user_groups), for the group's trail
  creator_id      INT UNSIGNED NOT NULL,
  payer_id        INT UNSIGNED NOT NULL,          -- who paid the restaurant; receives all settlements
  status          ENUM('draft','active','settling','closed') NOT NULL DEFAULT 'draft',
  tax             DECIMAL(10,2) NOT NULL DEFAULT 0,
  service_charge  DECIMAL(10,2) NOT NULL DEFAULT 0,
  discount        DECIMAL(10,2) NOT NULL DEFAULT 0,  -- discount printed on the receipt (Senior/PWD, promo), deducted from the total
  split_mode      ENUM('items','percent','game') NOT NULL DEFAULT 'items',  -- percent: each member pays bill_members.percent of the total; game: set by a Fun Mode game
  interest_rate   DECIMAL(5,2) NULL,              -- set by the creator at settling: % added to what's left after each partial payment
  round_total     TINYINT(1) NOT NULL DEFAULT 0,  -- the total and every share rounded to whole units (the payer keeps the difference)
  change_holder_id INT UNSIGNED NULL,             -- the payer(s) handed over more than the total: who physically got the change
  amount_tendered DECIMAL(10,2) NULL,             -- one payer: what was handed to the cashier (more than the total = change); NULL = exactly the total
  receipt_total   DECIMAL(10,2) NULL,             -- total printed on the receipt, for the match check
  receipt_image   VARCHAR(255) NULL,              -- legacy single photo; photos now live in receipt_photos
  ocr_raw         MEDIUMTEXT NULL,                -- text of the first receipt (its first line names the place in Stats)
  ocr_status      ENUM('none','ok','failed','skipped') NOT NULL DEFAULT 'none',
  invite_code     CHAR(12) NULL UNIQUE,           -- join-by-link code; NULL when the link is off
  -- Currencies (includes/currency.php). Settlements are in `currency`, agreed by the group: the payer's when the bill
  -- starts, changeable until settling. Receipt amounts stay in receipt_currency and are converted at the settlement rate.
  currency         CHAR(3) NOT NULL DEFAULT 'PHP',
  receipt_currency CHAR(3) NULL,                  -- NULL = same as currency
  fx_mode          ENUM('market','actual','manual') NOT NULL DEFAULT 'market',  -- which settlement rate is used
  fx_market_rate   DECIMAL(20,10) NULL,           -- estimate: units of currency per 1 receipt_currency, from fx_market_source
  fx_market_source VARCHAR(60) NULL,
  fx_market_at     DATETIME NULL,                 -- when the provider published that rate
  fx_actual_total  DECIMAL(12,2) NULL,            -- actual: what the payer was really charged, in currency
  fx_manual_rate   DECIMAL(20,10) NULL,           -- manual: a rate typed by the creator
  fx_fees          DECIMAL(10,2) NOT NULL DEFAULT 0, -- recorded separately (e.g. foreign-transaction fee), in currency, shared equally
  fx_fees_note     VARCHAR(80) NULL,
  fx_rounding      ENUM('exact','nearest','up') NOT NULL DEFAULT 'exact', -- how converted shares are rounded (the payer keeps the difference)
  fx_set_at        DATETIME NULL,                 -- when the actual amount / manual rate was last set
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  settling_at     DATETIME NULL,
  closed_at       DATETIME NULL,
  FOREIGN KEY (creator_id) REFERENCES users(id),
  FOREIGN KEY (payer_id)   REFERENCES users(id),
  FOREIGN KEY (change_holder_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX (status)
) ENGINE=InnoDB;

CREATE TABLE bill_members (
  bill_id   INT UNSIGNED NOT NULL,
  user_id   INT UNSIGNED NOT NULL,
  discount_type ENUM('none','senior','pwd') NOT NULL DEFAULT 'none',  -- receives the receipt discount first
  percent   DECIMAL(5,2) NULL,                    -- share of the whole bill when bills.split_mode = 'percent'
  game_weight TINYINT UNSIGNED NULL,              -- split_mode = 'game': 1 = shares the bill equally, 0 = pays nothing
  game_share  INT NULL,                           -- split_mode = 'game' (Mystery Card): their exact share in centavos
  view_token CHAR(24) NULL UNIQUE,                -- a guest member's private link (pages/guest.php); NULL = no link
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archived_at DATETIME NULL,                      -- this member put the closed bill away: hidden from their lists, kept for everyone else
  PRIMARY KEY (bill_id, user_id),
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Who paid the restaurant, when more than one person did. No rows = bills.payer_id paid the whole total.
CREATE TABLE bill_payments (
  bill_id  INT UNSIGNED NOT NULL,
  user_id  INT UNSIGNED NOT NULL,
  amount   DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (bill_id, user_id),
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Receipts scanned into a bill. A bill can have several, and one photo can hold several receipts.
-- Their tax / service charge / discount / total were added into the bill's when scanned (and are taken out on removal).
CREATE TABLE receipts (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bill_id         INT UNSIGNED NOT NULL,
  position        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  title           VARCHAR(60) NULL,               -- the user's name for it when a bill has several, e.g. "Lunch"
  store_name      VARCHAR(120) NULL,
  receipt_no      VARCHAR(60) NULL,               -- OR / invoice / transaction no., for the duplicate check
  txn_date        VARCHAR(40) NULL,               -- as read, YYYY-MM-DD HH:MM
  subtotal        DECIMAL(10,2) NULL,
  tax             DECIMAL(10,2) NOT NULL DEFAULT 0,
  service_charge  DECIMAL(10,2) NOT NULL DEFAULT 0,
  discount        DECIMAL(10,2) NOT NULL DEFAULT 0,
  total           DECIMAL(10,2) NULL,
  tendered        DECIMAL(10,2) NULL,             -- the cash / card amount printed as paid (NULL = no payment line); adds up into bills.amount_tendered
  currency        CHAR(3) NULL,                   -- the currency its amounts are in (as corrected on Review)
  currency_detected CHAR(3) NULL,                 -- what the scanner read, kept even after a correction; NULL = not printed
  currency_clues  VARCHAR(120) NULL,              -- why, e.g. "¥ sign, Japanese text"
  ocr_raw         MEDIUMTEXT NULL,
  ocr_status      ENUM('ok','failed') NOT NULL DEFAULT 'ok',
  dup_note        VARCHAR(200) NULL,              -- looks like the same transaction as another receipt
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Photos of a receipt (uploads/receipts): one, or several sections of a long receipt. Receipts read from the same
-- photo each get a row for it, so a file is deleted only when no row uses it any more.
CREATE TABLE receipt_photos (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_id      INT UNSIGNED NOT NULL,
  position        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  image           VARCHAR(255) NOT NULL,
  sha1            CHAR(40) NULL,                  -- exact copy check
  dhash           CHAR(64) NULL,                  -- perceptual hash (includes/uploads.php image_dhash) for near-identical photos
  FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE CASCADE,
  INDEX (sha1)
) ENGINE=InnoDB;

-- Line items extracted by OCR (or typed manually) and corrected on the Review screen.
CREATE TABLE receipt_items (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bill_id         INT UNSIGNED NOT NULL,
  receipt_id      INT UNSIGNED NULL,              -- NULL: typed in manually
  position        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  name            VARCHAR(120) NOT NULL,
  qty             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  unit_price      DECIMAL(10,2) NOT NULL,         -- what was paid per piece: already less any promo below
  promo           DECIMAL(10,2) NOT NULL DEFAULT 0, -- item promo printed under the line (e.g. -57.50), off the whole line
  source         ENUM('ocr','manual') NOT NULL DEFAULT 'manual',
  ocr_name        VARCHAR(120) NULL,              -- what OCR originally read, to measure correction rate
  printed_name    VARCHAR(120) NULL,              -- the text exactly as printed (ocr_name is the plain-English version)
  details         VARCHAR(255) NULL,              -- a meal set's contents, e.g. "Chicken, Rice, Iced Tea"
  needs_review    TINYINT(1) NOT NULL DEFAULT 0,  -- low-confidence read, must be confirmed on the Review screen
  suggestion      VARCHAR(120) NULL,              -- likely correct name for a misread item
  dup_note        VARCHAR(160) NULL,              -- same item and price on another receipt, or listed twice
  was_corrected   TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
  FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Which members share each item (split equally among them).
CREATE TABLE item_assignments (
  item_id  INT UNSIGNED NOT NULL,
  user_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (item_id, user_id),
  FOREIGN KEY (item_id) REFERENCES receipt_items(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Paid in one or more parts (settlement_payments). Status follows the parts:
-- pending (something left to pay) → awaiting (a part waits for the receiver) → settled (nothing left)
--                                 ↘ disputed (receiver rejected a part) → pending (sender resends)
CREATE TABLE settlements (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bill_id         INT UNSIGNED NOT NULL,
  from_user_id    INT UNSIGNED NOT NULL,
  to_user_id      INT UNSIGNED NOT NULL,
  currency        CHAR(3) NOT NULL DEFAULT 'PHP', -- all amounts below and its payments are in this (the bill's agreed currency)
  principal       DECIMAL(10,2) NULL,             -- what was owed when settling started (NULL on old rows: = amount)
  amount          DECIMAL(10,2) NOT NULL,         -- what is owed in all: principal + interest added so far
  paid_amount     DECIMAL(10,2) NOT NULL DEFAULT 0, -- confirmed payments so far
  -- Once settled, what it came to in the debtor's own currency (when different), at that day's market rate
  local_currency    CHAR(3) NULL,
  local_amount      DECIMAL(14,2) NULL,
  local_rate        DECIMAL(20,10) NULL,
  local_rate_source VARCHAR(60) NULL,
  local_rate_at     DATETIME NULL,
  interest_rate   DECIMAL(5,2) NULL,              -- % of what's left, added after each partial payment
  status          ENUM('pending','awaiting','settled','disputed') NOT NULL DEFAULT 'pending',
  settle_method   ENUM('online','transfer','cash','credit','mixed') NULL, -- how it was paid: online = PayMongo, transfer = ref/screenshot, cash = receiver settled in person
  paid_at         DATETIME NULL,
  confirmed_at    DATETIME NULL,
  disputed_at     DATETIME NULL,
  dispute_reason  VARCHAR(500) NULL,
  payment_ref     VARCHAR(60) NULL,               -- reference number the sender entered when marking paid
  proof_image     VARCHAR(255) NULL,              -- proof-of-payment screenshot (uploads/proofs)
  paymongo_session VARCHAR(80) NULL,              -- latest PayMongo checkout session opened for this settlement
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (bill_id)      REFERENCES bills(id) ON DELETE CASCADE,
  FOREIGN KEY (from_user_id) REFERENCES users(id),
  FOREIGN KEY (to_user_id)   REFERENCES users(id),
  INDEX (status)
) ENGINE=InnoDB;

-- Immutable audit trail of every settlement status change.
CREATE TABLE settlement_events (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  settlement_id  INT UNSIGNED NOT NULL,
  actor_id       INT UNSIGNED NULL,
  event          ENUM('created','marked_paid','confirmed','disputed','resent','nudged','interest','covered') NOT NULL,
  note           VARCHAR(500) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (settlement_id) REFERENCES settlements(id) ON DELETE CASCADE,
  FOREIGN KEY (actor_id)      REFERENCES users(id)
) ENGINE=InnoDB;

-- Each payment towards a settlement. paid_by is usually the debtor, or someone paying on their behalf.
-- started (online checkout opened, not paid yet) · awaiting (receiver to confirm) · confirmed · rejected
CREATE TABLE settlement_payments (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  settlement_id    INT UNSIGNED NOT NULL,
  paid_by          INT UNSIGNED NOT NULL,
  amount           DECIMAL(10,2) NOT NULL,
  method           ENUM('online','transfer','cash','credit') NOT NULL, -- credit: paid from an earlier cash overpayment
  online_via    VARCHAR(20) NULL,                 -- online: the PayMongo channel — card, gcash, maya (or grab_pay…)
  online_detail VARCHAR(40) NULL,                 -- online card: brand and last 4 digits, e.g. "Visa •4242"
  status           ENUM('started','awaiting','confirmed','rejected') NOT NULL DEFAULT 'awaiting',
  payment_ref      VARCHAR(60) NULL,
  proof_image      VARCHAR(255) NULL,             -- uploads/proofs
  paymongo_session VARCHAR(80) NULL,
  pay_back         TINYINT(1) NOT NULL DEFAULT 0, -- paid for someone else, who then owes paid_by (a new settlement on confirm)
  tendered         DECIMAL(10,2) NULL,            -- cash handed over, when more than this part
  change_given     DECIMAL(10,2) NULL,            -- ... and the receiver gave the extra back
  credit_kept      DECIMAL(10,2) NULL,            -- ... or kept it as credit (credits)
  reject_reason    VARCHAR(500) NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_at     DATETIME NULL,
  FOREIGN KEY (settlement_id) REFERENCES settlements(id) ON DELETE CASCADE,
  FOREIGN KEY (paid_by)       REFERENCES users(id),
  INDEX (status)
) ENGINE=InnoDB;

-- Legacy: extra cash kept by the receiver, before it became a change utang (bills.change_payment_id).
-- database/migrate.php turns any credit still left into one; nothing new is written here.
CREATE TABLE credits (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id           INT UNSIGNED NOT NULL,       -- whose money it is (overpaid)
  holder_id          INT UNSIGNED NOT NULL,       -- who kept it (received the cash)
  amount             DECIMAL(10,2) NOT NULL,
  remaining          DECIMAL(10,2) NOT NULL,
  source_payment_id  INT UNSIGNED NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id)  REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (holder_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (source_payment_id) REFERENCES settlement_payments(id) ON DELETE SET NULL,
  INDEX (owner_id, holder_id)
) ENGINE=InnoDB;

-- Saved groups of people who split often ("Barkada", "Dorm"): new bills start from one, and the group page
-- keeps one trail of its bills, payments and receipts.
CREATE TABLE user_groups (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60) NOT NULL,
  owner_id    INT UNSIGNED NOT NULL,
  fun_mode    TINYINT(1) NOT NULL DEFAULT 0,      -- on: after the receipt review, the group's bills offer a game (pages/game.php)
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE user_group_members (
  group_id   INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  joined_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (group_id, user_id),
  FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE bills ADD FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE SET NULL;

-- Market exchange rates as last fetched (includes/currency.php): units of each currency per 1 USD.
CREATE TABLE fx_rates (
  quote       CHAR(3) NOT NULL PRIMARY KEY,
  per_usd     DECIMAL(24,10) NOT NULL,
  source      VARCHAR(60) NOT NULL,
  rate_time   DATETIME NOT NULL,                  -- when the provider published it
  fetched_at  DATETIME NOT NULL
) ENGINE=InnoDB;

-- Every change to a bill's currencies or settlement rate, with the full settings at that moment. Append-only, so the
-- rate a bill was estimated, charged and settled at can always be traced; receipts and items are never converted.
CREATE TABLE bill_fx_log (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bill_id          INT UNSIGNED NOT NULL,
  actor_id         INT UNSIGNED NULL,
  receipt_currency CHAR(3) NOT NULL,
  currency         CHAR(3) NOT NULL,
  mode             ENUM('market','actual','manual') NOT NULL,
  market_rate      DECIMAL(20,10) NULL,
  market_source    VARCHAR(60) NULL,
  market_at        DATETIME NULL,
  actual_total     DECIMAL(12,2) NULL,
  manual_rate      DECIMAL(20,10) NULL,
  fees             DECIMAL(10,2) NOT NULL DEFAULT 0,
  fees_note        VARCHAR(80) NULL,
  rounding         ENUM('exact','nearest','up') NOT NULL,
  note             VARCHAR(255) NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (bill_id)  REFERENCES bills(id) ON DELETE CASCADE,
  FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX (bill_id)
) ENGINE=InnoDB;

-- Failed sign-in attempts, for throttling password guessing (see api/auth.php).
CREATE TABLE login_attempts (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL,
  ip            VARCHAR(45) NOT NULL,
  attempted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (email, ip, attempted_at)
) ENGINE=InnoDB;

-- Days each user used the app (see record_activity() in includes/bootstrap.php): one row per user per day.
CREATE TABLE user_activity (
  user_id   INT UNSIGNED NOT NULL,
  day       DATE NOT NULL,
  first_at  DATETIME NOT NULL,                    -- first request that day
  last_at   DATETIME NOT NULL,                    -- latest request that day (to within ~5 minutes)
  PRIMARY KEY (user_id, day),
  INDEX (day),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- "Forgot password?" codes emailed to users (see api/auth.php). Only a hash of the 6-digit code is stored.
CREATE TABLE password_resets (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  code_hash   CHAR(64) NOT NULL,
  expires_at  DATETIME NOT NULL,
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ip          VARCHAR(45) NOT NULL DEFAULT '',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (user_id)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  type        ENUM('added','paid','confirmed','disputed','resent','settling','closed','nudge','admin','achievement','game','community') NOT NULL,
  message     VARCHAR(300) NOT NULL,
  link        VARCHAR(200) NULL,
  is_read     TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (user_id, is_read)
) ENGINE=InnoDB;

-- One-time achievements (includes/achievements.php). Earned once, kept for good; hidden ones aren't shown to others.
CREATE TABLE user_achievements (
  user_id    INT UNSIGNED NOT NULL,
  code       VARCHAR(30) NOT NULL,
  bill_id    INT UNSIGNED NULL,                   -- the bill it was earned on
  earned_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  seen_at    DATETIME NULL,                       -- NULL: its pop-up hasn't been shown yet (shown on the dashboard)
  hidden     TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, code),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Progress towards badges that take several occurrences (ACHIEVEMENT_TARGETS): one row per occurrence.
CREATE TABLE achievement_progress (
  user_id    INT UNSIGNED NOT NULL,
  code       VARCHAR(30) NOT NULL,
  ref_id     INT UNSIGNED NOT NULL,               -- the bill or settlement it happened on, so it counts once
  amount     INT UNSIGNED NOT NULL DEFAULT 1,     -- what it adds (big_spender: share in centavos, in pesos)
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, code, ref_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Fun Mode games (includes/games.php). The server holds the true state; screens only draw it and poll for changes.
-- state (JSON): the race challenge / target / shuffled deck / knock-out order and, once done, the result. version goes up on every change.
CREATE TABLE game_sessions (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bill_id      INT UNSIGNED NOT NULL,
  host_id      INT UNSIGNED NOT NULL,
  game         ENUM('race','closest','roulette','cards','balls','skip') NOT NULL,   -- skip: the creator chose to split normally
  mode         ENUM('screen','live') NOT NULL DEFAULT 'screen',    -- screen: one phone passed around; live: everyone's phone
  status       ENUM('lobby','playing','choosing','done','cancelled') NOT NULL DEFAULT 'lobby',
  state        TEXT NULL,
  started_at   DATETIME(3) NULL,
  deadline_at  DATETIME(3) NULL,
  version      INT UNSIGNED NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
  FOREIGN KEY (host_id) REFERENCES users(id),
  INDEX (bill_id, status)
) ENGINE=InnoDB;

CREATE TABLE game_players (
  session_id   INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  joined_at    DATETIME NULL,                     -- live mode: when they opened the game on their phone
  answer_cents INT NULL,                          -- race: their total · closest: their basket's total
  basket       TEXT NULL,                         -- closest: JSON {receipt item id: qty}
  turn_started_at DATETIME(3) NULL,               -- race: when their clock started (live: all at once; one phone: their Go)
  answered_at  DATETIME(3) NULL,                  -- when they locked in (one answer each)
  card_slot    TINYINT UNSIGNED NULL,             -- which face-down card they picked (unique per game)
  target_id    INT UNSIGNED NULL,                 -- who their 🔄 / 🔀 / 💥 card picked
  PRIMARY KEY (session_id, user_id),
  UNIQUE (session_id, card_slot),
  FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Ratings and feedback on the landing page (api/feedback.php): one per user, shown publicly once an admin approves it.
CREATE TABLE feedback (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL UNIQUE,            -- NULL for a review left without an account
  guest_key  CHAR(64) NULL UNIQUE,                -- SHA-256 of that visitor's cookie token (one review per browser)
  guest_name VARCHAR(40) NULL,
  ip_hash    CHAR(64) NULL,                       -- HMAC of the IP, only to cap anonymous reviews per day
  rating     TINYINT UNSIGNED NOT NULL,           -- 1 to 5
  comment    VARCHAR(500) NULL,
  status     ENUM('pending','approved','hidden') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (status, created_at),
  INDEX (ip_hash, created_at)
) ENGINE=InnoDB;

-- Extra proof-of-payment photos for one payment (the first photo stays in settlement_payments.proof_image).
CREATE TABLE settlement_payment_proofs (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id INT UNSIGNED NOT NULL,
  position   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  image      VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (payment_id) REFERENCES settlement_payments(id) ON DELETE CASCADE,
  INDEX (payment_id, position)
) ENGINE=InnoDB;

-- Community feed (api/community.php): posts, their photos, reactions, comments and per-user saves.
CREATE TABLE community_posts (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,
  body           VARCHAR(2000) NULL,
  location       VARCHAR(160) NULL,
  lat            DECIMAL(9,6) NULL,               -- set when the location was picked from the place suggestions
  lng            DECIMAL(9,6) NULL,
  price          DECIMAL(10,2) NULL,
  audience       ENUM('public','only_me') NOT NULL DEFAULT 'public',
  shared_post_id INT UNSIGNED NULL,               -- a re-share points at the post it shares
  is_share       TINYINT(1) NOT NULL DEFAULT 0,   -- stays 1 if the original is later deleted
  edited_at      DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (shared_post_id) REFERENCES community_posts(id) ON DELETE SET NULL,
  INDEX (audience, id),
  INDEX (user_id, id)
) ENGINE=InnoDB;

CREATE TABLE community_photos (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id   INT UNSIGNED NOT NULL,
  position  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  image     VARCHAR(255) NOT NULL,
  FOREIGN KEY (post_id) REFERENCES community_posts(id) ON DELETE CASCADE,
  INDEX (post_id, position)
) ENGINE=InnoDB;

CREATE TABLE community_reactions (
  post_id    INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  type       ENUM('like','love','haha','wow','sad','angry') NOT NULL DEFAULT 'like',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, user_id),
  FOREIGN KEY (post_id) REFERENCES community_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE community_comments (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id    INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  body       VARCHAR(1000) NOT NULL,
  parent_id  INT UNSIGNED NULL,                 -- NULL = a comment on the post; set = a reply to that comment (one level)
  edited_at  DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (post_id) REFERENCES community_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (parent_id) REFERENCES community_comments(id) ON DELETE CASCADE,
  INDEX (post_id, parent_id, id)
) ENGINE=InnoDB;

CREATE TABLE community_saves (
  user_id    INT UNSIGNED NOT NULL,
  post_id    INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, post_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (post_id) REFERENCES community_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Gemini translations of community comments, one per comment and language, shared by everyone who asks.
CREATE TABLE community_translations (
  comment_id      INT UNSIGNED NOT NULL,
  lang            VARCHAR(12) NOT NULL,
  translation     VARCHAR(2000) NOT NULL,
  romanization    VARCHAR(2000) NULL,              -- the original written in Latin letters (empty for Latin-script text)
  source_language VARCHAR(60) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (comment_id, lang),
  FOREIGN KEY (comment_id) REFERENCES community_comments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- A person's own purchases (pages/purchases.php), outside any bill
CREATE TABLE purchases (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,
  title          VARCHAR(80) NOT NULL,
  category       VARCHAR(30) NOT NULL,
  status         ENUM('paid','unpaid') NOT NULL DEFAULT 'paid', -- unpaid: still to pay (a bill due), settled later
  amount         DECIMAL(12,2) NOT NULL,
  purchased_on   DATE NOT NULL,
  due_on         DATE NULL,
  paid_on        DATE NULL,
  merchant       VARCHAR(60) NULL,
  receipt_no     VARCHAR(40) NULL,
  payment_method VARCHAR(30) NULL,
  note           VARCHAR(300) NULL,
  receipt_image  VARCHAR(80) NULL,               -- the scanned receipt photo (uploads/receipts)
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (user_id, purchased_on)
) ENGINE=InnoDB;

-- The journal of those purchases: added, scanned, edited, settled, reopened, deleted
CREATE TABLE purchase_events (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  purchase_id INT UNSIGNED NULL,                  -- no foreign key: the entry outlives a deleted purchase
  event       ENUM('added','scanned','edited','settled','reopened','deleted') NOT NULL,
  title       VARCHAR(80) NOT NULL,
  amount      DECIMAL(12,2) NOT NULL,
  detail      VARCHAR(120) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (user_id, created_at)
) ENGINE=InnoDB;

-- Setlo AI chat (api/assistant.php): each user's recent conversation with the assistant
CREATE TABLE assistant_messages (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  role       ENUM('user','model') NOT NULL,
  content    TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (user_id, id),
  INDEX (user_id, created_at)
) ENGINE=InnoDB;
