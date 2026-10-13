# AmarDayal Clinic — Unified Website + Clinic Management System

This version merges the public AmarDayal Clinic website with the clinic management workflow from the Python desktop application into one PHP/MySQL web application.

## Features
- Modern responsive public website
- Patient registration/login
- Online appointment booking
- Patient portal and visit history
- Staff roles: Admin, Reception, Doctor
- Reception + OPD registration
- Doctor consultation, diagnosis and prescription
- Billing and invoice records
- Patient directory/search
- Revenue reports
- Audit logs
- Staff account management
- Website content management
- Contact messages
- MySQL database

## Installation
1. Create a MySQL database by importing `database/cms.sql` in phpMyAdmin.
2. Put the project folder inside XAMPP `htdocs` (for example `htdocs/AmarDayal_Merged`).
3. Start Apache and MySQL.
4. If your MySQL credentials are not root/no-password, set `DB_HOST`, `DB_USER`, `DB_PASSWORD`, and `DB_NAME` environment variables or edit `config.php`.
5. Open `http://localhost/AmarDayal_Merged/`.

## Default staff accounts
The SQL file creates the tables but does not insert plaintext staff credentials. Run the following PHP once after importing the database, or create the first user manually using PHP `password_hash()`.

For convenience, `install_admin.php` is included. Open it once in the browser and then delete it.

Default accounts:
- admin / admin123 — Admin
- doctor / doctor123 — Doctor
- reception / reception123 — Reception

Change these passwords immediately for real use.
