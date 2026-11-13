# Bella Vista Oasis Spa — Wellness Spa & Appointment Management System

A PHP + MySQL academic demo website designed around the supplied project brief and rubric.

## Included features
- Responsive spa landing page, company profile/history, service menu, and policy section.
- Appointment request form and booking confirmation reference.
- Server-side validation, price calculation, Monday closure rule, advance booking rule, fixed time slots, and demo slot capacity.
- MySQL persistence for appointments and services.
- Admin login, booking status management, dashboard summary, and activate/deactivate/add service tools.
- Prepared SQL statements and password hashing for administrator login.

## Requirements
- Windows computer with XAMPP (Apache and MySQL).
- Web browser.

## Step-by-step installation
1. Install and open XAMPP Control Panel.
2. Start **Apache** and **MySQL**.
3. Copy the whole `bella_vista_oasis_spa` folder into `C:\xampp\htdocs\`.
   The expected path is `C:\xampp\htdocs\bella_vista_oasis_spa\index.php`.
4. Open `http://localhost/phpmyadmin`.
5. Choose **Import**, select the included `database.sql`, then click **Import/Go**. This creates the `bella_vista_spa` database and sample services.
6. Open `http://localhost/bella_vista_oasis_spa/setup_admin.php` once. It creates the demo admin account.
7. **Delete `setup_admin.php` after it succeeds.** Do not leave a setup script on a deployed website.
8. Open the public website at `http://localhost/bella_vista_oasis_spa/`.
9. Open the admin portal at `http://localhost/bella_vista_oasis_spa/admin_login.php`.
10. Demo login:
    - Username: `admin`
    - Password: `BellaSpa123!`

## Demo business rules to confirm with your instructor/client
- Currency: Philippine peso (PHP).
- Services and prices are sample values and can be changed in the admin dashboard.
- The spa is closed on Mondays.
- Customers must book at least one day ahead.
- Available time slots: 9:00 AM, 10:30 AM, 12:00 PM, 1:30 PM, 3:00 PM, and 4:30 PM.
- Maximum of 4 guests per exact time slot across all bookings.
- First guest pays the listed service price; each additional guest is charged 80% of the base service price.
- Booking status starts as Pending.
- Booking guidelines are demo text and should be replaced with approved cancellation/grace-period/damage rules if required.

## Database tables
- `admins`: administrator login accounts.
- `services`: treatment name, description, duration, price, and active flag.
- `appointments`: customer details, service, date/time, guest count, estimated total, booking reference, and status.

## Troubleshooting
- **Database connection failed:** confirm MySQL is running, import `database.sql`, and check database credentials in `config.php`.
- **404 Not Found:** ensure the folder is directly inside `C:\xampp\htdocs\` and the URL folder name matches.
- **Blank page/PHP error:** check Apache's error log in XAMPP and confirm you are using a recent PHP version.
- **Google Fonts or photo background do not load:** the site still works; these decorative assets require an internet connection.
- **No appointment options:** check that the sample services exist and are active in the database.

## Notes and limitations
This is a local academic prototype, not a production booking platform. It does not send email/SMS notifications, accept online payments, or provide live calendar synchronization. Slot capacity is a simplified demo rule, not a staff/room-based scheduling engine. For public deployment, change the demo admin password, add CSRF protection and stronger rate limiting, use HTTPS, review privacy requirements, and test concurrent bookings.
