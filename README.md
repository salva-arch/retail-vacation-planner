# 📅 Retail Vacation Planner (Enterprise Edition)

A high-performance, **single-page web application (SPA)** designed for workforce management in retail environments.

![Status](https://img.shields.io/badge/Status-Production_Ready-green) ![Stack](https://img.shields.io/badge/Stack-PHP_%7C_Vanilla_JS-blue) ![Feature](https://img.shields.io/badge/Feature-Concurrency_Control-orange)

## 🚀 The Challenge
Retail stores face complex scheduling challenges:
* **6-Day Workweek:** Saturdays count as vacation days.
* **Keyholder Logic:** At least 2 managers must always be present.
* **Capacity Limits:** Maximum of 3 employees absent simultaneously.

## 💡 The Solution
This tool automates the entire approval process, enforcing business rules in real-time before a request is even submitted.

### Key Features
* **🛡️ Atomic Locking:** Prevents race conditions when multiple users book simultaneously.
* **⚡ Zero-Reload AJAX:** Instant feedback interface for a native app feel.
* **📋 Smart Waitlist:** Automatically suggests a waitlist spot if a period is overbooked.
* **🔐 Role-Based Access Control (RBAC):** Strict separation between operational users and IT administrators.
* **📧 Automated Reporting:** Weekly CSV backups and status reports via SMTP.

## 🛠️ Technical Architecture
Built with a focus on **Zero-Dependency** and **Long-Term Maintainability**.

* **Backend:** PHP 8+ (Integrated via WordPress or standalone)
* **Frontend:** Vanilla JavaScript (ES6+) with Fetch API
* **Data:** JSON-Storage (Migration path to SQL prepared)
* **Security:** Nonce Verification, Input Sanitization, Session Hardening

## 📦 Installation
1.  Clone this repository.
2.  Deploy `urlaubsplaner.php` to your server.
3.  Configure `RB_ADMIN_EMAIL` and `RB_YEAR` in the config section.
4.  Ready to go.

---
*Designed & Developed by Salva - 2025*
