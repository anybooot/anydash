<h1 align="center">
  <br>
  ⚡ NexusHost Portal
  <br>
</h1>

<h3 align="center">A modern, single-file game server hosting portal powered by Pterodactyl</h3>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.0+">
  <img src="https://img.shields.io/badge/SQLite-3-003B57?style=for-the-badge&logo=sqlite&logoColor=white" alt="SQLite">
  <img src="https://img.shields.io/badge/License-MIT-22c55e?style=for-the-badge" alt="MIT License">
  <img src="https://img.shields.io/badge/Single%20File-Yes-3b82f6?style=for-the-badge" alt="Single File">
</p>

<p align="center">
  <a href="#-features">Features</a> •
  <a href="#-download--install">Download</a> •
  <a href="#-setup-guide">Setup</a> •
  <a href="#-admin-panel">Admin Panel</a> •
  <a href="#-themes">Themes</a> •
  <a href="#-license">License</a>
</p>

---

## 📖 About

**NexusHost Portal** is a fully-featured, **single-file** game server hosting portal that connects to your **Pterodactyl panel** via its API. It gives your users a beautiful dashboard where they can deploy free servers, manage their existing servers, enable 2FA, and more — all from one `index.php` file.

No frameworks. No composer. No npm. Just **PHP + SQLite**. Drop it on any host and go.

---

## ✨ Features

### 👤 User Dashboard

- **Secure authentication** — local accounts with bcrypt password hashing
- **Auto-sync with panel** — register with the same email as your panel account and it links automatically
- **Free server deployment** — one-click deploy with configurable resources
- **Renewal system** — servers expire, users renew them, cooldown between renewals
- **Two-Factor Authentication (2FA)** — TOTP support with QR code (Google Authenticator, Authy, 1Password)
- **Change password** — updates both the portal and panel accounts
- **Live quota bar** — shows per-user and global server limits with animated progress bars
- **Open console** — direct link to the panel's server console

### 🎛️ Admin Panel

- **Panel configuration** — URL, Application API key, Client API key
- **Sync nodes & eggs** — import all nodes and game types from the panel
- **Toggle nodes/eggs** — enable/disable which are available to users
- **Free plan resources** — set default RAM, Disk, CPU, Databases, Ports, Backups
- **Free server limits** — max per user + max total across host with live usage counter
- **Renewal config** — turn ON/OFF, set interval (1–120 days), bonus days per renew
- **User management** — make admin, reset password, ban/unban with reason, delete
- **All servers view** — see every server on the panel
- **Free servers view** — track every free server created from the dashboard
- **Activity logs** — every action logged with IP and timestamp
- **Branding** — site name, logo (favicon), Discord, Website, Terms, Privacy, Cookies links

### 🎨 Design

- **7 built-in themes** — Green, Blue, Purple, Orange, Red, Black, Light
- **Glassmorphism** — modern frosted-glass look with blur effects
- **Animated background** — smooth gradient orbs
- **Fully responsive** — works on desktop, tablet, and mobile
- **Custom toasts** — beautiful in-app notifications
- **Smooth modals** — animated open/close transitions

---

## 💾 Download & Install

### Option 1 — Download the file (recommended)

1. Click on **[`index.php`](./index.php)** in this repository
2. Click the **`Download raw file`** button (⬇️ icon, top right of the file view)
3. Upload the file to your PHP web host

### Option 2 — Copy the code directly

1. Open **[`index.php`](./index.php)**
2. Click **`Raw`** or **`Copy raw file`** (📋 icon)
3. Paste the code into a new file named `index.php`
4. Upload it to your PHP web host

### Option 3 — Clone the repo

    git clone https://github.com/anybooot/anydash.git

Then upload the folder to your host.

### Option 4 — Terminal (SSH)

    wget https://raw.githubusercontent.com/anybooot/anydash/main/index.php

---

## 🖥️ Requirements

Any host that supports **PHP 8.0 or newer**. That's it.

| Requirement | Minimum |
|---|---|
| **PHP** | 8.0+ |
| **Extensions** | `pdo_sqlite`, `curl`, `json`, `session` (all enabled by default on most hosts) |
| **Web server** | Apache, Nginx, LiteSpeed, or PHP's built-in server |
| **SSL** | Recommended (HTTPS) but not required |
| **Storage** | ~1 MB for the SQLite database |

**Tested on:**

- ✅ cPanel shared hosting
- ✅ Pterodactyl (as a separate server)
- ✅ Apache / Nginx
- ✅ PHP built-in server (`php -S localhost:8000`)
- ✅ Docker (`php:8.2-apache`)

---

## 🚀 Setup Guide

### Step 1 — Upload the file

Upload `index.php` to your host's public directory (`public_html`, `www`, `htdocs`, etc.).

**That's all you need for the file to work.**

### Step 2 — Open the portal

Go to `https://yourdomain.com/` in your browser.

The **first account you register becomes the admin** automatically.

### Step 3 — Get your Pterodactyl API keys

Log in to your Pterodactyl panel as an admin, then go to:

**Admin → Application API → Create New**

- Description: `ANYDASH Portal`
- Permissions: **check ALL boxes** (read + write for everything)
- Click **Create**
- **Copy the key immediately** (starts with `ptla_`) — it's shown only once

### Step 4 — Configure the portal

Log in to your portal with the admin account, then:

**Admin → Settings → Panel Connection**

- **Panel URL**: `https://panel.yourdomain.com` (no trailing `/`)
- **Application API Key**: paste the `ptla_...` key
- **Client API Key**: paste a `ptlc_...` key (optional, for advanced features)
- Click **Save Settings**

### Step 5 — Sync nodes & game types

**Admin → Nodes & Games** → click **Sync with Panel**

- All your nodes and eggs are now imported
- Toggle ON the ones you want available to users

### Step 6 — Configure the free plan

**Admin → Settings → Free Plan Resources**

- Set **RAM**, **Disk**, **CPU**
- Set **Databases**, **Ports**, **Backups**
- Set **Max per User** (how many servers each user can create)
- Set **Max Total** (global host limit)
- Configure the **renewal system** (ON/OFF, interval, bonus days)

### Step 7 — Branding (optional)

**Admin → Branding & Links**

- **Site Name** — your host's name
- **Logo URL** — will appear in the browser tab as favicon
- **Discord URL** — appears in top header
- **Website URL** — appears in top header
- **Terms / Privacy / Cookies URLs** — appear in the footer
- **Theme** — pick from 7 built-in themes

**Save** and you're done! 🎉

---

## 🎛️ Admin Panel

Once logged in as admin, you get access to a full admin center:

### ⚙️ Settings

- Panel connection (URL + API keys)
- Free plan resources (RAM, Disk, CPU, DBs, Ports, Backups)
- Free server limits (per-user + global) with live usage counter
- Default server description
- Renewal system configuration

### 🌐 Nodes & Games

- Sync all nodes and eggs from the panel
- Toggle which nodes are available for free servers
- Toggle which game types are available for free servers

### 👥 Users

- View all registered users
- **Make admin / remove admin** (toggle role)
- **Reset password** (set a new one manually)
- **Ban** with a custom reason
- **Unban** banned users
- **Delete** users permanently
- Search by username or email
- Pagination

### 🖥️ All Servers

- Full list of every server on the panel
- Shows node, owner, RAM, CPU, suspension status

### 🎁 Free Servers

- Every free server created from the dashboard
- Shows owner, expiry, last renewal date
- Tracks expired vs active

### 📋 Logs

- Every action logged: registration, login, server creation, renewals, bans, etc.
- Shows user, IP, details, timestamp
- Search and filter

---

## 🎨 Themes

Choose from **7 built-in themes** in **Admin → Branding & Links → Theme**:

| Theme | Description |
|---|---|
| 🟢 **Green** | Emerald mint (default) |
| 🔵 **Blue** | Deep ocean blue |
| 🟣 **Purple** | Royal violet |
| 🟠 **Orange** | Sunset amber |
| 🔴 **Red** | Crimson red |
| ⚫ **Black** | Pure dark gray |
| ⚪ **White** | Clean light mode |

Theme changes are applied instantly and persist for all users (it's a global setting).

---

## 🔐 Security

NexusHost Portal takes security seriously:

- **Bcrypt password hashing** — same algorithm used by Pterodactyl itself
- **SQLite with WAL mode** — safe concurrent reads/writes
- **HttpOnly cookies** — session cookies are not accessible via JavaScript
- **SameSite=Lax** — CSRF protection on modern browsers
- **Secure cookies** — automatically enabled when HTTPS is detected
- **Root admin protection** — the first user (id=1) cannot be banned, deleted, or demoted
- **No hardcoded credentials** — API keys are stored in the database, not in the code
- **Input validation** — username/email/password rules enforced server-side

### 🔒 Recommendations

- **Always use HTTPS** in production
- **Backup `anydash.db`** regularly — it contains all users and settings
- **Set restrictive permissions** on the SQLite file: `chmod 600 anydash.db`
- **Never commit the `.db` file** to GitHub
- Consider putting `index.php` behind **Cloudflare Access** for an extra layer of security

### ⚠️ Important about reCAPTCHA

This portal uses **local authentication** — it does **not** authenticate users through the panel's `/auth/login` endpoint. That means:

- ✅ Works even if your panel has **reCAPTCHA enabled** on login
- ✅ Works even if your panel has **2FA enforced** for admins
- ✅ No dependency on panel login pages

If your panel has reCAPTCHA on the panel login, **users can still log in normally to the panel separately**. This portal is a **separate authentication layer** that links to the panel via the Application API.

---

## 📁 File Structure

    nexushost-portal/
    ├── index.php        ← The entire portal (backend + frontend)
    ├── anydash.db     ← SQLite database (auto-created on first run)
    └── README.md        ← This file

The database is created automatically the first time you open `index.php`. You don't need to create it or run any SQL.

---

## ⚙️ Configuration Reference

All settings are stored in the SQLite database and editable from the admin panel:

### Panel Connection

| Setting | Default | Description |
|---|---|---|
| `ptero_url` | *(empty)* | Your Pterodactyl panel URL |
| `ptero_admin_key` | *(empty)* | Application API key (`ptla_...`) |
| `ptero_client_key` | *(empty)* | Client API key (`ptlc_...`) — optional |

### Free Plan Resources

| Setting | Default | Description |
|---|---|---|
| `default_ram` | `2048` | RAM in MB |
| `default_disk` | `5120` | Disk in MB |
| `default_cpu` | `100` | CPU percentage |
| `default_databases` | `1` | Max databases per server |
| `default_allocations` | `2` | Max ports per server |
| `default_backups` | `2` | Max backups per server |

### Free Server Limits

| Setting | Default | Description |
|---|---|---|
| `max_free_per_user` | `1` | Max free servers per user |
| `max_free_total` | `100` | Max free servers across the host |

### Renewal System

| Setting | Default | Description |
|---|---|---|
| `renew_enabled` | `1` | `1` = servers expire, `0` = never expire |
| `renew_days` | `7` | Days until expiry (1–120) |
| `renew_bonus_days` | `1` | Extra days added per renewal |

**Cooldown rule:** A user can renew every `renew_days / 2` days. For example, if the interval is 7 days, the cooldown is 3.5 days.

---

## 🛠️ Troubleshooting

### "Panel API not configured"

→ Go to **Admin → Settings** and enter your panel URL + Application API key.

### "You have a panel account but no portal account yet"

→ You already exist in Pterodactyl but haven't registered here. Register with the **same email** to link accounts.

### "Your account is not linked to the panel"

→ The panel sync failed during registration. Make sure your Application API key is correct and has **write permissions**.

### Free servers quota not updating

→ The quota counter updates on page load. Refresh the page.

### Server won't start after deploy

→ Check the panel for error logs. The egg's startup command and docker image must be valid.

### "No free ports available on this node"

→ Your panel's node has all allocations assigned. Free up some in the panel or add more.

### Sync fails with "cURL error"

→ Your web host may block outbound HTTPS. Contact support or use a host that allows it.

### SQLite database locked

→ WAL mode is enabled by default. If you still have issues, ensure the file has write permissions: `chmod 666 anydash.db`.


---

## 🤝 Contributing

Pull requests are welcome! If you find a bug or have a feature suggestion:

1. Open an **Issue** describing the problem/idea
2. Fork the repo and create a branch
3. Submit a **Pull Request** with a clear description

Please keep the **single-file philosophy** — all backend and frontend code goes in `index.php`.

---

## 📄 License

This project is licensed under the **MIT License** — you're free to use, modify, and distribute it, including for commercial purposes.

    MIT License

    Copyright (c) 2025 ANYDASH Portal

    Permission is hereby granted, free of charge, to any person obtaining a copy
    of this software and associated documentation files (the "Software"), to deal
    in the Software without restriction, including without limitation the rights
    to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
    copies of the Software, and to permit persons to whom the Software is
    furnished to do so, subject to the following conditions:

    The above copyright notice and this permission notice shall be included in all
    copies or substantial portions of the Software.

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
    IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
    FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
    AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
    LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
    OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
    SOFTWARE.

---

## ⚠️ Disclaimer

This project is **not affiliated with, endorsed by, or sponsored by Pterodactyl**. It's an independent portal that connects to a Pterodactyl panel via its public API.

You are responsible for complying with Pterodactyl's license and the terms of service of your hosting provider.

---

## 💬 Support

- 🐛 **Bug reports**: [SOON](https://anyboot.ct.ws/soon)
- 💡 **Feature requests**: [SOON](https://anyboot.ct.ws/soon)
- 💬 **Community**: Join our [SOON](https://anyboot.ct.ws/soon)

---

<p align="center">
  Made with ❤️ for the hosting community
  <br>
  <sub>If you like this project, consider giving it a ⭐</sub>
</p>
